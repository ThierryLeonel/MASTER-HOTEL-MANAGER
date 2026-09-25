<?php

namespace App\Http\Controllers\FrontOffice;

use App\Exceptions\HmsException;
use App\Http\Controllers\Controller;
use App\Models\BreakfastEntitlement;
use App\Models\DiscountApproval;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoomController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index()
    {
        $rooms = Room::with(['category', 'currentStay.guest'])->orderBy('room_number')->get();
        $categories = RoomCategory::where('is_active', true)->get();
        // The Owner's edit form lists every category, active or not, so
        // a room still sitting in a since-deactivated category isn't
        // forced onto a different one just by saving an unrelated change.
        $allCategories = RoomCategory::orderBy('name')->get();

        return view('front-office.rooms.index', compact('rooms', 'categories', 'allCategories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'room_number' => 'required|string|max:20|unique:rooms,room_number',
            'room_category_id' => 'required|exists:room_categories,id',
            'floor' => 'nullable|string|max:20',
        ]);

        Room::create($data);

        return back()->with('status', 'Room added.');
    }

    /**
     * Direct request ("I want the owner to have the access to delete and
     * update room details, that is room numbers, descriptions etc").
     * Owner-only via 'edit-room-details'. Every field an Owner would
     * reasonably correct is editable — number, category, floor, the
     * free-text description (`notes`), and whether the room is in use at
     * all (`is_active`). Two changes are refused while they'd contradict
     * something already live, rather than silently breaking it:
     *   - changing the CATEGORY, or deactivating the room, while a guest
     *     is checked into it (the stay was rated against the old
     *     category), or while an upcoming reservation is already
     *     assigned to it (that reservation was booked/approved against
     *     the old category — ReservationService::approve() treats a
     *     category mismatch as something that needs explicit sign-off,
     *     so this never lets it happen implicitly underneath it).
     * Renaming a room (room_number) is always allowed — every stay,
     * receipt and report reads the number through the room relation, so
     * a corrected number simply shows everywhere; the old value is kept
     * on the audit log.
     */
    public function update(Request $request, Room $room)
    {
        $data = $request->validate([
            'room_number' => 'required|string|max:20|unique:rooms,room_number,'.$room->id,
            'room_category_id' => 'required|exists:room_categories,id',
            'floor' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active');

        $room = DB::transaction(function () use ($request, $room, $data) {
            /** @var Room $room */
            $room = Room::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();

            $categoryChanging = (int) $data['room_category_id'] !== (int) $room->room_category_id;
            $deactivating = $room->is_active && ! $data['is_active'];

            if ($categoryChanging || $deactivating) {
                $what = $categoryChanging ? 'change this room\'s category' : 'deactivate this room';

                if ($room->hasActiveStay()) {
                    throw new HmsException("A guest is currently checked into room {$room->room_number} — check them out before you {$what}.");
                }

                $upcoming = $room->reservations()
                    ->whereIn('status', ['pending', 'approved', 'confirmed'])
                    ->count();

                if ($upcoming > 0) {
                    throw new HmsException("Room {$room->room_number} is assigned to {$upcoming} upcoming reservation(s) — reassign or cancel them before you {$what}.");
                }
            }

            $before = $room->toArray();
            $room->update($data);

            $this->audit->log('room.updated', 'front_office', $room, $before, $room->toArray(), actor: $request->user());

            return $room;
        });

        return back()->with('status', "Room {$room->room_number} updated.");
    }

    /**
     * Owner-only delete. Refuses for any room with history: stays,
     * reservations (any status), housekeeping tasks, maintenance tickets,
     * breakfast entitlements, or discount approvals all point at a room,
     * and erasing it would either be blocked by the database outright
     * (stays/housekeeping/breakfast use restrictOnDelete) or silently
     * strip the room off historical reservations/tickets/approvals
     * (nullOnDelete) — rewriting history this project never allows. A
     * room with history is deactivated instead (update() above), which
     * already takes it out of availability everywhere
     * (Room::isAvailableFor() checks is_active first). Only a room that
     * was genuinely never used — typically one added by mistake or with
     * a typo'd number — can be removed outright. The hybrid sync engine
     * does not propagate hard deletes (SyncService), so on a two-node
     * install the room would need deleting on each node separately.
     */
    public function destroy(Request $request, Room $room)
    {
        $number = DB::transaction(function () use ($request, $room) {
            /** @var Room $room */
            $room = Room::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();

            $history = array_filter([
                'stays' => $room->stays()->exists(),
                'reservations' => $room->reservations()->exists(),
                'housekeeping tasks' => $room->housekeepingTasks()->exists(),
                'maintenance tickets' => $room->maintenanceTickets()->exists(),
                'breakfast entitlements' => BreakfastEntitlement::where('room_id', $room->id)->exists(),
                'discount approvals' => DiscountApproval::where('room_id', $room->id)->exists(),
            ]);

            if ($history !== []) {
                throw new HmsException(
                    "Room {$room->room_number} has history (".implode(', ', array_keys($history)).') and can\'t be deleted without erasing that record. Deactivate it instead — it will no longer be bookable.'
                );
            }

            $this->audit->log('room.deleted', 'front_office', $room, $room->toArray(), null, actor: $request->user());
            $room->delete();

            return $room->room_number;
        });

        return back()->with('status', "Room {$number} deleted.");
    }

    /**
     * Post-9.8 fix round ("let the receptionist have the access to
     * update room status"): previously the ONLY way a room's `status`
     * ever changed was as a side effect of check-in/check-out/maintenance
     * services — there was no direct "just change the status" action at
     * all. Gated on the new 'update-room-status' permission (separate
     * from 'manage-rooms', same reasoning as 'view-rooms') so front_desk
     * can use this without being able to add/edit rooms outright.
     *
     * Deliberately does not allow setting status to 'occupied' by hand —
     * that must go through an actual check-in (CheckInService), which
     * also creates the Stay/Folio a room marked "occupied" implies. Only
     * the housekeeping/maintenance-style statuses are settable here.
     */
    public function updateStatus(Request $request, Room $room)
    {
        $data = $request->validate([
            'status' => 'required|in:available,maintenance,out_of_service',
        ]);

        // Correction round (audited defect #12): this had no
        // DB::transaction/lockForUpdate at all, so it raced against
        // check-in/checkout/maintenance approval — two concurrent
        // requests (or a request landing mid check-in) could both read a
        // stale, unlocked $room and one could win a status change that
        // contradicted what had just happened underneath it. It also only
        // ever consulted the mutable `status` column ('occupied') to
        // guard against overwriting an active stay, which — per Room::
        // hasActiveStay()'s own docblock (audited defect #4) — is not a
        // reliable signal; a stale/incorrect status column here used to
        // let a manual status change quietly clobber a genuinely occupied
        // room. Finally, it never checked hasBlockingMaintenance() before
        // allowing 'available', so staff could manually override the very
        // guard MaintenanceService/CheckOutService enforce elsewhere, and
        // it never wrote an audit log entry despite being a real, direct
        // state-changing action.
        $room = DB::transaction(function () use ($request, $room, $data) {
            /** @var Room $room */
            $room = Room::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();

            if ($room->hasActiveStay()) {
                throw new HmsException('This room is currently occupied by a guest — check the guest out before changing its status.');
            }

            if ($data['status'] === 'available' && $room->hasBlockingMaintenance()) {
                throw new HmsException('This room has an unresolved maintenance ticket that takes it out of service — resolve it before marking the room available.');
            }

            $before = $room->toArray();
            $room->update(['status' => $data['status']]);

            $this->audit->log(
                'room.status_updated',
                'front_office',
                $room,
                $before,
                $room->toArray(),
                actor: $request->user(),
            );

            return $room;
        });

        return back()->with('status', "Room {$room->room_number} marked {$data['status']}.");
    }
}
