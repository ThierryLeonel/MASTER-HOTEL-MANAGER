<?php

namespace App\Models;

use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Database\Eloquent\Model;

class DisbursementTicket extends Model
{
    use HasSyncUlid;

    protected $fillable = [
        'ticket_number', 'department_id', 'purpose', 'amount',
        'status', 'authorized_by', 'notes', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function authorizedBy()
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function disbursements()
    {
        return $this->hasMany(CashDisbursement::class, 'ticket_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
