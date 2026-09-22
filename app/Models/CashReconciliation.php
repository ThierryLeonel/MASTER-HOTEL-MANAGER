<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class CashReconciliation extends Model
{
    use HasSyncUlid;
    protected $fillable = ['business_date', 'expected_amount', 'counted_amount', 'variance', 'recorded_by', 'notes'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'expected_amount' => 'decimal:2',
            'counted_amount' => 'decimal:2',
            'variance' => 'decimal:2',
        ];
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
