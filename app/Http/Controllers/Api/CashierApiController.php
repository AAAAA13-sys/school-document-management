<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashierAssessment;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CashierApiController extends Controller
{
    protected array $allowedSources = ['CASHIER', 'CAS', 'PMS', 'ES'];

    protected function authorizeConnector(Request $request): object
    {
        $connector = $request->attributes->get('connector');
        abort_unless($connector, 401, 'Connector authentication required.');
        if (isset($connector->source) && ! in_array($connector->source, array_merge($this->allowedSources, ['ALL', 'DMS', 'ADMIN']))) {
            abort(403, "Connector source '{$connector->source}' is not authorized for Cashier endpoint. Allowed sources: ".implode(', ', $this->allowedSources));
        }

        return $connector;
    }

    public function index(Request $request): JsonResponse
    {
        $connector = $this->authorizeConnector($request);
        $query = CashierAssessment::query()
            ->where('school_id', $connector->school_id)
            ->where('campus', $connector->campus);

        if ($request->filled('status')) {
            $query->where('payment_status', $request->input('status'));
        }
        if ($request->filled('student_number')) {
            $query->where('student_number', $request->input('student_number'));
        }
        if ($request->filled('account_type')) {
            $query->where('account_type', $request->input('account_type'));
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
                $q->where('assessment_number', 'like', "%{$search}%")
                    ->orWhere('or_number', 'like', "%{$search}%")
                    ->orWhere('student_number', 'like', "%{$search}%")
                    ->orWhere('student_name', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $paginator = $query->with(['receiptDocument.latestVersion'])
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
        $assessment = CashierAssessment::where('school_id', $connector->school_id)
            ->where('campus', $connector->campus)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)
                    ->orWhere('assessment_number', $id)
                    ->orWhere('or_number', $id);
            })
            ->with(['receiptDocument.latestVersion', 'enrollment'])
            ->first();

        abort_unless($assessment, 404, 'Assessment or payment transaction not found.');

        return response()->json([
            'status' => 'success',
            'data' => $assessment,
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
            'assessment_number' => 'required|string|max:64',
            'or_number' => 'nullable|string|max:64',
            'student_number' => 'nullable|string|max:64',
            'applicant_number' => 'nullable|string|max:64',
            'student_name' => 'required|string|max:150',
            'account_type' => 'required|string|max:64',
            'academic_year' => 'nullable|string|max:30',
            'semester' => 'nullable|string|max:30',
            'total_amount' => 'required|numeric|min:0',
            'amount_paid' => 'sometimes|numeric|min:0',
            'payment_method' => 'nullable|string|max:50',
            'payment_status' => 'sometimes|string|in:assessed,pending_verification,verified,refunded,voided',
            'receipt_document_id' => 'nullable|uuid',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed for fee assessment.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $result = DB::transaction(function () use ($connector, $validated) {
            $totalAmount = (float) $validated['total_amount'];
            $amountPaid = (float) ($validated['amount_paid'] ?? 0.00);
            $balance = max(0.00, round($totalAmount - $amountPaid, 2));

            // Verify receipt document if provided
            $receiptDocId = null;
            if (! empty($validated['receipt_document_id'])) {
                $doc = Document::where('id', $validated['receipt_document_id'])
                    ->where('school_id', $connector->school_id)
                    ->where('campus', $connector->campus)
                    ->first();
                if ($doc) {
                    $receiptDocId = $doc->id;
                }
            }

            $existing = CashierAssessment::where('school_id', $connector->school_id)
                ->where('campus', $connector->campus)
                ->where('assessment_number', $validated['assessment_number'])
                ->first();

            $status = $validated['payment_status'] ?? ($amountPaid >= $totalAmount && $totalAmount > 0 ? 'verified' : 'assessed');

            $data = [
                'or_number' => $validated['or_number'] ?? null,
                'student_number' => $validated['student_number'] ?? null,
                'applicant_number' => $validated['applicant_number'] ?? null,
                'student_name' => $validated['student_name'],
                'account_type' => $validated['account_type'],
                'academic_year' => $validated['academic_year'] ?? null,
                'semester' => $validated['semester'] ?? null,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'balance' => $balance,
                'payment_method' => $validated['payment_method'] ?? null,
                'payment_status' => $status,
                'receipt_document_id' => $receiptDocId,
                'verified_at' => $status === 'verified' ? now() : null,
                'verified_by' => $status === 'verified' ? ($connector->name ?? 'Cashier') : null,
                'metadata' => $validated['metadata'] ?? [],
            ];

            if ($existing) {
                $existing->update($data);
                $assessment = $existing;
                $created = false;
            } else {
                $assessment = CashierAssessment::create(array_merge($data, [
                    'assessment_number' => $validated['assessment_number'],
                    'school_id' => $connector->school_id,
                    'campus' => $connector->campus,
                ]));
                $created = true;
            }

            $assessment->load(['receiptDocument.latestVersion']);

            return [$assessment, $created];
        });

        [$assessment, $created] = $result;

        return response()->json([
            'status' => 'success',
            'message' => $created ? 'Fee assessment created successfully.' : 'Fee assessment record updated.',
            'created' => $created,
            'data' => $assessment,
        ], $created ? 201 : 200);
    }

    protected function storeBatch(Request $request, object $connector): JsonResponse
    {
        $batch = $request->input('batch');
        if (! is_array($batch) || empty($batch)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Batch array must contain at least one cashier record.',
            ], 422);
        }

        $processed = [];
        $errors = [];

        DB::transaction(function () use ($batch, $connector, &$processed, &$errors) {
            foreach ($batch as $index => $item) {
                $validator = Validator::make($item, [
                    'assessment_number' => 'required|string|max:64',
                    'student_name' => 'required|string|max:150',
                    'account_type' => 'required|string|max:64',
                    'total_amount' => 'required|numeric|min:0',
                ]);

                if ($validator->fails()) {
                    $errors[] = ['index' => $index, 'errors' => $validator->errors()];
                    continue;
                }

                $validated = $validator->validated();
                $totalAmount = (float) $validated['total_amount'];
                $amountPaid = (float) ($item['amount_paid'] ?? 0.00);
                $balance = max(0.00, round($totalAmount - $amountPaid, 2));

                $existing = CashierAssessment::where('school_id', $connector->school_id)
                    ->where('campus', $connector->campus)
                    ->where('assessment_number', $validated['assessment_number'])
                    ->first();

                $status = $item['payment_status'] ?? ($amountPaid >= $totalAmount && $totalAmount > 0 ? 'verified' : 'assessed');

                $data = [
                    'or_number' => $item['or_number'] ?? null,
                    'student_number' => $item['student_number'] ?? null,
                    'applicant_number' => $item['applicant_number'] ?? null,
                    'student_name' => $validated['student_name'],
                    'account_type' => $validated['account_type'],
                    'academic_year' => $item['academic_year'] ?? null,
                    'semester' => $item['semester'] ?? null,
                    'total_amount' => $totalAmount,
                    'amount_paid' => $amountPaid,
                    'balance' => $balance,
                    'payment_method' => $item['payment_method'] ?? null,
                    'payment_status' => $status,
                    'receipt_document_id' => $item['receipt_document_id'] ?? null,
                    'verified_at' => $status === 'verified' ? now() : null,
                    'verified_by' => $status === 'verified' ? ($connector->name ?? 'Cashier') : null,
                    'metadata' => $item['metadata'] ?? [],
                ];

                if ($existing) {
                    $existing->update($data);
                } else {
                    CashierAssessment::create(array_merge($data, [
                        'assessment_number' => $validated['assessment_number'],
                        'school_id' => $connector->school_id,
                        'campus' => $connector->campus,
                    ]));
                }

                $processed[] = $validated['assessment_number'];
            }
        });

        return response()->json([
            'status' => empty($errors) ? 'success' : 'partial_success',
            'message' => 'Processed '.count($processed).' cashier assessment(s).',
            'processed_count' => count($processed),
            'processed' => $processed,
            'errors' => $errors,
        ], empty($errors) ? 201 : 207);
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        $connector = $this->authorizeConnector($request);
        $assessment = CashierAssessment::where('school_id', $connector->school_id)
            ->where('campus', $connector->campus)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('assessment_number', $id);
            })
            ->first();

        abort_unless($assessment, 404, 'Assessment not found.');

        $validator = Validator::make($request->all(), [
            'or_number' => 'required|string|max:64',
            'amount_paid' => 'required|numeric|min:0.01',
            'payment_method' => 'sometimes|string|max:50',
            'receipt_document_id' => 'nullable|uuid',
            'verified_by' => 'sometimes|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed for payment verification.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $receiptDocId = $assessment->receipt_document_id;
        if (! empty($validated['receipt_document_id'])) {
            $doc = Document::where('id', $validated['receipt_document_id'])
                ->where('school_id', $connector->school_id)
                ->where('campus', $connector->campus)
                ->first();
            abort_unless($doc, 404, 'Receipt document not found in scope.');
            $receiptDocId = $doc->id;
        }

        $newAmountPaid = round((float) $assessment->amount_paid + (float) $validated['amount_paid'], 2);
        $newBalance = max(0.00, round((float) $assessment->total_amount - $newAmountPaid, 2));

        $assessment->update([
            'or_number' => $validated['or_number'],
            'amount_paid' => $newAmountPaid,
            'balance' => $newBalance,
            'payment_method' => $validated['payment_method'] ?? $assessment->payment_method ?? 'cash',
            'payment_status' => 'verified',
            'receipt_document_id' => $receiptDocId,
            'verified_at' => now(),
            'verified_by' => $validated['verified_by'] ?? ($connector->name ?? 'Cashier Teller'),
        ]);

        $assessment->load(['receiptDocument.latestVersion']);

        return response()->json([
            'status' => 'success',
            'message' => 'Payment successfully verified.',
            'data' => $assessment,
        ], 200);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $connector = $this->authorizeConnector($request);
        $assessment = CashierAssessment::where('school_id', $connector->school_id)
            ->where('campus', $connector->campus)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('assessment_number', $id);
            })
            ->first();

        abort_unless($assessment, 404, 'Assessment not found.');

        $validator = Validator::make($request->all(), [
            'payment_status' => 'sometimes|string|in:assessed,pending_verification,verified,refunded,voided',
            'receipt_document_id' => 'nullable|uuid',
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

        if (array_key_exists('receipt_document_id', $validated) && $validated['receipt_document_id']) {
            $doc = Document::where('id', $validated['receipt_document_id'])
                ->where('school_id', $connector->school_id)
                ->where('campus', $connector->campus)
                ->first();
            abort_unless($doc, 404, 'Receipt document not found in scope.');
        }

        $assessment->update(array_filter([
            'payment_status' => $validated['payment_status'] ?? null,
            'receipt_document_id' => array_key_exists('receipt_document_id', $validated) ? $validated['receipt_document_id'] : null,
            'metadata' => array_merge($assessment->metadata ?? [], $validated['metadata'] ?? []),
        ], fn ($val) => ! is_null($val)));

        $assessment->load(['receiptDocument.latestVersion']);

        return response()->json([
            'status' => 'success',
            'message' => 'Assessment updated successfully.',
            'data' => $assessment,
        ], 200);
    }
}
