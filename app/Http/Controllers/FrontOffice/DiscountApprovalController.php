<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\DiscountApproval;
use App\Services\FrontOffice\DiscountApprovalService;
use Illuminate\Http\Request;

/**
 * Feature 6 ("Approved discounts on check-in") — the generic surface for
 * a discount-approval REQUEST addressed to a specific eligible approver
 * (see DiscountApprovalService::requestApproval()). Self-approval and
 * requesting one happen inline from the reservation page
 * (ReservationController::selfApproveDiscount()/requestDiscount()); this
 * controller is where the addressed approver actually acts on it, from
 * their own authenticated session — never from a link a client could
 * spoof, since approve()/reject() always use $request->user(), never a
 * submitted approver id.
 */
class DiscountApprovalController extends Controller
{
    public function __construct(private readonly DiscountApprovalService $service)
    {
    }

    /**
     * "My pending discount approval requests" — every request addressed
     * to the current user, still pending.
     */
    public function index(Request $request)
    {
        $pending = DiscountApproval::with(['approvable', 'roomCategory', 'room', 'requestedBy'])
            ->where('requested_approver_id', $request->user()->id)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get();

        return view('front-office.discount-approvals.index', compact('pending'));
    }

    public function approve(Request $request, DiscountApproval $discountApproval)
    {
        $this->service->approveRequest($discountApproval, $request->user());

        return back()->with('status', 'Discount approved.');
    }

    public function reject(Request $request, DiscountApproval $discountApproval)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->rejectRequest($discountApproval, $request->user(), $data['reason']);

        return back()->with('status', 'Discount request rejected.');
    }
}
