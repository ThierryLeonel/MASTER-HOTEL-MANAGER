<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class AttendanceRecord extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = [
        'user_id', 'work_date', 'clock_in_at', 'clock_out_at',
        'status', 'recorded_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isClockedIn(): bool
    {
        return $this->clock_in_at !== null && $this->clock_out_at === null;
    }
}
