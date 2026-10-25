<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\PayrollRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PayrollApiController extends Controller
{
    protected array $allowedSources = ['PMS', 'PAYROLL', 'EMS'];

    protected function authorizeConnector(Request $request): object
    {
        $connector = $request->attributes->get('connector');
        abort_unless($connector, 401, 'Connector authentication required.');
        if (isset($connector->source) && ! in_array($connector->source, array_merge($this->allowedSources, ['ALL', 'DMS', 'ADMIN']))) {
            abort(403, "Connector source '{$connector->source}' is not authorized for Payroll endpoint. Allowed sources: ".implode(', ', $this->allowedSources));
        }

        return $connector;
    }

    public function index(Request $request): JsonResponse
    {
        $connector = $this->authorizeConnector($request);
        $query = PayrollRecord::query()
            ->where('school_id', $connector->school_id)
            ->where('campus', $connector->campus);

        if ($request->filled('period')) {
            $query->where('payroll_period', $request->input('period'));
        }
        if ($request->filled('department')) {
            $query->where('department', 'like', '%'.$request->input('department').'%');
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->input('employee_id'));
        }
        if ($request->filled('status')) {
            $query->where('disbursement_status', $request->input('status'));
        }
        if ($request->filled('employment_type')) {
            $query->where('employment_type', $request->input('employment_type'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('employee_id', 'like', "%{$search}%")
                    ->orWhere('employee_name', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $paginator = $query->with(['payslipDocument.latestVersion'])
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
        $record = PayrollRecord::where('school_id', $connector->school_id)
            ->where('campus', $connector->campus)
            ->where(function ($q) use ($id, $request) {
                $q->where('id', $id)
                    ->orWhere(function ($sub) use ($id, $request) {
                        $sub->where('employee_id', $id);
                        if ($request->filled('period')) {
                            $sub->where('payroll_period', $request->input('period'));
                        }
                    });
            })
            ->with(['payslipDocument.latestVersion'])
            ->first();

        abort_unless($record, 404, 'Payroll record not found.');

        return response()->json([
            'status' => 'success',
            'data' => $record,
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
            'payroll_period' => 'required|string|max:64',
            'employee_id' => 'required|string|max:64',
            'employee_name' => 'required|string|max:150',
            'department' => 'required|string|max:100',
            'employment_type' => 'sometimes|string|in:faculty,staff,contractual,part_time',
            'gross_pay' => 'required|numeric|min:0',
            'deductions' => 'sometimes|numeric|min:0',
            'net_pay' => 'sometimes|numeric',
            'deduction_breakdown' => 'nullable|array',
            'disbursement_status' => 'sometimes|string|in:draft,processed,disbursed,flagged',
            'payslip_document_id' => 'nullable|uuid',
            'disbursed_at' => 'nullable|date',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed for payroll record.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $result = DB::transaction(function () use ($connector, $validated) {
            $grossPay = (float) $validated['gross_pay'];
            $deductions = (float) ($validated['deductions'] ?? 0.00);
            $netPay = isset($validated['net_pay']) ? (float) $validated['net_pay'] : round($grossPay - $deductions, 2);

            // Verify payslip document if passed
            $payslipDocId = null;
            if (! empty($validated['payslip_document_id'])) {
                $doc = Document::where('id', $validated['payslip_document_id'])
                    ->where('school_id', $connector->school_id)
                    ->where('campus', $connector->campus)
                    ->first();
                if ($doc) {
                    $payslipDocId = $doc->id;
                }
            }

            $existing = PayrollRecord::where('school_id', $connector->school_id)
                ->where('campus', $connector->campus)
                ->where('payroll_period', $validated['payroll_period'])
                ->where('employee_id', $validated['employee_id'])
                ->first();

            $status = $validated['disbursement_status'] ?? 'processed';

            $data = [
                'employee_name' => $validated['employee_name'],
                'department' => $validated['department'],
                'employment_type' => $validated['employment_type'] ?? 'faculty',
                'gross_pay' => $grossPay,
                'deductions' => $deductions,
                'net_pay' => $netPay,
                'deduction_breakdown' => $validated['deduction_breakdown'] ?? [],
                'disbursement_status' => $status,
                'payslip_document_id' => $payslipDocId,
                'disbursed_at' => $status === 'disbursed' ? ($validated['disbursed_at'] ?? now()) : null,
                'metadata' => $validated['metadata'] ?? [],
            ];

            if ($existing) {
                $existing->update($data);
                $record = $existing;
                $created = false;
            } else {
                $record = PayrollRecord::create(array_merge($data, [
                    'payroll_period' => $validated['payroll_period'],
                    'employee_id' => $validated['employee_id'],
                    'school_id' => $connector->school_id,
                    'campus' => $connector->campus,
                ]));
                $created = true;
            }

            $record->load(['payslipDocument.latestVersion']);

            return [$record, $created];
        });

        [$record, $created] = $result;

        return response()->json([
            'status' => 'success',
            'message' => $created ? 'Payroll record indexed successfully.' : 'Payroll record updated.',
            'created' => $created,
            'data' => $record,
        ], $created ? 201 : 200);
    }

    protected function storeBatch(Request $request, object $connector): JsonResponse
    {
        $batch = $request->input('batch');
        if (! is_array($batch) || empty($batch)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Batch array must contain at least one payroll record.',
            ], 422);
        }

        $processed = [];
        $errors = [];

        DB::transaction(function () use ($batch, $connector, &$processed, &$errors) {
            foreach ($batch as $index => $item) {
                $validator = Validator::make($item, [
                    'payroll_period' => 'required|string|max:64',
                    'employee_id' => 'required|string|max:64',
                    'employee_name' => 'required|string|max:150',
                    'department' => 'required|string|max:100',
                    'gross_pay' => 'required|numeric|min:0',
                ]);

                if ($validator->fails()) {
                    $errors[] = ['index' => $index, 'errors' => $validator->errors()];
                    continue;
                }

                $validated = $validator->validated();
                $grossPay = (float) $validated['gross_pay'];
                $deductions = (float) ($item['deductions'] ?? 0.00);
                $netPay = isset($item['net_pay']) ? (float) $item['net_pay'] : round($grossPay - $deductions, 2);

                $existing = PayrollRecord::where('school_id', $connector->school_id)
                    ->where('campus', $connector->campus)
                    ->where('payroll_period', $validated['payroll_period'])
                    ->where('employee_id', $validated['employee_id'])
                    ->first();

                $status = $item['disbursement_status'] ?? 'processed';

                $data = [
                    'employee_name' => $validated['employee_name'],
                    'department' => $validated['department'],
                    'employment_type' => $item['employment_type'] ?? 'faculty',
                    'gross_pay' => $grossPay,
                    'deductions' => $deductions,
                    'net_pay' => $netPay,
                    'deduction_breakdown' => $item['deduction_breakdown'] ?? [],
                    'disbursement_status' => $status,
                    'payslip_document_id' => $item['payslip_document_id'] ?? null,
                    'disbursed_at' => $status === 'disbursed' ? ($item['disbursed_at'] ?? now()) : null,
                    'metadata' => $item['metadata'] ?? [],
                ];

                if ($existing) {
                    $existing->update($data);
                } else {
                    PayrollRecord::create(array_merge($data, [
                        'payroll_period' => $validated['payroll_period'],
                        'employee_id' => $validated['employee_id'],
                        'school_id' => $connector->school_id,
                        'campus' => $connector->campus,
                    ]));
                }

                $processed[] = $validated['employee_id'];
            }
        });

        return response()->json([
            'status' => empty($errors) ? 'success' : 'partial_success',
            'message' => 'Processed '.count($processed).' payroll record(s).',
            'processed_count' => count($processed),
            'processed' => $processed,
            'errors' => $errors,
        ], empty($errors) ? 201 : 207);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $connector = $this->authorizeConnector($request);
        $record = PayrollRecord::where('school_id', $connector->school_id)
            ->where('campus', $connector->campus)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('employee_id', $id);
            })
            ->first();

        abort_unless($record, 404, 'Payroll record not found.');

        $validator = Validator::make($request->all(), [
            'disbursement_status' => 'sometimes|string|in:draft,processed,disbursed,flagged',
            'payslip_document_id' => 'nullable|uuid',
            'disbursed_at' => 'nullable|date',
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

        if (array_key_exists('payslip_document_id', $validated) && $validated['payslip_document_id']) {
            $doc = Document::where('id', $validated['payslip_document_id'])
                ->where('school_id', $connector->school_id)
                ->where('campus', $connector->campus)
                ->first();
            abort_unless($doc, 404, 'Payslip document not found in scope.');
        }

        $status = $validated['disbursement_status'] ?? $record->disbursement_status;
        $disbursedAt = $status === 'disbursed' ? ($validated['disbursed_at'] ?? now()) : $record->disbursed_at;

        $record->update(array_filter([
            'disbursement_status' => $validated['disbursement_status'] ?? null,
            'payslip_document_id' => array_key_exists('payslip_document_id', $validated) ? $validated['payslip_document_id'] : null,
            'disbursed_at' => $disbursedAt,
            'metadata' => array_merge($record->metadata ?? [], $validated['metadata'] ?? []),
        ], fn ($val) => ! is_null($val)));

        $record->load(['payslipDocument.latestVersion']);

        return response()->json([
            'status' => 'success',
            'message' => 'Payroll record updated successfully.',
            'data' => $record,
        ], 200);
    }
}
