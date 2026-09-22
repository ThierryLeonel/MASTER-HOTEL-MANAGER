<?php

namespace App\Services\Admin;

use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Phase 9.2: Department (model + migration + seeder) has existed since
 * Phase 1 as a foreign key on User/EmployeeProfile, but nothing ever
 * exposed CRUD for it — see PHASE9_AUDIT_AND_PLAN.md §3.2.
 */
class DepartmentService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function create(array $data, User $actor): Department
    {
        $department = Department::create([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'description' => $data['description'] ?? null,
            'is_active' => true,
        ]);

        $this->audit->log('admin.department_created', 'admin', $department, newValues: $department->toArray(), actor: $actor);

        return $department;
    }

    public function update(Department $department, array $data, User $actor): Department
    {
        $oldValues = $department->only(['name', 'code', 'description']);

        $department->update([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'description' => $data['description'] ?? null,
        ]);

        $this->audit->log(
            'admin.department_updated',
            'admin',
            $department,
            oldValues: $oldValues,
            newValues: $department->only(['name', 'code', 'description']),
            actor: $actor,
        );

        return $department->fresh();
    }

    public function setActive(Department $department, bool $active, User $actor): Department
    {
        $department->update(['is_active' => $active]);

        $this->audit->log(
            $active ? 'admin.department_activated' : 'admin.department_deactivated',
            'admin',
            $department,
            actor: $actor,
        );

        return $department->fresh();
    }
}
