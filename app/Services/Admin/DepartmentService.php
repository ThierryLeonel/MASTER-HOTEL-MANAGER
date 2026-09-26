<?php

namespace App\Services\Admin;

use App\Exceptions\HmsException;
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

    /**
     * Broader request ("...edit, delete and update all categories and
     * every input, except financial inputs"): a department could already
     * be deactivated but never actually removed. Deactivation alone was
     * already the deliberate design for anything with real usage (see
     * this class's original docblock) — this only adds true deletion for
     * a department that was never actually used, checking every table
     * that references it, INCLUDING the financial ones (expenses,
     * disbursements), specifically because those use nullOnDelete: a hard
     * delete would otherwise silently strip the department off a
     * historical expense or disbursement record rather than merely
     * unassigning a still-open one — exactly the kind of financial-record
     * rewrite this project never allows. Requisitions/shifts already use
     * restrictOnDelete at the database level, but are checked here too so
     * a blocked delete gets a clear message instead of a raw SQL error.
     */
    public function delete(Department $department, User $actor): void
    {
        $history = array_filter([
            'staff assigned to it' => $department->users()->exists(),
            'stock requisitions' => \App\Models\StockRequisition::where('department_id', $department->id)->exists(),
            'shifts' => \App\Models\Shift::where('department_id', $department->id)->exists(),
            'expenses' => \App\Models\Expense::where('department_id', $department->id)->exists(),
            'disbursement tickets' => \App\Models\DisbursementTicket::where('department_id', $department->id)->exists(),
            'cash disbursements' => \App\Models\CashDisbursement::where('department_id', $department->id)->exists(),
            'wastage records' => \App\Models\StockWastage::where('department_id', $department->id)->exists(),
        ]);

        if ($history !== []) {
            throw new HmsException(
                "Department \"{$department->name}\" has history (".implode(', ', array_keys($history)).') and can\'t be deleted without erasing that record. Deactivate it instead.'
            );
        }

        $this->audit->log('admin.department_deleted', 'admin', $department, oldValues: $department->toArray(), actor: $actor);
        $department->delete();
    }
}
