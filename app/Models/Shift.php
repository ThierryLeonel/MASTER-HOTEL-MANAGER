<?php

namespace App\Models;

use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasSyncUlid;

    protected $fillable = [
        'shift_number', 'department_id', 'opened_by', 'opened_at',
        'closed_by', 'closed_at', 'status', 'expected_cash',
        'declared_cash', 'variance', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'expected_cash' => 'decimal:2',
            'declared_cash' => 'decimal:2',
            'variance' => 'decimal:2',
        ];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
