<?php

namespace App\Models;

use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Database\Eloquent\Model;

/**
 * Direct request ("the list should not be fixed... the accountant, or
 * finance officer [create/update], and [the owner] should be able to
 * update, create and delete it"): the managed, editable list behind the
 * "Income source" dropdown on the general "record a payment" screen (see
 * this table's migration for the full reasoning, and
 * IncomeSourceController/IncomeSourceService for who can do what to it).
 *
 * Deliberately just a name — `payments.income_source` stores whatever
 * text was chosen (or typed, via the dropdown's "Other" option) directly,
 * with no foreign key back to this table, so this list can be freely
 * edited or trimmed later without ever touching a historical payment.
 */
class IncomeSource extends Model
{
    use HasSyncUlid;

    protected $fillable = ['name', 'created_by', 'updated_by'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
