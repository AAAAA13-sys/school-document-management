<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\StudentApplicant;
use App\Models\StudentEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EnrollmentApiController extends Controller
{
    protected array $allowedSources = ['ES', 'ENROLLMENT', 'OAS', 'ADMISSION'];

    protected function authorizeConnector(Request $request): object
    {
        $connector = $request->attributes->get('connector');
        abort_unless($connector, 401, 'Connector authentication required.');
        if (isset($connector->source) && ! in_array($connector->source, array_merge($this->allowedSources, ['ALL', 'DMS', 'ADMIN']))) {
            abort(403, "Connector source '{$connector->source}' is not authorized for Enrollment endpoint. Allowed sources: ".implode(', ', $this->allowedSources));
        }

        return $connector;
    }

    public function index(Request $request): JsonResponse
    {
        $connector = $this->authorizeConnector($request);
        $query = StudentEnrollment::query()
            ->where('school_id', $connector->school_id)
            ->where('campus', $connector->campus);

        if ($request->filled('status')) {
            $query->where('enrollment_status', $request->input('status'));
        }
        if ($request->filled('program')) {
            $query->where('program', 'like', '%'.$request->input('program').'%');
        }
        if ($request->filled('year_level')) {
            $query->where('year_level', (int) $request->input('year_level'));
        }
        if ($request->filled('academic_year')) {
            $query->where('academic_year', $request->input('academic_year'));
        }
        if ($request->filled('semester')) {
            $query->where('semester', $request->input('semester'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('student_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('program', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $paginator = $query->with(['corDocument.latestVersion', 'applicant'])
            ->orderByDesc('id')
            ->paginate($perPage);

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
        $enrollment = StudentEnrollment::where('school_id', $connector->school_id)
            ->where('campus', $connector->campus)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('student_number', $id);
            })
            ->with(['corDocument.latestVersion', 'applicant.applicantDocuments.document', 'assessments'])
            ->first();

        abort_unless($enrollment, 404, 'Enrollee record not found.');

        return response()->json([
            'status' => 'success',
            'data' => $enrollment,
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $connector = $this->authorizeConnector($request);

        // Batch support
        if ($request->has('batch') && is_array($request->input('batch'))) {
            return $this->storeBatch($request, $connector);
        }

        $validator = Validator::make($request->all(), [
            'student_number' => 'required|string|max:64',
            'applicant_id' => 'nullable',
            'first_name' => 'sometimes|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'program' => 'required|string|max:100',
            'year_level' => 'sometimes|integer|min:1|max:10',
            'academic_year' => 'required|string|max:30',
            'semester' => 'required|string|max:30',
            'enrolled_units' => 'sometimes|integer|min:0|max:60',
            'course_allocations' => 'nullable|array',
            'enrollment_status' => 'sometimes|string|in:pending,enrolled,assessed,withdrawn,completed',
            'clearance_status' => 'sometimes|string|in:cleared,pending,flagged',
            'cor_document_id' => 'nullable|uuid',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed for enrollment record.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $result = DB::transaction(function () use ($connector, $validated) {
            $applicant = null;
            $applicantId = null;

            // Transition admitted applicant if applicant_id or applicant_number is supplied
            if (! empty($validated['applicant_id'])) {
                $applicant = StudentApplicant::where('school_id', $connector->school_id)
                    ->where('campus', $connector->campus)
                    ->where(function ($q) use ($validated) {
                        $q->where('id', $validated['applicant_id'])
                            ->orWhere('applicant_number', (string) $validated['applicant_id']);
                    })
                    ->first();

                if ($applicant) {
                    $applicantId = $applicant->id;
                    // Governance: transition status to admitted upon enrollment
                    $applicant->update(['admission_status' => 'admitted']);
                }
            }

            // Verify Certificate of Registration document if provided
            $corDocId = null;
            if (! empty($validated['cor_document_id'])) {
                $doc = Document::where('id', $validated['cor_document_id'])
                    ->where('school_id', $connector->school_id)
                    ->where('campus', $connector->campus)
                    ->first();
                if ($doc) {
                    $corDocId = $doc->id;
                }
            }

            $firstName = $validated['first_name'] ?? ($applicant ? $applicant->first_name : 'Unknown');
            $middleName = $validated['middle_name'] ?? ($applicant ? $applicant->middle_name : null);
            $lastName = $validated['last_name'] ?? ($applicant ? $applicant->last_name : 'Student');

            $existing = StudentEnrollment::where('school_id', $connector->school_id)
                ->where('campus', $connector->campus)
                ->where('student_number', $validated['student_number'])
                ->first();

            $data = [
                'applicant_id' => $applicantId,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'program' => $validated['program'],
                'year_level' => $validated['year_level'] ?? 1,
                'academic_year' => $validated['academic_year'],
                'semester' => $validated['semester'],
                'enrolled_units' => $validated['enrolled_units'] ?? 0,
                'course_allocations' => $validated['course_allocations'] ?? [],
                'enrollment_status' => $validated['enrollment_status'] ?? 'enrolled',
                'clearance_status' => $validated['clearance_status'] ?? 'cleared',
                'cor_document_id' => $corDocId,
                'metadata' => $validated['metadata'] ?? [],
            ];

            if ($existing) {
                $existing->update($data);
                $enrollment = $existing;
                $created = false;
            } else {
                $enrollment = StudentEnrollment::create(array_merge($data, [
                    'student_number' => $validated['student_number'],
                    'school_id' => $connector->school_id,
                    'campus' => $connector->campus,
                ]));
                $created = true;
            }

            $enrollment->load(['corDocument.latestVersion', 'applicant']);

            return [$enrollment, $created];
        });

        [$enrollment, $created] = $result;

        return response()->json([
            'status' => 'success',
            'message' => $created ? 'Student enrollment registered successfully.' : 'Student enrollment record updated.',
            'created' => $created,
            'data' => $enrollment,
        ], $created ? 201 : 200);
    }

    protected function storeBatch(Request $request, object $connector): JsonResponse
    {
        $batch = $request->input('batch');
        if (! is_array($batch) || empty($batch)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Batch array must contain at least one enrollment record.',
            ], 422);
        }

        $processed = [];
        $errors = [];

        DB::transaction(function () use ($batch, $connector, &$processed, &$errors) {
            foreach ($batch as $index => $item) {
                $validator = Validator::make($item, [
                    'student_number' => 'required|string|max:64',
                    'program' => 'required|string|max:100',
                    'academic_year' => 'required|string|max:30',
                    'semester' => 'required|string|max:30',
                ]);

                if ($validator->fails()) {
                    $errors[] = ['index' => $index, 'errors' => $validator->errors()];
                    continue;
                }

                $validated = $validator->validated();
                $existing = StudentEnrollment::where('school_id', $connector->school_id)
                    ->where('campus', $connector->campus)
                    ->where('student_number', $validated['student_number'])
                    ->first();

                $data = [
                    'first_name' => $item['first_name'] ?? 'Enrollee',
                    'middle_name' => $item['middle_name'] ?? null,
                    'last_name' => $item['last_name'] ?? 'Student',
                    'program' => $validated['program'],
                    'year_level' => $item['year_level'] ?? 1,
                    'academic_year' => $validated['academic_year'],
                    'semester' => $validated['semester'],
                    'enrolled_units' => $item['enrolled_units'] ?? 0,
                    'course_allocations' => $item['course_allocations'] ?? [],
                    'enrollment_status' => $item['enrollment_status'] ?? 'enrolled',
                    'clearance_status' => $item['clearance_status'] ?? 'cleared',
                    'cor_document_id' => $item['cor_document_id'] ?? null,
                    'metadata' => $item['metadata'] ?? [],
                ];

                if ($existing) {
                    $existing->update($data);
                } else {
                    StudentEnrollment::create(array_merge($data, [
                        'student_number' => $validated['student_number'],
                        'school_id' => $connector->school_id,
                        'campus' => $connector->campus,
                    ]));
                }

                $processed[] = $validated['student_number'];
            }
        });

        return response()->json([
            'status' => empty($errors) ? 'success' : 'partial_success',
            'message' => 'Processed '.count($processed).' enrollment record(s).',
            'processed_count' => count($processed),
            'processed' => $processed,
            'errors' => $errors,
        ], empty($errors) ? 201 : 207);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $connector = $this->authorizeConnector($request);
        $enrollment = StudentEnrollment::where('school_id', $connector->school_id)
            ->where('campus', $connector->campus)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('student_number', $id);
            })
            ->first();

        abort_unless($enrollment, 404, 'Enrollee record not found.');

        $validator = Validator::make($request->all(), [
            'enrolled_units' => 'sometimes|integer|min:0|max:60',
            'course_allocations' => 'nullable|array',
            'enrollment_status' => 'sometimes|string|in:pending,enrolled,assessed,withdrawn,completed',
            'clearance_status' => 'sometimes|string|in:cleared,pending,flagged',
            'cor_document_id' => 'nullable|uuid',
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

        if (array_key_exists('cor_document_id', $validated) && $validated['cor_document_id']) {
            $doc = Document::where('id', $validated['cor_document_id'])
                ->where('school_id', $connector->school_id)
                ->where('campus', $connector->campus)
                ->first();
            abort_unless($doc, 404, 'Certificate of Registration document not found in scope.');
        }

        $enrollment->update(array_filter([
            'enrolled_units' => $validated['enrolled_units'] ?? null,
            'course_allocations' => $validated['course_allocations'] ?? null,
            'enrollment_status' => $validated['enrollment_status'] ?? null,
            'clearance_status' => $validated['clearance_status'] ?? null,
            'cor_document_id' => array_key_exists('cor_document_id', $validated) ? $validated['cor_document_id'] : null,
            'metadata' => array_merge($enrollment->metadata ?? [], $validated['metadata'] ?? []),
        ], fn ($val) => ! is_null($val)));

        $enrollment->load(['corDocument.latestVersion', 'applicant']);

        return response()->json([
            'status' => 'success',
            'message' => 'Enrollment record updated successfully.',
            'data' => $enrollment,
        ], 200);
    }
}
