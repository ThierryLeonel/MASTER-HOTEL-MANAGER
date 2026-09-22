<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class LeaveBalance extends Model
{
    use HasSyncUlid;
    protected $fillable = ['user_id', 'leave_type_id', 'year', 'entitled_days', 'used_days'];

    protected function casts(): array
    {
        return [
            'entitled_days' => 'decimal:1',
            'used_days' => 'decimal:1',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function remainingDays(): float
    {
        return (float) $this->entitled_days - (float) $this->used_days;
    }
}
