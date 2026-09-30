<?php

namespace App\Services\FrontOffice;

use App\Exceptions\HmsException;
use App\Models\ChargeType;
use App\Models\ChargeTypeRate;
use App\Models\FacilityAccess;
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
 *
 * Charge-types unification (Sept 2026 — see ChargeType's own docblock):
 * both methods below now take a ChargeType/ChargeTypeRate — the same
 * unified, admin-editable list every other charge (bar, restaurant,
 * front desk, and any custom type an admin adds) uses — instead of the
 * old Facility/FacilityRate models directly. The `facility_accesses`
 * table's own schema (`facility_id`/`facility_rate_id`) is completely
 * untouched underneath: every walk-in-payable ChargeType is
 * transparently backed by a shadow Facility row
 * (ChargeTypeService::syncLegacyFacility()), and that shadow row's id is
 * what's actually written to `facility_id` here — invisible to every
 * caller of this class and to the admin screen alike.
 */
class FacilityAccessService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PaymentLedgerService $ledger,
        private readonly ShiftGateService $shiftGate,
    ) {
    }

    public function grantForGuest(ChargeType $chargeType, Stay $stay, User $actor, ?string $notes = null): FacilityAccess
    {
        if ($stay->status !== 'active') {
            throw new HmsException('Free facility access can only be granted to a currently checked-in stay.');
        }

        if (! $chargeType->free_for_resident_guests) {
            throw new HmsException("\"{$chargeType->name}\" is not configured as free for resident guests. An admin can turn this on from Charge Types if that's not right.");
        }

        if ($chargeType->legacy_facility_id === null) {
            throw new HmsException("\"{$chargeType->name}\" isn't set up for facility access — mark it walk-in payable from Charge Types first.");
        }

        // A free grant never touches PaymentLedgerService, so the "no
        // transaction without an open shift" rule (PHASE9_AUDIT_AND_PLAN.md
        // §21) is asserted explicitly here.
        $this->shiftGate->assertOpenForCode('FO');

        $access = FacilityAccess::create([
            'access_number' => $this->generateNumber(),
            'facility_id' => $chargeType->legacy_facility_id,
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
        ChargeType $chargeType,
        ChargeTypeRate $rate,
        string $visitorName,
        ?string $visitorPhone,
        string $method,
        User $actor,
        ?string $notes = null,
        ?int $financialAccountId = null,
    ): FacilityAccess {
        if ((int) $rate->charge_type_id !== $chargeType->id || ! $rate->is_active) {
            throw new HmsException('This rate is not available for this charge type.');
        }

        if ($chargeType->legacy_facility_id === null || $rate->legacy_facility_rate_id === null) {
            throw new HmsException("\"{$chargeType->name}\" isn't set up for facility access — mark it walk-in payable from Charge Types first.");
        }

        return DB::transaction(function () use ($chargeType, $rate, $visitorName, $visitorPhone, $method, $actor, $notes, $financialAccountId) {
            $access = FacilityAccess::create([
                'access_number' => $this->generateNumber(),
                'facility_id' => $chargeType->legacy_facility_id,
                'visitor_name' => $visitorName,
                'visitor_phone' => $visitorPhone,
                'facility_rate_id' => $rate->legacy_facility_rate_id,
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
                notes: "{$chargeType->name}: {$rate->name} — {$visitorName}",
                financialAccountId: $financialAccountId,
                // Charge-types unification: a walk-in sale under a charge
                // type that has a department assigned (an admin can attach
                // one from Charge Types, e.g. Gym/Pool to a "Recreation"
                // department) now attributes that cash the same way every
                // other manually recorded departmental sale already does —
                // see PaymentLedgerService::record()'s own docblock for why
                // an explicit department also correctly skips the
                // shift-open gate. Null (the seeded default for
                // Facility-derived charge types) behaves exactly as before
                // this change.
                departmentId: $chargeType->department_id,
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
