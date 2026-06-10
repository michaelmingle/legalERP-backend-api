<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Payroll;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PayrollController extends Controller
{
    private const ROLES_ALLOWED = ['hr', 'admin', 'owner'];

    private function gate(): ?\Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        if (!in_array($user->role, self::ROLES_ALLOWED)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        return null;
    }

    /**
     * GET /payrolls
     *
     * Returns one row per employee for the requested period:
     *  - if a Payroll row exists  → returns it (real status, real net_pay)
     *  - if not                   → returns a synthetic row built from the employee's salary/allowance/deduction
     *                                with status = "not_generated" and id = "emp-{id}"
     *
     * This makes the table reflect the actual employee roster even before "Run Payroll" is clicked.
     */
    public function index(Request $request)
    {
        if ($r = $this->gate()) return $r;
        try {
            $user  = Auth::user();
            $orgId = $user->organization_id;

            $month = (int) ($request->month ?? now()->month);
            $year  = (int) ($request->year  ?? now()->year);

            // 1) Pull all employees for this org (or legacy NULL-org) — these drive the list.
            $employeesQuery = Employee::with('user')
                ->where(function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId)->orWhereNull('organization_id');
                });

            if ($request->filled('search')) {
                $s = $request->search;
                $employeesQuery->where(function ($q) use ($s) {
                    $q->where('full_name', 'like', "%{$s}%")
                      ->orWhere('employee_id', 'like', "%{$s}%")
                      ->orWhereHas('user', function ($u) use ($s) {
                          $u->where('email', 'like', "%{$s}%")
                            ->orWhere('first_name', 'like', "%{$s}%")
                            ->orWhere('last_name', 'like', "%{$s}%");
                      });
                });
            }

            $employees = $employeesQuery->orderBy('full_name')->get();

            // 2) Pull existing Payroll rows for those employees in the requested period.
            $payrolls = Payroll::with(['employee.user', 'processor'])
                ->whereIn('employee_id', $employees->pluck('id'))
                ->where('period_month', $month)
                ->where('period_year',  $year)
                ->get()
                ->keyBy('employee_id');

            // 3) Build merged rows (one per employee).
            $rows = $employees->map(function ($emp) use ($payrolls, $month, $year) {
                $payroll = $payrolls->get($emp->id);
                return $payroll
                    ? $this->transform($payroll)
                    : $this->synthFromEmployee($emp, $month, $year);
            });

            // 4) Apply status filter AFTER merge so "not_generated" is filterable.
            if ($request->filled('status') && $request->status !== 'all') {
                $status = $request->status;
                $rows = $rows->values()->filter(fn ($r) => ($r['status'] ?? '') === $status)->values();
            }

            return response()->json([
                'success' => true,
                'data'    => $rows->values(),
                'total'   => $rows->count(),
                'period'  => ['month' => $month, 'year' => $year],
            ]);
        } catch (\Throwable $e) {
            Log::error('PayrollController@index: '.$e->getMessage());
            return response()->json(['error' => 'Failed to load payrolls', 'detail' => $e->getMessage()], 500);
        }
    }

    /** Build a synthetic payroll row from an Employee record (no DB row yet). */
    private function synthFromEmployee(Employee $emp, int $month, int $year): array
    {
        $u = $emp->user;
        $name = $u
            ? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''))
            : ($emp->full_name ?? 'Unknown');

        $basic     = (float) ($emp->salary    ?? 0);
        $allowance = (float) ($emp->allowance ?? 0);
        $deduction = (float) ($emp->deduction ?? 0);

        return [
            'id'           => 'emp-' . $emp->id,   // sentinel id (not numeric → frontend knows it's synthetic)
            'is_synthetic' => true,
            'payroll_no'   => null,
            'employee_id'  => $emp->id,
            'employee'     => [
                'id'         => $emp->id,
                'employee_id'=> $emp->employee_id,
                'name'       => $name,
                'email'      => $u?->email,
                'role'       => $u?->role ?? $emp->job_title,
                'department' => $emp->department,
                'avatar'     => $u?->photo_url ?? ($emp->photo ? asset('storage/' . $emp->photo) : null),
            ],
            'period_month' => $month,
            'period_year'  => $year,
            'period_label' => sprintf('%02d/%d', $month, $year),
            'basic_salary' => $basic,
            'allowance'    => $allowance,
            'deduction'    => $deduction,
            'tax'          => 0,
            'net_pay'      => round(($basic + $allowance) - $deduction, 2),
            'status'       => 'not_generated',
            'processed_at' => null,
            'notes'        => null,
            'created_at'   => null,
        ];
    }

    /**
     * GET /payrolls/summary
     * Top-line numbers for the dashboard, expressed against the full employee roster
     * so HR sees the gap between expected and actual payslips.
     */
    public function summary(Request $request)
    {
        if ($r = $this->gate()) return $r;
        try {
            $orgId = Auth::user()->organization_id;
            $month = (int) ($request->month ?? now()->month);
            $year  = (int) ($request->year  ?? now()->year);

            // Active employee roster (the universe of payroll candidates)
            $employees = Employee::query()
                ->where(function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId)->orWhereNull('organization_id');
                })
                ->get();

            $totalEmployees     = $employees->count();
            $expectedNetFromEmp = $employees->sum(fn ($e) => max(0.0, ((float) $e->salary) + ((float) $e->allowance) - ((float) $e->deduction)));

            // Actual payslips for this period
            $base = Payroll::query()
                ->where(function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId)->orWhereNull('organization_id');
                })
                ->where('period_month', $month)
                ->where('period_year',  $year);

            $generated = (clone $base)->count();
            $netActual = (float) (clone $base)->sum('net_pay');

            return response()->json([
                'success' => true,
                'data' => [
                    'period_month'    => $month,
                    'period_year'     => $year,
                    'total_employees' => $totalEmployees,
                    'total_payslips'  => $generated,
                    'not_generated'   => max(0, $totalEmployees - $generated),
                    // "Total Net Pay" reflects the full roster cost (synthetic + real)
                    // so HR sees an accurate number even before a Run is triggered.
                    'total_net_pay'   => round($netActual + max(0.0, $expectedNetFromEmp -
                        // subtract the employees who already have a payslip; their actual amount is already in $netActual
                        $employees->whereIn('id', (clone $base)->pluck('employee_id'))
                            ->sum(fn ($e) => max(0.0, ((float) $e->salary) + ((float) $e->allowance) - ((float) $e->deduction)))
                    ), 2),
                    'pending'         => (clone $base)->where('status', 'pending')->count(),
                    'processed'       => (clone $base)->where('status', 'processed')->count(),
                    'on_hold'         => (clone $base)->where('status', 'on_hold')->count(),
                    'paid'            => (clone $base)->where('status', 'paid')->count(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to load summary', 'detail' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /payrolls/generate-for-employee  { employee_id, period_month, period_year }
     * Materializes a single payslip from the employee's defaults for the requested period.
     * Idempotent — if a payslip already exists, returns it.
     */
    public function generateForEmployee(Request $request)
    {
        if ($r = $this->gate()) return $r;
        try {
            $validated = $request->validate([
                'employee_id'  => 'required|exists:employees,id',
                'period_month' => 'nullable|integer|between:1,12',
                'period_year'  => 'nullable|integer|min:2000|max:2100',
                'basic_salary' => 'nullable|numeric|min:0',
                'allowance'    => 'nullable|numeric|min:0',
                'deduction'    => 'nullable|numeric|min:0',
                'tax'          => 'nullable|numeric|min:0',
                'notes'        => 'nullable|string|max:1000',
            ]);

            $month = (int) ($validated['period_month'] ?? now()->month);
            $year  = (int) ($validated['period_year']  ?? now()->year);
            $orgId = Auth::user()->organization_id;

            $emp = Employee::findOrFail($validated['employee_id']);

            $existing = Payroll::where('employee_id', $emp->id)
                ->where('period_month', $month)
                ->where('period_year',  $year)
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payslip already exists for this period',
                    'data'    => $this->transform($existing->load('employee.user')),
                ]);
            }

            // Overrides win over employee defaults.
            $attrs = [
                'organization_id' => $orgId,
                'employee_id'     => $emp->id,
                'payroll_no'      => $this->generatePayrollNo($year, $month),
                'period_month'    => $month,
                'period_year'     => $year,
                'basic_salary'    => $validated['basic_salary'] ?? (float) $emp->salary,
                'allowance'       => $validated['allowance']    ?? (float) $emp->allowance,
                'deduction'       => $validated['deduction']    ?? (float) $emp->deduction,
                'tax'             => $validated['tax']          ?? 0,
                'notes'           => $validated['notes']        ?? null,
                'status'          => 'pending',
            ];
            $attrs['net_pay'] = Payroll::calculateNet($attrs);
            $payroll = Payroll::create($attrs);

            return response()->json([
                'success' => true,
                'message' => 'Payslip generated',
                'data'    => $this->transform($payroll->load('employee.user')),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('PayrollController@generateForEmployee: '.$e->getMessage());
            return response()->json(['error' => 'Failed to generate payslip', 'detail' => $e->getMessage()], 500);
        }
    }

    /** POST /payrolls — manually create a single payslip. */
    public function store(Request $request)
    {
        if ($r = $this->gate()) return $r;
        try {
            $validated = $request->validate([
                'employee_id'  => 'required|exists:employees,id',
                'period_month' => 'required|integer|between:1,12',
                'period_year'  => 'required|integer|min:2000|max:2100',
                'basic_salary' => 'nullable|numeric|min:0',
                'allowance'    => 'nullable|numeric|min:0',
                'deduction'    => 'nullable|numeric|min:0',
                'tax'          => 'nullable|numeric|min:0',
                'notes'        => 'nullable|string|max:1000',
            ]);

            $orgId    = Auth::user()->organization_id;
            $employee = Employee::findOrFail($validated['employee_id']);

            // Defaults pulled from the employee record
            $validated['basic_salary'] = $validated['basic_salary'] ?? (float) $employee->salary;
            $validated['allowance']    = $validated['allowance']    ?? (float) $employee->allowance;
            $validated['deduction']    = $validated['deduction']    ?? (float) $employee->deduction;
            $validated['tax']          = $validated['tax']          ?? 0;
            $validated['net_pay']      = Payroll::calculateNet($validated);
            $validated['organization_id'] = $orgId;
            $validated['payroll_no']      = $this->generatePayrollNo($validated['period_year'], $validated['period_month']);
            $validated['status']          = 'pending';

            $payroll = Payroll::create($validated);

            return response()->json([
                'success' => true,
                'data'    => $this->transform($payroll->load('employee.user')),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('PayrollController@store: '.$e->getMessage());
            return response()->json(['error' => 'Failed to create payroll', 'detail' => $e->getMessage()], 500);
        }
    }

    /** GET /payrolls/{id} */
    public function show($id)
    {
        if ($r = $this->gate()) return $r;
        $payroll = Payroll::with('employee.user', 'processor')->findOrFail($id);
        if (!$this->payrollBelongsToOrg($payroll)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        return response()->json(['success' => true, 'data' => $this->transform($payroll)]);
    }

    /** PUT /payrolls/{id} — edit numeric values or update status (process / hold / paid). */
    public function update(Request $request, $id)
    {
        if ($r = $this->gate()) return $r;
        try {
            $payroll = Payroll::findOrFail($id);
            if (!$this->payrollBelongsToOrg($payroll)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $validated = $request->validate([
                'basic_salary' => 'sometimes|numeric|min:0',
                'allowance'    => 'sometimes|numeric|min:0',
                'deduction'    => 'sometimes|numeric|min:0',
                'tax'          => 'sometimes|numeric|min:0',
                'status'       => 'sometimes|in:pending,on_hold,processed,paid',
                'notes'        => 'sometimes|nullable|string|max:1000',
            ]);

            $payroll->fill($validated);

            // Recompute net pay when any numeric field changed.
            if (array_intersect_key($validated, array_flip(['basic_salary','allowance','deduction','tax']))) {
                $payroll->net_pay = Payroll::calculateNet($payroll->only(['basic_salary','allowance','deduction','tax']));
            }

            // Stamp processor + processed_at when moving into processed/paid
            if (!empty($validated['status']) && in_array($validated['status'], ['processed', 'paid'])) {
                $payroll->processed_at = now();
                $payroll->processed_by = Auth::id();
            }

            $payroll->save();

            return response()->json([
                'success' => true,
                'data'    => $this->transform($payroll->fresh()->load('employee.user', 'processor')),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('PayrollController@update: '.$e->getMessage());
            return response()->json(['error' => 'Failed to update payroll', 'detail' => $e->getMessage()], 500);
        }
    }

    /** DELETE /payrolls/{id} */
    public function destroy($id)
    {
        if ($r = $this->gate()) return $r;
        $payroll = Payroll::findOrFail($id);
        if (!$this->payrollBelongsToOrg($payroll)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        $payroll->delete();
        return response()->json(['success' => true, 'message' => 'Payroll deleted']);
    }

    /**
     * POST /payrolls/run — generate payslips for every active employee
     * for the requested (or current) period. Idempotent: existing payslips for
     * that employee+period are reused, not duplicated.
     */
    public function run(Request $request)
    {
        if ($r = $this->gate()) return $r;
        try {
            $validated = $request->validate([
                'period_month' => 'nullable|integer|between:1,12',
                'period_year'  => 'nullable|integer|min:2000|max:2100',
                'employee_ids' => 'nullable|array',
                'employee_ids.*' => 'integer|exists:employees,id',
            ]);

            $orgId = Auth::user()->organization_id;
            $month = (int) ($validated['period_month'] ?? now()->month);
            $year  = (int) ($validated['period_year']  ?? now()->year);

            $employeesQuery = Employee::query()
                ->where(function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId)->orWhereNull('organization_id');
                });

            if (!empty($validated['employee_ids'])) {
                $employeesQuery->whereIn('id', $validated['employee_ids']);
            } else {
                // Skip employees marked inactive when running for everyone.
                $employeesQuery->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'inactive');
                });
            }

            $employees = $employeesQuery->get();
            $created = 0;
            $skipped = 0;

            DB::transaction(function () use ($employees, $month, $year, $orgId, &$created, &$skipped) {
                foreach ($employees as $emp) {
                    $exists = Payroll::where('employee_id', $emp->id)
                        ->where('period_month', $month)
                        ->where('period_year', $year)
                        ->exists();
                    if ($exists) { $skipped++; continue; }

                    $attrs = [
                        'organization_id' => $orgId,
                        'employee_id'     => $emp->id,
                        'payroll_no'      => $this->generatePayrollNo($year, $month),
                        'period_month'    => $month,
                        'period_year'     => $year,
                        'basic_salary'    => (float) $emp->salary,
                        'allowance'       => (float) $emp->allowance,
                        'deduction'       => (float) $emp->deduction,
                        'tax'             => 0,
                        'status'          => 'pending',
                    ];
                    $attrs['net_pay'] = Payroll::calculateNet($attrs);
                    Payroll::create($attrs);
                    $created++;
                }
            });

            return response()->json([
                'success' => true,
                'message' => "Payroll run for {$month}/{$year}: created {$created}, skipped {$skipped} (already existed).",
                'data'    => [
                    'period_month' => $month,
                    'period_year'  => $year,
                    'created'      => $created,
                    'skipped'      => $skipped,
                    'total_employees' => $employees->count(),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('PayrollController@run: '.$e->getMessage());
            return response()->json(['error' => 'Failed to run payroll', 'detail' => $e->getMessage()], 500);
        }
    }

    /** GET /payrolls/export?month=&year=&status= — CSV download. */
    public function export(Request $request)
    {
        if ($r = $this->gate()) return $r;
        $orgId = Auth::user()->organization_id;

        $query = Payroll::with('employee.user')
            ->where(function ($q) use ($orgId) {
                $q->where('organization_id', $orgId)->orWhereNull('organization_id');
            });
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('month')) $query->where('period_month', (int) $request->month);
        if ($request->filled('year'))  $query->where('period_year',  (int) $request->year);

        $rows = $query->latest('period_year')->latest('period_month')->get();

        $headers = ['Payroll #', 'Employee', 'Employee ID', 'Email', 'Period', 'Basic', 'Allowance', 'Deduction', 'Tax', 'Net Pay', 'Status', 'Processed At'];
        $filename = 'payroll-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows, $headers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $p) {
                $u = $p->employee?->user;
                $name = $u
                    ? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''))
                    : ($p->employee?->full_name ?? 'Unknown');
                fputcsv($out, [
                    $p->payroll_no,
                    $name,
                    $p->employee?->employee_id,
                    $u?->email,
                    str_pad((string) $p->period_month, 2, '0', STR_PAD_LEFT) . '/' . $p->period_year,
                    number_format((float) $p->basic_salary, 2, '.', ''),
                    number_format((float) $p->allowance,    2, '.', ''),
                    number_format((float) $p->deduction,    2, '.', ''),
                    number_format((float) $p->tax,          2, '.', ''),
                    number_format((float) $p->net_pay,      2, '.', ''),
                    $p->status,
                    optional($p->processed_at)->toDateTimeString(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function payrollBelongsToOrg(Payroll $p): bool
    {
        $orgId = Auth::user()?->organization_id;
        if (!$orgId) return true;
        return $p->organization_id === null || (int) $p->organization_id === (int) $orgId;
    }

    private function generatePayrollNo(int $year, int $month): string
    {
        return sprintf('PR-%d%02d-%s', $year, $month, strtoupper(Str::random(6)));
    }

    private function transform(Payroll $p): array
    {
        $u   = $p->employee?->user;
        $emp = $p->employee;
        $name = $u
            ? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''))
            : ($emp?->full_name ?? 'Unknown');

        return [
            'id'           => $p->id,
            'payroll_no'   => $p->payroll_no,
            'employee_id'  => $p->employee_id,
            'employee'     => [
                'id'         => $emp?->id,
                'employee_id'=> $emp?->employee_id,
                'name'       => $name,
                'email'      => $u?->email,
                'role'       => $u?->role ?? $emp?->job_title,
                'department' => $emp?->department,
                'avatar'     => $u?->photo_url ?? ($emp?->photo ? asset('storage/'.$emp->photo) : null),
            ],
            'period_month' => $p->period_month,
            'period_year'  => $p->period_year,
            'period_label' => sprintf('%02d/%d', $p->period_month, $p->period_year),
            'basic_salary' => (float) $p->basic_salary,
            'allowance'    => (float) $p->allowance,
            'deduction'    => (float) $p->deduction,
            'tax'          => (float) $p->tax,
            'net_pay'      => (float) $p->net_pay,
            'status'       => $p->status,
            'processed_at' => optional($p->processed_at)->toDateTimeString(),
            'notes'        => $p->notes,
            'created_at'   => optional($p->created_at)->toDateTimeString(),
        ];
    }
}
