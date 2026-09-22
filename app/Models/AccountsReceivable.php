<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class AccountsReceivable extends Model
{
    use HasSyncUlid;
    protected $table = 'accounts_receivable';

    protected $fillable = [
        'folio_id', 'guest_id', 'company_id', 'original_amount', 'amount_outstanding',
        'status', 'due_date', 'created_by', 'written_off_by', 'written_off_reason',
    ];

    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:2',
            'amount_outstanding' => 'decimal:2',
            'due_date' => 'date',
        ];
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isSettleable(): bool
    {
        return in_array($this->status, ['open', 'partially_paid'], true);
    }
}
