<?php

namespace App\Services\FrontOffice;

use App\Exceptions\HmsException;
use App\Models\EventSpace;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Admin-editable list of bookable event venues, clarified via
 * AskUserQuestion ("Main Hall, Mini HALL, Conference halls, etc you know
 * better" — an admin-managed list, not a hardcoded one). Follows the
 * ChargeType-style "deactivate, don't delete if used" pattern rather than
 * IncomeSource's real-delete pattern, because — unlike `payments.income_source`
 * (a plain string column with no foreign key) — `event_reservation_spaces`
 * DOES carry a real foreign key to `event_spaces.id` with `restrictOnDelete`
 * (see this table's migration), so a real delete of a space still booked
 * by any event reservation would simply fail at the database level; this
 * class checks for that usage up front instead, so the admin gets a clear
 * explanation rather than a raw constraint-violation error.
 */
class EventSpaceService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function create(array $data, User $actor): EventSpace
    {
        $space = EventSpace::create([
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'max_capacity' => $data['max_capacity'] ?? null,
            'is_active' => true,
        ]);

        $this->audit->log('front_office.event_space_created', 'front_office', $space, null, $space->toArray(), actor: $actor);

        return $space;
    }

    public function update(EventSpace $space, array $data, User $actor): EventSpace
    {
        $oldValues = $space->only(['name', 'description', 'max_capacity', 'is_active']);

        $space->update([
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'max_capacity' => $data['max_capacity'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? $space->is_active),
        ]);

        $this->audit->log(
            'front_office.event_space_updated',
            'front_office',
            $space,
            oldValues: $oldValues,
            newValues: $space->fresh()->only(['name', 'description', 'max_capacity', 'is_active']),
            actor: $actor,
        );

        return $space->fresh();
    }

    public function delete(EventSpace $space, User $actor): void
    {
        if ($space->eventReservations()->exists()) {
            throw new HmsException("\"{$space->name}\" has event reservations booked against it and can't be deleted without erasing that history. Deactivate it instead.");
        }

        $this->audit->log('front_office.event_space_deleted', 'front_office', $space, oldValues: $space->toArray(), actor: $actor);

        $space->delete();
    }
}
