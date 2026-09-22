<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\Admin\DepartmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 9.2 (PHASE9_AUDIT_AND_PLAN.md §3.2) — Department has existed
 * since Phase 1 as a foreign key on User/EmployeeProfile, seeded by
 * DepartmentSeeder, but had no CRUD screen until now.
 */
class DepartmentController extends Controller
{
    public function __construct(private readonly DepartmentService $service)
    {
    }

    public function index()
    {
        $departments = Department::withCount('users')->orderBy('name')->get();

        return view('admin.departments.index', compact('departments'));
    }

    public function create()
    {
        return view('admin.departments.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:20|unique:departments,code',
            'description' => 'nullable|string',
        ]);

        $this->service->create($data, $request->user());

        return redirect()->route('admin.departments.index')->with('status', 'Department created.');
    }

    public function edit(Department $department)
    {
        return view('admin.departments.edit', compact('department'));
    }

    public function update(Request $request, Department $department)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:20', Rule::unique('departments', 'code')->ignore($department->id)],
            'description' => 'nullable|string',
        ]);

        $this->service->update($department, $data, $request->user());

        return redirect()->route('admin.departments.index')->with('status', 'Department updated.');
    }

    public function activate(Request $request, Department $department)
    {
        $this->service->setActive($department, true, $request->user());

        return back()->with('status', 'Department reactivated.');
    }

    public function deactivate(Request $request, Department $department)
    {
        $this->service->setActive($department, false, $request->user());

        return back()->with('status', 'Department deactivated.');
    }
}
