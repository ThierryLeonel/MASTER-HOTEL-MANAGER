<?php

namespace App\Models;

use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Database\Eloquent\Model;

/**
 * Direct request (Sept 2026): "normally we have bar charge, restaurant
 * charge, frontdesk charge, rental charge, pool fee, Gym Fee, etc
 * include all connect them... just build it in such a way i can edit
 * and or just add or delete." See this model's migration
 * (2025_02_02_001100_create_charge_types_table.php) for the full
 * reasoning — one unified, admin-editable list replacing both the old
 * hardcoded `FolioTransaction::CHARGE_TYPES` constant and the separate
 * Facility/FacilityRate (Gym & Pool) configuration.
 */
class ChargeType extends Model
{
    use HasSyncUlid;

    protected $fillable = [
        'code', 'name', 'description', 'department_id',
        'postable_to_folio', 'walk_in_payable', 'free_for_resident_guests',
        'is_system', 'is_active', 'legacy_facility_id',
    ];

    protected function casts(): array
    {
        return [
            'postable_to_folio' => 'boolean',
            'walk_in_payable' => 'boolean',
            'free_for_resident_guests' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function rates()
    {
        return $this->hasMany(ChargeTypeRate::class);
    }

    /**
     * Purely internal plumbing — see this model's migration docblock.
     * Never surfaced in the admin UI; only consulted by
     * FacilityAccessService when this charge type is walk-in-payable, to
     * satisfy the pre-existing, already-tested `facility_accesses`
     * schema underneath the new unified screen.
     */
    public function legacyFacility()
    {
        return $this->belongsTo(Facility::class, 'legacy_facility_id');
    }

    /**
     * A code editable in the admin UI only for a non-system charge type —
     * see ChargeTypeService::update()'s enforcement of this at the write
     * layer; this is the read-side convenience for views.
     */
    public function codeIsEditable(): bool
    {
        return ! $this->is_system;
    }
}
