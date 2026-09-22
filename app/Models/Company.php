<?php

namespace App\Models;

use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasSyncUlid;

    protected $fillable = [
        'name', 'contact_name', 'phone', 'email', 'credit_limit', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function stays()
    {
        return $this->hasMany(Stay::class);
    }

    public function accountsReceivable()
    {
        return $this->hasMany(AccountsReceivable::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The company's total unpaid balance across every stay it was ever
     * billed for — what "closing client debt" needs to show at a glance,
     * per PHASE9_AUDIT_AND_PLAN.md §22.
     */
    public function totalOutstanding(): float
    {
        return (float) $this->accountsReceivable()->whereIn('status', ['open', 'partially_paid'])->sum('amount_outstanding');
    }
}
