<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Services\Sync\Concerns\HasSyncUlid;

class Guest extends Model
{
    use HasSyncUlid;
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'first_name', 'last_name', 'phone', 'email', 'id_document_type',
        'id_document_number', 'nationality', 'address', 'date_of_birth',
        'notes', 'is_blacklisted', 'blacklist_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_blacklisted' => 'boolean',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function stays()
    {
        return $this->hasMany(Stay::class);
    }
}
