<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\BreakfastEntitlement;
use App\Models\MenuItem;
use App\Models\Stay;
use App\Services\Finance\AccountingDayService;
use App\Services\Restaurant\BreakfastEntitlementService;
use Illuminate\Http\Request;

/**
 * Feature 3 ("one complimentary breakfast per occupied unit per business
 * date") — deliberately a small, standalone screen rather than a tab
 * bolted onto the already-complex per-outlet POS module (OrderController/
 * pos/{outlet} views): a complimentary redemption has none of an Order's
 * draft/confirm/close/table/payment concerns, so giving it its own
 * controller keeps both simple.
 */
class BreakfastController extends Controller
{
    public function __construct(private readonly BreakfastEntitlementService $service)
    {
    }

    public function index(Request $request, AccountingDayService $accountingDay)
    {
        $businessDate = $accountingDay->today()->business_date;

        $activeStays = Stay::with(['guest', 'room'])
            ->where('status', 'active')
            ->orderBy('room_id')
            ->get();

        $todaysEntitlements = BreakfastEntitlement::with(['stay.guest', 'room', 'menuItem', 'servedBy'])
            ->where('business_date', $businessDate)
            ->orderByDesc('served_at')
            ->get();

        $breakfastItems = MenuItem::where('outlet', 'restaurant')->where('is_active', true)->orderBy('name')->get();

        $redeemedStayIds = $todaysEntitlements->where('reversed_at', null)->pluck('stay_id');

        return view('restaurant.breakfast.index', compact('activeStays', 'todaysEntitlements', 'breakfastItems', 'redeemedStayIds', 'businessDate'));
    }

    public function redeem(Request $request, Stay $stay)
    {
        $data = $request->validate([
            'menu_item_id' => 'required|exists:menu_items,id',
            'delivered_to_room' => 'nullable|boolean',
        ]);

        $menuItem = MenuItem::findOrFail($data['menu_item_id']);

        $this->service->redeem($stay, $menuItem, $request->user(), $request->boolean('delivered_to_room'));

        return back()->with('status', 'Complimentary breakfast redeemed for room '.$stay->room->room_number.'.');
    }

    /**
     * "Edit ... complimentary breakfast": correcting today's own redemption
     * (menu item / delivered-to-room). See BreakfastEntitlementService::
     * update() for why this is same-day-only and reversed entries are
     * excluded — same permission as reversing, since both are manager-level
     * corrections to an already-served redemption.
     */
    public function update(Request $request, BreakfastEntitlement $entitlement)
    {
        $data = $request->validate([
            'menu_item_id' => 'nullable|exists:menu_items,id',
            'delivered_to_room' => 'nullable|boolean',
        ]);

        $menuItem = isset($data['menu_item_id']) ? MenuItem::findOrFail($data['menu_item_id']) : null;

        $this->service->update($entitlement, $menuItem, $request->boolean('delivered_to_room'), $request->user());

        return back()->with('status', 'Breakfast entitlement updated.');
    }

    /**
     * "Delete ... complimentary breakfast": exposed to staff as a delete
     * button, but implemented as the same audited reversal `reverse()`
     * already used elsewhere — this table's own design deliberately never
     * lets a delete free up a spent (stay_id, business_date) slot or erase
     * that a breakfast really was served (see
     * BreakfastEntitlementService::reverse()'s docblock), the same
     * "never rewrite/erase history" rule this whole engagement applies to
     * every other real record. The stock it consumed is restored either
     * way, so the practical effect staff see — the redemption disappearing
     * from the active list, minus its stock cost — is identical to a
     * delete.
     */
    public function destroy(Request $request, BreakfastEntitlement $entitlement)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $this->service->reverse($entitlement, $data['reason'] ?? 'Deleted by manager', $request->user());

        return back()->with('status', 'Breakfast entitlement deleted (its stock deduction was restored).');
    }

    public function reverse(Request $request, BreakfastEntitlement $entitlement)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->reverse($entitlement, $data['reason'], $request->user());

        return back()->with('status', 'Breakfast entitlement reversed and its stock deduction restored.');
    }
}
