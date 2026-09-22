<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\Hr\EmployeeService;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $service)
    {
    }

    public function index()
    {
        $profiles = EmployeeProfile::with('user.department')->orderBy('employee_number')->get();
        $activeUsers = User::where('is_active', true)->orderBy('name')->get();

        return view('hr.employees.index', compact('profiles', 'activeUsers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'job_title' => 'required|string|max:255',
            'employment_type' => 'required|in:full_time,part_time,contract',
            'hire_date' => 'required|date',
            'basic_salary' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:cash,bank_transfer,mobile_money',
            'bank_account' => 'nullable|string|max:255',
            'national_id' => 'nullable|string|max:100',
        ]);

        $user = User::findOrFail($data['user_id']);
        $this->service->onboard($user, $data, $request->user());

        return back()->with('status', 'Employee profile saved.');
    }

    public function terminate(Request $request, EmployeeProfile $employeeProfile)
    {
        $this->service->terminate($employeeProfile, $request->user());

        return back()->with('status', 'Employee terminated and account deactivated.');
    }
}
