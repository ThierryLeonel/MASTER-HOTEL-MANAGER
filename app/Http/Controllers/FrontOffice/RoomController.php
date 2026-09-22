<?php

namespace App\Http\Controllers\FrontOffice;

use App\Exceptions\HmsException;
use App\Http\Controllers\Controller;
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

        return view('front-office.rooms.index', compact('rooms', 'categories'));
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
