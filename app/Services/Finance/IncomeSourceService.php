<?php

namespace App\Services\Finance;

use App\Models\IncomeSource;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Direct request ("the list should not be fixed... the accountant, or
 * finance officer [create/update], and [the owner] should be able to
 * update, create and delete it") — see IncomeSource's own docblock and
 * this table's migration for why a real delete is safe here (this list
 * carries no foreign key from `payments`, so nothing historical is ever
 * touched by anything in this class).
 */
class IncomeSourceService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function create(array $data, User $actor): IncomeSource
    {
        $source = IncomeSource::create([
            'name' => trim($data['name']),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->audit->log('finance.income_source_created', 'finance', $source, null, $source->toArray(), actor: $actor);

        return $source;
    }

    public function update(IncomeSource $source, array $data, User $actor): IncomeSource
    {
        $oldValues = $source->only(['name']);

        $source->update([
            'name' => trim($data['name']),
            'updated_by' => $actor->id,
        ]);

        $this->audit->log(
            'finance.income_source_updated',
            'finance',
            $source,
            oldValues: $oldValues,
            newValues: $source->fresh()->only(['name']),
            actor: $actor,
        );

        return $source->fresh();
    }

    /**
     * A real, permanent delete — deliberately not a soft delete/deactivate
     * toggle, per the direct request that the owner be able to "delete"
     * this (not merely hide) an entry. Safe precisely because this table
     * has no foreign key from `payments.income_source` (see this model's
     * migration) — every payment recorded against this source before it
     * was deleted keeps showing its stored text exactly as it always did,
     * completely unaffected. This only ever changes what the dropdown
     * offers for FUTURE payments.
     */
    public function delete(IncomeSource $source, User $actor): void
    {
        $usageCount = Payment::where('income_source', $source->name)->count();

        $this->audit->log(
            'finance.income_source_deleted',
            'finance',
            $source,
            oldValues: array_merge($source->only(['name']), ['payments_recorded_under_this_name' => $usageCount]),
            newValues: null,
            actor: $actor,
        );

        $source->delete();
    }
}
