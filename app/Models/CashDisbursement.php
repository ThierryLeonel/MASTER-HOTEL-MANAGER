<?php

namespace App\Models;

use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Database\Eloquent\Model;

class CashDisbursement extends Model
{
    use HasSyncUlid;

    protected $fillable = [
        'disbursement_number', 'ticket_id', 'department_id', 'amount',
        'paid_to', 'purpose', 'expense_id', 'method', 'financial_account_id', 'authorized_by_user_id',
        'authorized_by_name', 'authorized_by_title', 'recorded_by',
        'disbursed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'disbursed_at' => 'datetime',
        ];
    }

    public function ticket()
    {
        return $this->belongsTo(DisbursementTicket::class, 'ticket_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function financialAccount()
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function authorizedByUser()
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * A single line describing who authorized this, regardless of which
     * of the two shapes (ticket vs named authorizer) it was recorded
     * under — used everywhere this needs to be displayed/printed.
     */
    public function authorizationLabel(): string
    {
        if ($this->ticket) {
            return "Ticket {$this->ticket->ticket_number} — authorized by {$this->ticket->authorizedBy?->name}";
        }

        if ($this->authorized_by_user_id) {
            return 'Authorized by '.($this->authorizedByUser?->name ?? 'unknown user');
        }

        $title = $this->authorized_by_title ? " ({$this->authorized_by_title})" : '';

        return "Authorized by {$this->authorized_by_name}{$title}";
    }
}
