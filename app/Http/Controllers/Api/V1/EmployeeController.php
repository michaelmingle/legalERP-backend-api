<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    /**
     * Display a listing of employees with optional filters (filtered by organization)
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Only HR, admin, and owner can view all employees
            if (!in_array($user->role, ['hr', 'admin', 'owner'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $query = Employee::where('organization_id', $organizationId)
                ->with('user');

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
        } catch (\Exception $e) {
            Log::error('Error fetching employees: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch employees'], 500);
        }
    }

    /**
     * Store a newly created employee (with optional photo) - filtered by organization
     */
    public function store(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Only HR, admin, and owner can create employees
            if (!in_array($user->role, ['hr', 'admin', 'owner'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            // Convert createCredentialsLater to boolean
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
                $newUser = User::create([
                    'organization_id' => $organizationId,
                    'username' => $validated['username'],
                    'email'    => $validated['systemAccessEmail'],
                    'password' => ($validated['createCredentialsLater'] ?? false) ? null : Hash::make($validated['password']),
                    'role'     => $validated['role'],
                    'status'   => 'active',
                ]);

                // Create Employee record linked to the user
                $employee = Employee::create([
                    'organization_id'           => $organizationId,
                    'user_id'                   => $newUser->id,
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
                    // $newUser->sendSetPasswordNotification();
                }

                return response()->json([
                    'message' => 'Employee created successfully',
                    'user' => $newUser,
                    'employee' => $employee
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                if ($photoPath) {
                    Storage::disk('public')->delete($photoPath);
                }
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Employee creation failed: ' . $e->getMessage());
            return response()->json([
                'error' => 'Employee creation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified employee (filtered by organization)
     */
    public function show(Employee $employee)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Verify employee belongs to organization
            if ($employee->organization_id !== $organizationId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            return response()->json($employee->load('user'));
        } catch (\Exception $e) {
            Log::error('Error fetching employee: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch employee'], 500);
        }
    }

    /**
     * Update the specified employee (including photo and user details) - filtered by organization
     */
    public function update(Request $request, Employee $employee)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Verify employee belongs to organization
            if ($employee->organization_id !== $organizationId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            // Only HR, admin, and owner can update employees
            if (!in_array($user->role, ['hr', 'admin', 'owner'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $employeeUser = $employee->user;

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
                'username'              => ['sometimes', 'string', Rule::unique('users')->ignore($employeeUser->id)],
                'systemAccessEmail'     => ['sometimes', 'email', Rule::unique('users')->ignore($employeeUser->id)],
                'role'                  => 'sometimes|string|in:Admin,HR Manager,employee,Manager',
                'password'              => 'nullable|string|min:6',
            ]);

            DB::beginTransaction();
            try {
                // Update User
                if (isset($data['username'])) {
                    $employeeUser->username = $data['username'];
                }
                if (isset($data['systemAccessEmail'])) {
                    $employeeUser->email = $data['systemAccessEmail'];
                }
                if (isset($data['role'])) {
                    $employeeUser->role = $data['role'];
                }
                if (!empty($data['password'])) {
                    $employeeUser->password = Hash::make($data['password']);
                }
                $employeeUser->save();

                // Update Employee fields
                $employee->full_name = $data['fullName'] ?? $employee->full_name;
                $employee->date_of_birth = $data['dob'] ?? $employee->date_of_birth;
                $employee->gender = $data['gender'] ?? $employee->gender;
                $employee->contact_number = $data['contactNumber'] ?? $employee->contact_number;
                $employee->contact_email = $data['email'] ?? $employee->contact_email;
                $employee->emergency_contact_name = $data['emergencyName'] ?? $employee->emergency_contact_name;
                $employee->emergency_contact_number = $data['emergencyContact'] ?? $employee->emergency_contact_number;
                $employee->emergency_relation = $data['relation'] ?? $employee->emergency_relation;
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
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Employee update failed: ' . $e->getMessage());
            return response()->json(['error' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified employee (and associated user) - filtered by organization
     */
    public function destroy(Employee $employee)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Verify employee belongs to organization
            if ($employee->organization_id !== $organizationId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            // Only HR, admin, and owner can delete employees
            if (!in_array($user->role, ['hr', 'admin', 'owner'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $employeeUser = $employee->user;

            DB::beginTransaction();
            try {
                // Delete photo if exists
                if ($employee->photo) {
                    Storage::disk('public')->delete($employee->photo);
                }

                // Delete employee (soft delete if you use SoftDeletes)
                $employee->delete();
                
                // Delete associated user
                if ($employeeUser) {
                    $employeeUser->delete();
                }

                DB::commit();

                return response()->json(['message' => 'Employee deleted successfully']);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Employee deletion failed: ' . $e->getMessage());
            return response()->json(['error' => 'Deletion failed: ' . $e->getMessage()], 500);
        }
    }
}