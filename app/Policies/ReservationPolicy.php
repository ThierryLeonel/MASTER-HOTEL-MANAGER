<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage-reservations');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-reservations');
    }

    /**
     * The approval gate itself (spec section 22) — separate from the
     * general manage-reservations permission so it can be granted
     * independently (e.g. Duty Manager approves what Front Desk creates).
     */
    public function approve(User $user, Reservation $reservation): bool
    {
        return $user->can('approve-reservation');
    }

    public function reject(User $user, Reservation $reservation): bool
    {
        return $user->can('approve-reservation');
    }

    public function cancel(User $user, Reservation $reservation): bool
    {
        return $user->can('manage-reservations');
    }

    /**
     * Correction round (feature companion to audited defect #3): verifying
     * a website-claimed deposit and posting it to the ledger is a
     * financial-approval-grade decision — gated the same as approving the
     * reservation itself, never on the broader manage-reservations
     * permission a non-cash-handling role could hold.
     */
    public function verifyDeposit(User $user, Reservation $reservation): bool
    {
        return $user->can('approve-reservation');
    }

    /**
     * Correction round (audited defect #8): disposing of a cancelled/
     * rejected reservation's deposit (refund/forfeit/transfer) is the same
     * class of decision as approving a reservation in the first place.
     */
    public function disposeDeposit(User $user, Reservation $reservation): bool
    {
        return $user->can('approve-reservation');
    }
}
