<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    /**
     * Display a listing of employees with optional filters.
     */
    public function index(Request $request)
    {
        $query = Employee::with('user');

        // Optional filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('department')) {
            $query->where('department', $request->department);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('employee_id', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->where('email', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                  });
            });
        }

        $employees = $query->get();

        return response()->json($employees);
    }

    /**
     * Store a newly created employee (with optional photo).
     */
    public function store(Request $request)
    {
        // Convert createCredentialsLater to boolean (accepts "true"/"false"/"1"/"0"/true/false)
        if ($request->has('createCredentialsLater')) {
            $request->merge([
                'createCredentialsLater' => filter_var(
                    $request->createCredentialsLater,
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                )
            ]);
        }

        // Validate incoming request
        $validated = $request->validate([
            // Personal Information
            'fullName'              => 'required|string|max:255',
            'dob'                   => 'nullable|date',
            'gender'                => 'nullable|in:Male,Female,Other',
            'contactNumber'         => 'nullable|string|max:20',
            'email'                 => 'nullable|email|max:255',     
            'photo'                 => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'emergencyName'         => 'nullable|string|max:255',
            'emergencyContact'      => 'nullable|string|max:20',
            'relation'              => 'nullable|string|max:100',

            // Employment Information
            'employeeId'            => 'required|string|unique:employees,employee_id',
            'department'            => 'nullable|string|max:255',
            'jobTitle'              => 'nullable|string|max:255',
            'hireDate'              => 'nullable|date',
            'employmentType'        => 'nullable|in:Full-Time,Part-Time,Contract',
            'status'                => 'nullable|in:Active,Inactive,On Leave',

            // Payroll Information
            'salary'                => 'nullable|numeric|min:0',
            'allowance'             => 'nullable|string|max:255',
            'deduction'             => 'nullable|string|max:255',
            'bankName'              => 'nullable|string|max:255',
            'bankAccount'           => 'nullable|string|max:255',

            // System Access
            'username'              => 'required|string|unique:users,username',
            'systemAccessEmail'     => 'required|email|unique:users,email',
            'role'                  => 'required|string|in:employee',
            'createCredentialsLater'=> 'nullable|boolean',
            'password'              => [
                'nullable',
                'string',
                'min:6',
                function ($attribute, $value, $fail) use ($request) {
                    $createLater = $request->input('createCredentialsLater', false);
                    // If not creating credentials later, password is required
                    if (!$createLater && empty($value)) {
                        $fail('The password field is required when not creating credentials later.');
                    }
                },
            ],
        ]);

        // Handle photo upload
        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('employees/photos', 'public');
        }

        DB::beginTransaction();

        try {
            // Create User record for login
            $user = User::create([
                'username' => $validated['username'],
                'email'    => $validated['systemAccessEmail'],
                'password' => ($validated['createCredentialsLater'] ?? false) ? null : Hash::make($validated['password']),
                'role'     => $validated['role'],
            ]);

            // Create Employee record linked to the user
            $employee = Employee::create([
                'user_id'                   => $user->id,
                'full_name'                 => $validated['fullName'],
                'date_of_birth'             => $validated['dob'] ?? null,
                'gender'                    => $validated['gender'] ?? null,
                'contact_number'            => $validated['contactNumber'] ?? null,
                'contact_email'             => $validated['email'] ?? null,
                'photo'                     => $photoPath,
                'emergency_contact_name'    => $validated['emergencyName'] ?? null,
                'emergency_contact_number'  => $validated['emergencyContact'] ?? null,
                'emergency_relation'        => $validated['relation'] ?? null,
                'employee_id'               => $validated['employeeId'],
                'department'                => $validated['department'] ?? null,
                'job_title'                 => $validated['jobTitle'] ?? null,
                'hire_date'                 => $validated['hireDate'] ?? null,
                'employment_type'           => $validated['employmentType'] ?? null,
                'status'                    => $validated['status'] ?? 'Active',
                'salary'                    => $validated['salary'] ?? null,
                'allowance'                 => $validated['allowance'] ?? null,
                'deduction'                 => $validated['deduction'] ?? null,
                'bank_name'                 => $validated['bankName'] ?? null,
                'bank_account_number'       => $validated['bankAccount'] ?? null,
            ]);

            DB::commit();

            // If "Create Credentials Later" was checked, send invitation email
            if ($validated['createCredentialsLater'] ?? false) {
                // TODO: Send password setup email
                // $user->sendSetPasswordNotification();
            }

            return response()->json([
                'message' => 'success',
                'user' => $user,
                'employee' => $employee
            ]);

        } catch (\Exception $e) {
    DB::rollBack();
    if ($photoPath) {
        Storage::disk('public')->delete($photoPath);
    }
    // Return the actual error message
    return response()->json([
        'error' => $e->getMessage()
    ], 500);
}
    }

    /**
     * Display the specified employee.
     */
    public function show(Employee $employee)
    {
        return response()->json($employee->load('user'));
    }

    /**
     * Update the specified employee (including photo and user details).
     */
    public function update(Request $request, Employee $employee)
    {
        $user = $employee->user;

        $data = $request->validate([
            // Personal
            'fullName'              => 'sometimes|string|max:255',
            'dob'                   => 'nullable|date',
            'gender'                => 'nullable|in:Male,Female,Other',
            'contactNumber'         => 'nullable|string|max:20',
            'email'                 => 'nullable|email|max:255',
            'emergencyName'         => 'nullable|string|max:255',
            'emergencyContact'      => 'nullable|string|max:20',
            'relation'              => 'nullable|string|max:100',
            'photo'                 => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',

            // Employment
            // 'employeeId'            => 'nullable|string|unique:employees,employee_id',
            'department'            => 'nullable|string|max:255',
            'jobTitle'              => 'nullable|string|max:255',
            'hireDate'              => 'nullable|date',
            'employmentType'        => 'nullable|in:Full-Time,Part-Time,Contract',
            'status'                => 'nullable|in:Active,Inactive,On Leave',

            // Payroll
            'salary'                => 'nullable|numeric|min:0',
            'allowance'             => 'nullable|string|max:255',
            'deduction'             => 'nullable|string|max:255',
            'bankName'              => 'nullable|string|max:255',
            'bankAccount'           => 'nullable|string|max:255',

            // System Access
            'username'              => ['sometimes', 'string', Rule::unique('users')->ignore($user->id)],
            'systemAccessEmail'     => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'role'                  => 'sometimes|string|in:Admin,HR Manager,employee,Manager',
            'password'              => 'nullable|string|min:6',
        ]);

        DB::beginTransaction();
        try {
            // Update User
            if (isset($data['username'])) {
                $user->username = $data['username'];
            }
            if (isset($data['systemAccessEmail'])) {
                $user->email = $data['systemAccessEmail'];
            }
            if (isset($data['role'])) {
                $user->role = $data['role'];
            }
            if (!empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }
            $user->save();

            // Update Employee fields
            $employee->full_name = $data['fullName'] ?? $employee->full_name;
            $employee->date_of_birth = $data['dob'] ?? $employee->date_of_birth;
            $employee->gender = $data['gender'] ?? $employee->gender;
            $employee->contact_number = $data['contactNumber'] ?? $employee->contact_number;
            $employee->contact_email = $data['email'] ?? $employee->contact_email;
            $employee->emergency_contact_name = $data['emergencyName'] ?? $employee->emergency_contact_name;
            $employee->emergency_contact_number = $data['emergencyContact'] ?? $employee->emergency_contact_number;
            $employee->emergency_relation = $data['relation'] ?? $employee->emergency_relation;
            $employee->employee_id = $data['employeeId'] ?? $employee->employee_id;
            $employee->department = $data['department'] ?? $employee->department;
            $employee->job_title = $data['jobTitle'] ?? $employee->job_title;
            $employee->hire_date = $data['hireDate'] ?? $employee->hire_date;
            $employee->employment_type = $data['employmentType'] ?? $employee->employment_type;
            $employee->status = $data['status'] ?? $employee->status;
            $employee->salary = $data['salary'] ?? $employee->salary;
            $employee->allowance = $data['allowance'] ?? $employee->allowance;
            $employee->deduction = $data['deduction'] ?? $employee->deduction;
            $employee->bank_name = $data['bankName'] ?? $employee->bank_name;
            $employee->bank_account_number = $data['bankAccount'] ?? $employee->bank_account_number;

            // Handle photo upload
            if ($request->hasFile('photo')) {
                // Delete old photo
                if ($employee->photo) {
                    Storage::disk('public')->delete($employee->photo);
                }
                $photoPath = $request->file('photo')->store('employees/photos', 'public');
                $employee->photo = $photoPath;
            }

            $employee->save();

            DB::commit();

            return response()->json([
                'message'  => 'Employee updated successfully',
                'employee' => $employee->fresh()->load('user')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Employee update failed: ' . $e->getMessage());
            return response()->json(['error' => 'Update failed'], 500);
        }
    }

    /**
     * Remove the specified employee (and associated user).
     */
    public function destroy(Employee $employee)
    {
        $user = $employee->user;

        DB::beginTransaction();
        try {
            // Delete photo if exists
            if ($employee->photo) {
                Storage::disk('public')->delete($employee->photo);
            }

            // Delete employee (will cascade to user if foreign key onDelete cascade is set)
            $employee->delete(); // soft delete if you use SoftDeletes
            $user->delete();     // or soft delete if you want

            DB::commit();

            return response()->json(['message' => 'Employee deleted successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Employee deletion failed: ' . $e->getMessage());
            return response()->json(['error' => 'Deletion failed'], 500);
        }
    }
}