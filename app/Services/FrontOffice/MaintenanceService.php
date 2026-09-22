<?php

namespace App\Services\FrontOffice;

use App\Exceptions\HmsException;
use App\Models\MaintenanceTicket;
use App\Models\Room;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Maintenance approval gate (spec section 22): a ticket flagged
 * takes_room_out_of_service only actually flips the room's status once a
 * Duty Manager/GM approves it — reporting the ticket alone never takes a
 * room out of service.
 */
class MaintenanceService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function report(array $data, User $reportedBy): MaintenanceTicket
    {
        $ticket = MaintenanceTicket::create([
            'room_id' => $data['room_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'],
            'priority' => $data['priority'] ?? 'medium',
            'status' => 'open',
            'takes_room_out_of_service' => $data['takes_room_out_of_service'] ?? false,
            'reported_by' => $reportedBy->id,
        ]);

        $this->audit->log('maintenance.reported', 'front_office', $ticket, null, $ticket->toArray(), actor: $reportedBy);

        return $ticket;
    }

    public function approve(MaintenanceTicket $ticket, User $approver): MaintenanceTicket
    {
        return DB::transaction(function () use ($ticket, $approver) {
            /** @var MaintenanceTicket $ticket */
            $ticket = MaintenanceTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            if ($ticket->status !== 'open') {
                throw new HmsException("Only an open ticket can be approved (current: {$ticket->status}).");
            }

            $ticket->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            // Correction round (audited defect #9): approving a
            // takes_room_out_of_service ticket used to unconditionally
            // overwrite the room's status to 'maintenance', even when the
            // room was currently 'occupied' — silently destroying the
            // occupancy signal a live Stay depends on. Room::
            // hasBlockingMaintenance() (this delivery) is what actually
            // communicates "this room is blocked" from here on, driven by
            // the ticket's own status — the `status` column is only ever
            // flipped to 'maintenance' when the room is genuinely vacant
            // right now, so a guest's active Stay is never clobbered.
            if ($ticket->takes_room_out_of_service && $ticket->room_id) {
                /** @var Room $room */
                $room = Room::lockForUpdate()->findOrFail($ticket->room_id);

                if (! $room->hasActiveStay() && $room->status === 'available') {
                    $room->update(['status' => 'maintenance']);
                }
            }

            $this->audit->log('maintenance.approved', 'front_office', $ticket, null, $ticket->toArray(), actor: $approver);

            return $ticket;
        });
    }

    public function resolve(MaintenanceTicket $ticket, User $resolver, string $notes): MaintenanceTicket
    {
        if (! in_array($ticket->status, ['approved', 'in_progress'], true)) {
            throw new HmsException("Ticket must be approved/in progress to resolve (current: {$ticket->status}).");
        }

        return DB::transaction(function () use ($ticket, $resolver, $notes) {
            /** @var MaintenanceTicket $locked */
            $locked = MaintenanceTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['approved', 'in_progress'], true)) {
                throw new HmsException("Ticket must be approved/in progress to resolve (current: {$locked->status}).");
            }

            $locked->update([
                'status' => 'resolved',
                'resolved_by' => $resolver->id,
                'resolved_at' => now(),
                'resolution_notes' => $notes,
            ]);

            // Correction round (audited defects #9 and #11): only clear the
            // room back to 'available' when (a) it isn't currently occupied
            // by a live Stay — resolving a ticket must never mark an
            // occupied room available — and (b) no OTHER approved/
            // in_progress blocking ticket remains on this room. Room::
            // hasBlockingMaintenance() is re-checked AFTER this ticket's
            // own status has already been flipped to 'resolved' above, so
            // it correctly reflects only the remaining tickets.
            if ($ticket->takes_room_out_of_service && $ticket->room_id) {
                /** @var Room $room */
                $room = Room::lockForUpdate()->findOrFail($ticket->room_id);

                if (! $room->hasActiveStay() && ! $room->hasBlockingMaintenance() && $room->status === 'maintenance') {
                    $room->update(['status' => 'available']);
                }
            }

            $this->audit->log('maintenance.resolved', 'front_office', $locked, null, $locked->toArray(), actor: $resolver);

            return $locked;
        });
    }
}
