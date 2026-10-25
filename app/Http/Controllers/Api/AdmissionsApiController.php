<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplicantDocument;
use App\Models\Document;
use App\Models\StudentApplicant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdmissionsApiController extends Controller
{
    protected array $allowedSources = ['OAS', 'ADMISSION', 'ADMISSIONS', 'ES'];

    protected function authorizeConnector(Request $request): object
    {
        $connector = $request->attributes->get('connector');
        abort_unless($connector, 401, 'Connector authentication required.');
        if (isset($connector->source) && ! in_array($connector->source, array_merge($this->allowedSources, ['ALL', 'DMS', 'ADMIN']))) {
            abort(403, "Connector source '{$connector->source}' is not authorized for Admissions endpoint. Allowed sources: ".implode(', ', $this->allowedSources));
        }

        return $connector;
    }

    public function index(Request $request): JsonResponse
    {
        $connector = $this->authorizeConnector($request);
        $query = StudentApplicant::query()
            ->where('school_id', $connector->school_id)
            ->where('campus', $connector->campus);

        if ($request->filled('status')) {
            $query->where('admission_status', $request->input('status'));
        }
        if ($request->filled('program')) {
            $query->where('program_applied', 'like', '%'.$request->input('program').'%');
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('applicant_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('with_documents', true)) {
            $query->with(['applicantDocuments.document.latestVersion']);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 200);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $connector = $this->authorizeConnector($request);
        $applicant = StudentApplicant::where('school_id', $connector->school_id)
            ->where('campus', $connector->campus)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('applicant_number', $id);
            })
            ->with(['applicantDocuments.document.latestVersion'])
            ->first();

        abort_unless($applicant, 404, 'Applicant not found.');

        return response()->json([
            'status' => 'success',
            'data' => $applicant,
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $connector = $this->authorizeConnector($request);

        // Check for batch payload
        if ($request->has('batch') && is_array($request->input('batch'))) {
            return $this->storeBatch($request, $connector);
        }

        $validator = Validator::make($request->all(), [
            'applicant_number' => 'required|string|max:64',
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'required|string|max:100',
            'suffix' => 'nullable|string|max:20',
            'email' => 'required|email|max:150',
            'phone' => 'nullable|string|max:50',
            'program_applied' => 'required|string|max:100',
            'admission_status' => 'sometimes|string|in:pending,under_review,admitted,rejected,waitlisted',
            'applied_at' => 'nullable|date',
            'requirements' => 'nullable|array',
            'requirements.*.type' => 'required_with:requirements|string',
            'requirements.*.document_id' => 'nullable|uuid',
            'requirements.*.status' => 'nullable|string|in:pending,verified,rejected',
            'requirements.*.notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed for applicant record.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $result = DB::transaction(function () use ($connector, $validated) {
            $existing = StudentApplicant::where('school_id', $connector->school_id)
                ->where('campus', $connector->campus)
                ->where('applicant_number', $validated['applicant_number'])
                ->first();

            $data = [
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'],
                'suffix' => $validated['suffix'] ?? null,
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'program_applied' => $validated['program_applied'],
                'admission_status' => $validated['admission_status'] ?? 'pending',
                'applied_at' => $validated['applied_at'] ?? now(),
                'requirements' => $validated['requirements'] ?? [],
                'metadata' => $validated['metadata'] ?? [],
            ];

            if ($existing) {
                $existing->update($data);
                $applicant = $existing;
                $created = false;
            } else {
                $applicant = StudentApplicant::create(array_merge($data, [
                    'applicant_number' => $validated['applicant_number'],
                    'school_id' => $connector->school_id,
                    'campus' => $connector->campus,
                ]));
                $created = true;
            }

            // Sync requirement document links if document_id provided
            if (! empty($validated['requirements'])) {
                $this->syncRequirementDocuments($applicant, $validated['requirements'], $connector);
            }

            $applicant->load(['applicantDocuments.document.latestVersion']);

            return [$applicant, $created];
        });

        [$applicant, $created] = $result;

        return response()->json([
            'status' => 'success',
            'message' => $created ? 'Student applicant successfully registered.' : 'Student applicant record updated.',
            'created' => $created,
            'data' => $applicant,
        ], $created ? 201 : 200);
    }

    protected function storeBatch(Request $request, object $connector): JsonResponse
    {
        $batch = $request->input('batch');
        if (! is_array($batch) || empty($batch)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Batch array must contain at least one applicant record.',
            ], 422);
        }

        $processed = [];
        $errors = [];

        DB::transaction(function () use ($batch, $connector, &$processed, &$errors) {
            foreach ($batch as $index => $item) {
                $validator = Validator::make($item, [
                    'applicant_number' => 'required|string|max:64',
                    'first_name' => 'required|string|max:100',
                    'middle_name' => 'nullable|string|max:100',
                    'last_name' => 'required|string|max:100',
                    'email' => 'required|email|max:150',
                    'program_applied' => 'required|string|max:100',
                    'admission_status' => 'sometimes|string',
                ]);

                if ($validator->fails()) {
                    $errors[] = ['index' => $index, 'errors' => $validator->errors()];
                    continue;
                }

                $validated = $validator->validated();
                $existing = StudentApplicant::where('school_id', $connector->school_id)
                    ->where('campus', $connector->campus)
                    ->where('applicant_number', $validated['applicant_number'])
                    ->first();

                $data = [
                    'first_name' => $validated['first_name'],
                    'middle_name' => $item['middle_name'] ?? null,
                    'last_name' => $validated['last_name'],
                    'suffix' => $item['suffix'] ?? null,
                    'email' => $validated['email'],
                    'phone' => $item['phone'] ?? null,
                    'program_applied' => $validated['program_applied'],
                    'admission_status' => $item['admission_status'] ?? 'pending',
                    'applied_at' => $item['applied_at'] ?? now(),
                    'requirements' => $item['requirements'] ?? [],
                    'metadata' => $item['metadata'] ?? [],
                ];

                if ($existing) {
                    $existing->update($data);
                    $applicant = $existing;
                } else {
                    $applicant = StudentApplicant::create(array_merge($data, [
                        'applicant_number' => $validated['applicant_number'],
                        'school_id' => $connector->school_id,
                        'campus' => $connector->campus,
                    ]));
                }

                if (! empty($item['requirements'])) {
                    $this->syncRequirementDocuments($applicant, $item['requirements'], $connector);
                }

                $processed[] = $applicant->applicant_number;
            }
        });

        return response()->json([
            'status' => empty($errors) ? 'success' : 'partial_success',
            'message' => 'Processed '.count($processed).' applicant record(s).',
            'processed_count' => count($processed),
            'processed' => $processed,
            'errors' => $errors,
        ], empty($errors) ? 201 : 207);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $connector = $this->authorizeConnector($request);
        $applicant = StudentApplicant::where('school_id', $connector->school_id)
            ->where('campus', $connector->campus)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('applicant_number', $id);
            })
            ->first();

        abort_unless($applicant, 404, 'Applicant not found.');

        $validator = Validator::make($request->all(), [
            'admission_status' => 'sometimes|string|in:pending,under_review,admitted,rejected,waitlisted',
            'program_applied' => 'sometimes|string|max:100',
            'phone' => 'nullable|string|max:50',
            'requirements' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        if (isset($validated['requirements'])) {
            $this->syncRequirementDocuments($applicant, $validated['requirements'], $connector);
        }

        $applicant->update(array_filter([
            'admission_status' => $validated['admission_status'] ?? null,
            'program_applied' => $validated['program_applied'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'metadata' => array_merge($applicant->metadata ?? [], $validated['metadata'] ?? []),
        ], fn ($val) => ! is_null($val)));

        $applicant->load(['applicantDocuments.document.latestVersion']);

        return response()->json([
            'status' => 'success',
            'message' => 'Applicant record updated successfully.',
            'data' => $applicant,
        ], 200);
    }

    protected function syncRequirementDocuments(StudentApplicant $applicant, array $requirements, object $connector): void
    {
        foreach ($requirements as $req) {
            if (empty($req['type']) || empty($req['document_id'])) {
                continue;
            }

            // Verify document belongs to this tenant scope
            $doc = Document::where('id', $req['document_id'])
                ->where('school_id', $connector->school_id)
                ->where('campus', $connector->campus)
                ->first();

            if (! $doc) {
                continue;
            }

            ApplicantDocument::updateOrCreate(
                [
                    'applicant_id' => $applicant->id,
                    'document_type' => $req['type'],
                ],
                [
                    'document_id' => $doc->id,
                    'verification_status' => $req['status'] ?? 'verified',
                    'notes' => $req['notes'] ?? null,
                    'verified_at' => ($req['status'] ?? 'verified') === 'verified' ? now() : null,
                    'verified_by' => $connector->name ?? 'Connector:'.$connector->source,
                ]
            );
        }
    }
}
