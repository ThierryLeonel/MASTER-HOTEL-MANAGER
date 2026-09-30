<?php

namespace App\Services\Finance;

use App\Exceptions\HmsException;
use App\Models\ChargeType;
use App\Models\ChargeTypeRate;
use App\Models\Facility;
use App\Models\FacilityAccess;
use App\Models\FacilityRate;
use App\Models\FolioTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Str;

/**
 * Direct request (Sept 2026): "normally we have bar charge, restaurant
 * charge, frontdesk charge, rental charge, pool fee, Gym Fee, etc
 * include all connect them... just build it in such a way i can edit and
 * or just add or delete." See ChargeType's own docblock and its
 * migration for the full reasoning behind unifying the old fixed
 * `FolioTransaction::CHARGE_TYPES` list with the separate Facility/
 * FacilityRate (Gym & Pool) configuration into one editable list.
 *
 * A subtlety this whole class exists to manage correctly: when a charge
 * type is `walk_in_payable`, every sale under it still has to satisfy the
 * pre-existing, already-tested `facility_accesses` schema underneath
 * (`facility_id` is a real, NOT NULL foreign key there — see that
 * table's migration — and rewriting it to point at charge_types instead
 * would mean altering a constraint on a table real hotels already have
 * real history in, which this engagement will not risk without being
 * able to test it for real). So every walk-in-payable ChargeType is kept
 * transparently backed by a real `Facility` row (and every
 * ChargeTypeRate by a real `FacilityRate`), created and kept in sync
 * automatically here — completely invisible to the admin screen, which
 * only ever shows/edits the ChargeType/ChargeTypeRate side. Nothing about
 * this class ever asks the user to think about Facility/FacilityRate at
 * all; they are purely an internal storage detail from here on.
 */
class ChargeTypeService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function create(array $data, User $actor): ChargeType
    {
        $chargeType = ChargeType::create([
            // A charge type's code is never user-typed (avoids typos/
            // clashes the same way Facility::generateCode() already
            // avoided them for facility codes) — generated once here and
            // permanent from that point on; see update()'s refusal to
            // ever change it.
            'code' => $this->generateCode($data['name']),
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'postable_to_folio' => (bool) ($data['postable_to_folio'] ?? true),
            'walk_in_payable' => (bool) ($data['walk_in_payable'] ?? false),
            'free_for_resident_guests' => (bool) ($data['free_for_resident_guests'] ?? false),
            'is_system' => false,
            'is_active' => true,
        ]);

        $this->syncLegacyFacility($chargeType);

        $this->audit->log('finance.charge_type_created', 'finance', $chargeType, null, $chargeType->fresh()->toArray(), actor: $actor);

        return $chargeType->fresh();
    }

    /**
     * `code` is deliberately never accepted here, for every charge type
     * — not just system ones. Every historical FolioTransaction/Payment/
     * FacilityAccess row that already exists under a charge type's code
     * only ever stores that code as a plain string (there is no foreign
     * key from those tables to `charge_types.id`, the same "so a report
     * never breaks if this row is later removed" reasoning already used
     * for `payments.income_source`) — silently renaming a code out from
     * under that history would make every one of those old rows
     * impossible to correctly re-associate with this charge type ever
     * again. Renaming the user-facing `name` is completely safe and
     * always allowed instead; it's what's actually shown everywhere.
     */
    public function update(ChargeType $chargeType, array $data, User $actor): ChargeType
    {
        $oldValues = $chargeType->only(['name', 'description', 'department_id', 'postable_to_folio', 'walk_in_payable', 'free_for_resident_guests', 'is_active']);

        // A system charge type (room_charge and the six others this
        // codebase relies on structurally) can never be switched off —
        // deactivating room_charge, for instance, would silently break
        // every stay's ability to be charged for its own room. Its name/
        // description/department/postable-and-payable flags stay fully
        // editable; only `is_active` is pinned true.
        $isActive = $chargeType->is_system ? true : (bool) ($data['is_active'] ?? $chargeType->is_active);

        $chargeType->update([
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'postable_to_folio' => (bool) ($data['postable_to_folio'] ?? $chargeType->postable_to_folio),
            'walk_in_payable' => (bool) ($data['walk_in_payable'] ?? $chargeType->walk_in_payable),
            'free_for_resident_guests' => (bool) ($data['free_for_resident_guests'] ?? $chargeType->free_for_resident_guests),
            'is_active' => $isActive,
        ]);

        $this->syncLegacyFacility($chargeType->fresh());

        $this->audit->log(
            'finance.charge_type_updated',
            'finance',
            $chargeType,
            oldValues: $oldValues,
            newValues: $chargeType->fresh()->only(['name', 'description', 'department_id', 'postable_to_folio', 'walk_in_payable', 'free_for_resident_guests', 'is_active']),
            actor: $actor,
        );

        return $chargeType->fresh();
    }

    /**
     * Never allowed for a system charge type (the seven this codebase
     * relies on by code — see ChargeType's migration), and refused for
     * any charge type that already has real history against it (a posted
     * folio charge under its code, a configured rate, or a recorded
     * facility access) — deactivate instead, the same "never delete
     * something with real history, hide it instead" rule already used
     * for Facility/FacilityRate and every other managed list in this
     * engagement.
     */
    public function delete(ChargeType $chargeType, User $actor): void
    {
        if ($chargeType->is_system) {
            throw new HmsException("\"{$chargeType->name}\" is a built-in charge type this system relies on and can't be deleted.");
        }

        if (FolioTransaction::where('type', $chargeType->code)->exists()) {
            throw new HmsException("\"{$chargeType->name}\" has real charges posted under it and can't be deleted without erasing that history. Deactivate it instead.");
        }

        if ($chargeType->rates()->exists()) {
            throw new HmsException("\"{$chargeType->name}\" still has rates configured — delete those first.");
        }

        if ($chargeType->legacy_facility_id !== null && FacilityAccess::where('facility_id', $chargeType->legacy_facility_id)->exists()) {
            throw new HmsException("\"{$chargeType->name}\" has sale history and can't be deleted without erasing that record. Deactivate it instead.");
        }

        $name = $chargeType->name;
        $this->audit->log('finance.charge_type_deleted', 'finance', $chargeType, oldValues: $chargeType->toArray(), actor: $actor);

        // The shadow Facility row (if any) has no rates or accesses left
        // at this point (checked above), so it's safe to remove alongside
        // its ChargeType rather than leaving an orphaned, invisible row
        // behind.
        if ($chargeType->legacy_facility_id !== null) {
            Facility::where('id', $chargeType->legacy_facility_id)->delete();
        }

        $chargeType->delete();
    }

    public function storeRate(ChargeType $chargeType, array $data, User $actor): ChargeTypeRate
    {
        $rate = $chargeType->rates()->create([
            'name' => trim($data['name']),
            'amount' => $data['amount'],
            'is_active' => true,
        ]);

        $this->syncLegacyFacilityRate($rate->fresh());

        $this->audit->log('finance.charge_type_rate_created', 'finance', $rate, null, $rate->fresh()->toArray(), actor: $actor);

        return $rate->fresh();
    }

    public function updateRate(ChargeTypeRate $rate, array $data, User $actor): ChargeTypeRate
    {
        $oldValues = $rate->only(['name', 'amount']);

        $rate->update([
            'name' => trim($data['name']),
            'amount' => $data['amount'],
        ]);

        $this->syncLegacyFacilityRate($rate->fresh());

        $this->audit->log(
            'finance.charge_type_rate_updated',
            'finance',
            $rate,
            oldValues: $oldValues,
            newValues: $rate->fresh()->only(['name', 'amount']),
            actor: $actor,
        );

        return $rate->fresh();
    }

    public function toggleRate(ChargeTypeRate $rate): ChargeTypeRate
    {
        $rate->update(['is_active' => ! $rate->is_active]);

        if ($rate->legacy_facility_rate_id !== null) {
            FacilityRate::where('id', $rate->legacy_facility_rate_id)->update(['is_active' => $rate->is_active]);
        }

        return $rate->fresh();
    }

    public function destroyRate(ChargeTypeRate $rate, User $actor): void
    {
        if ($rate->legacy_facility_rate_id !== null && FacilityAccess::where('facility_rate_id', $rate->legacy_facility_rate_id)->exists()) {
            throw new HmsException("Rate \"{$rate->name}\" has sale history and can't be deleted without erasing that record. Disable it instead.");
        }

        $name = $rate->name;
        $this->audit->log('finance.charge_type_rate_deleted', 'finance', $rate, oldValues: $rate->toArray(), actor: $actor);

        if ($rate->legacy_facility_rate_id !== null) {
            FacilityRate::where('id', $rate->legacy_facility_rate_id)->delete();
        }

        $rate->delete();
    }

    /**
     * Creates (or updates, once one already exists) the shadow Facility
     * row a walk-in-payable ChargeType needs underneath — see this
     * class's own docblock. A no-op for a charge type that isn't (or no
     * longer is) walk-in-payable and never was linked to one.
     */
    private function syncLegacyFacility(ChargeType $chargeType): void
    {
        if (! $chargeType->walk_in_payable) {
            return;
        }

        if ($chargeType->legacy_facility_id === null) {
            $facility = Facility::create([
                'code' => $this->generateFacilityCode($chargeType->name),
                'name' => $chargeType->name,
                'description' => $chargeType->description,
                'is_active' => $chargeType->is_active,
            ]);

            $chargeType->update(['legacy_facility_id' => $facility->id]);

            return;
        }

        Facility::where('id', $chargeType->legacy_facility_id)->update([
            'name' => $chargeType->name,
            'description' => $chargeType->description,
            'is_active' => $chargeType->is_active,
        ]);
    }

    private function syncLegacyFacilityRate(ChargeTypeRate $rate): void
    {
        $chargeType = $rate->chargeType;

        // A rate can only meaningfully exist under a walk-in-payable
        // charge type (the admin form only ever offers "add a rate" for
        // one of those) — syncLegacyFacility() above guarantees
        // legacy_facility_id is set by the time this runs.
        if ($chargeType->legacy_facility_id === null) {
            return;
        }

        if ($rate->legacy_facility_rate_id === null) {
            $facilityRate = FacilityRate::create([
                'facility_id' => $chargeType->legacy_facility_id,
                'name' => $rate->name,
                'amount' => $rate->amount,
                'is_active' => $rate->is_active,
            ]);

            $rate->update(['legacy_facility_rate_id' => $facilityRate->id]);

            return;
        }

        FacilityRate::where('id', $rate->legacy_facility_rate_id)->update([
            'name' => $rate->name,
            'amount' => $rate->amount,
            'is_active' => $rate->is_active,
        ]);
    }

    /**
     * `CODE-XXXX` derived from the name, mirroring the old
     * Facility::generateCode() exactly (kept human-recognisable, always
     * unique, never manually typed).
     */
    private function generateCode(string $name): string
    {
        $base = strtoupper(Str::slug($name, '_'));
        $base = substr($base, 0, 24) ?: 'CHARGE';

        do {
            $code = $base.'_'.strtolower(Str::random(6));
        } while (ChargeType::where('code', $code)->exists());

        return $code;
    }

    private function generateFacilityCode(string $name): string
    {
        $base = strtoupper(Str::slug($name, ''));
        $base = substr($base, 0, 12) ?: 'FAC';

        do {
            $code = $base.'-'.strtoupper(Str::random(4));
        } while (Facility::where('code', $code)->exists());

        return $code;
    }
}
