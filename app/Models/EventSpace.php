<?php

namespace App\Models;

use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Direct request ("WHICH SPACES DO THEY WANT TO RESERVE... MAIN HALL,
 * MINI HALL FOR EXAMPLE, POOL OR THE WHOLE OUTER ENVIROMENT (SPACE)"),
 * clarified via AskUserQuestion as an admin-editable list rather than a
 * fixed set — see this table's migration for the full reasoning. Starter
 * rows (Main Hall, Mini Hall, Pool / Outdoor Area, Conference Hall) are
 * seeded by EventSpaceSeeder; an admin can rename, add, or deactivate any
 * of them from the Event Spaces screen at any time.
 */
class EventSpace extends Model
{
    use HasSyncUlid;
    use HasFactory;

    protected $fillable = ['name', 'description', 'max_capacity', 'is_active'];

    protected function casts(): array
    {
        return [
            'max_capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function eventReservations()
    {
        return $this->belongsToMany(EventReservation::class, 'event_reservation_spaces');
    }
}
