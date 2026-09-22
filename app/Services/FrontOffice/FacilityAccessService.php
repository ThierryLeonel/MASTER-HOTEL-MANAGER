<?php

namespace App\Services\FrontOffice;

use App\Exceptions\HmsException;
use App\Models\Facility;
use App\Models\FacilityAccess;
use App\Models\FacilityRate;
use App\Models\Stay;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\ShiftGateService;
use App\Services\PaymentLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gym & Swimming Pool access (post-9.8 second fix round,
 * PHASE9_AUDIT_AND_PLAN.md §20). Two paths, matching the user's own
 * description exactly:
 *
 *   "swimming pool guest have free access but others can register for
 *   the gym at the front desk and pay the registration fee even the
 *   swimming pool slots can be paid at the receptionist (front desk)
 *   before granted a access to the pool."
 *
 *  - grantForGuest(): a currently checked-in hotel guest — free, no
 *    payment, just a logged access record (still useful later for
 *    "how many guests actually use the pool/gym" reporting).
 *  - sellToWalkIn(): anyone else — a fee is charged and posted to the
 *    ledger through the same central PaymentLedgerService as every
 *    other sale, *before* access is considered granted (the access
 *    record and the payment are created together, in one transaction).
 */
class FacilityAccessService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PaymentLedgerService $ledger,
        private readonly ShiftGateService $shiftGate,
    ) {
    }

    public function grantForGuest(Facility $facility, Stay $stay, User $actor, ?string $notes = null): FacilityAccess
    {
        if ($stay->status !== 'active') {
            throw new HmsException('Free facility access can only be granted to a currently checked-in stay.');
        }

        // A free grant never touches PaymentLedgerService, so the "no
        // transaction without an open shift" rule (PHASE9_AUDIT_AND_PLAN.md
        // §21) is asserted explicitly here.
        $this->shiftGate->assertOpenForCode('FO');

        $access = FacilityAccess::create([
            'access_number' => $this->generateNumber(),
            'facility_id' => $facility->id,
            'stay_id' => $stay->id,
            'amount_charged' => 0,
            'granted_by' => $actor->id,
            'granted_at' => now(),
            'notes' => $notes,
        ]);

        $this->audit->log('facility.access_granted_free', 'front_office', $access, null, $access->toArray(), actor: $actor);

        return $access;
    }

    public function sellToWalkIn(
        Facility $facility,
        FacilityRate $rate,
        string $visitorName,
        ?string $visitorPhone,
        string $method,
        User $actor,
        ?string $notes = null,
        ?int $financialAccountId = null,
    ): FacilityAccess {
        if ($rate->facility_id !== $facility->id || ! $rate->is_active) {
            throw new HmsException('This rate is not available for this facility.');
        }

        return DB::transaction(function () use ($facility, $rate, $visitorName, $visitorPhone, $method, $actor, $notes, $financialAccountId) {
            $access = FacilityAccess::create([
                'access_number' => $this->generateNumber(),
                'facility_id' => $facility->id,
                'visitor_name' => $visitorName,
                'visitor_phone' => $visitorPhone,
                'facility_rate_id' => $rate->id,
                'amount_charged' => $rate->amount,
                'granted_by' => $actor->id,
                'granted_at' => now(),
                'notes' => $notes,
            ]);

            $payment = $this->ledger->record(
                amount: (float) $rate->amount,
                method: $method,
                sourceModule: 'facility_access',
                recordedBy: $actor,
                sourceReference: $access->access_number,
                reference: $access,
                notes: "{$facility->name}: {$rate->name} — {$visitorName}",
                financialAccountId: $financialAccountId,
            );

            $access->update(['payment_id' => $payment->id]);

            $this->audit->log('facility.access_sold', 'front_office', $access, null, $access->fresh()->toArray(), actor: $actor);

            return $access->fresh();
        });
    }

    private function generateNumber(): string
    {
        return 'FA-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
    }
}
