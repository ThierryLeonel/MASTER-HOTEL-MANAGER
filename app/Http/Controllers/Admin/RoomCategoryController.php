<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\HmsException;
use App\Http\Controllers\Controller;
use App\Models\DiscountApproval;
use App\Models\Reservation;
use App\Models\RoomCategory;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 9.2 (PHASE9_AUDIT_AND_PLAN.md §3.3, "rooms" under System
 * Settings): RoomCategory (rates, names, max occupancy) has existed
 * since Phase 1 — Reservations and the Room board both read from it —
 * but was only ever seeded (RoomSeeder), never editable. Gated on
 * 'manage-settings' rather than 'manage-rooms': changing a category's
 * nightly rate is a pricing/policy decision (Owner/GM), distinct from
 * Duty Manager/Front Desk adding a physical room under 'manage-rooms'.
 */
class RoomCategoryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index()
    {
        $categories = RoomCategory::withCount('rooms')->orderBy('name')->get();

        return view('admin.room-categories.index', compact('categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:room_categories,name',
            'description' => 'nullable|string',
            'max_occupancy' => 'required|integer|min:1|max:20',
            'base_rate' => 'required|numeric|min:0',
        ]);

        $category = RoomCategory::create([...$data, 'is_active' => true]);

        $this->audit->log('admin.room_category_created', 'admin', $category, newValues: $category->toArray(), actor: $request->user());

        return back()->with('status', 'Room category created.');
    }

    public function update(Request $request, RoomCategory $roomCategory)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('room_categories', 'name')->ignore($roomCategory->id)],
            'description' => 'nullable|string',
            'max_occupancy' => 'required|integer|min:1|max:20',
            'base_rate' => 'required|numeric|min:0',
        ]);

        $oldValues = $roomCategory->only(['name', 'max_occupancy', 'base_rate']);

        $roomCategory->update($data);

        $this->audit->log(
            'admin.room_category_updated',
            'admin',
            $roomCategory,
            oldValues: $oldValues,
            newValues: $roomCategory->only(['name', 'max_occupancy', 'base_rate']),
            actor: $request->user(),
        );

        return back()->with('status', 'Room category updated.');
    }

    public function activate(Request $request, RoomCategory $roomCategory)
    {
        $roomCategory->update(['is_active' => true]);
        $this->audit->log('admin.room_category_activated', 'admin', $roomCategory, actor: $request->user());

        return back()->with('status', 'Room category reactivated.');
    }

    public function deactivate(Request $request, RoomCategory $roomCategory)
    {
        $roomCategory->update(['is_active' => false]);
        $this->audit->log('admin.room_category_deactivated', 'admin', $roomCategory, actor: $request->user());

        return back()->with('status', 'Room category deactivated (existing rooms are unaffected).');
    }

    /**
     * Broader request ("make sure the owner/main user has the access to
     * edit, delete and update all categories and every input, except
     * financial inputs"): RoomCategory could already be edited/deactivated
     * but never actually removed. Same "only when genuinely unused"
     * guard as RoomController::destroy() — a category still assigned to
     * any room, or referenced by any reservation or discount-approval
     * record (even for a room since deleted), is deactivated instead so
     * that history keeps reading a real category name rather than a
     * dangling reference.
     */
    public function destroy(Request $request, RoomCategory $roomCategory)
    {
        if ($roomCategory->rooms()->exists()) {
            throw new HmsException("Category \"{$roomCategory->name}\" still has rooms assigned to it — reassign or remove those rooms first, or deactivate the category instead.");
        }

        if (Reservation::where('room_category_id', $roomCategory->id)->exists() || DiscountApproval::where('room_category_id', $roomCategory->id)->exists()) {
            throw new HmsException("Category \"{$roomCategory->name}\" has reservation or discount history and can't be deleted without erasing that record. Deactivate it instead.");
        }

        $name = $roomCategory->name;
        $this->audit->log('admin.room_category_deleted', 'admin', $roomCategory, oldValues: $roomCategory->toArray(), actor: $request->user());
        $roomCategory->delete();

        return back()->with('status', "Room category \"{$name}\" deleted.");
    }
}
