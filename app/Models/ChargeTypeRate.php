<?php

namespace App\Models;

use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Database\Eloquent\Model;

/**
 * A named, priced option under a walk-in-payable ChargeType — e.g. Gym's
 * "Single visit" vs "Monthly pass." Mirrors the old FacilityRate exactly
 * (see this table's migration); see ChargeType's own docblock for the
 * full reasoning behind the unification.
 */
class ChargeTypeRate extends Model
{
    use HasSyncUlid;

    protected $fillable = ['charge_type_id', 'name', 'amount', 'is_active', 'legacy_facility_rate_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function chargeType()
    {
        return $this->belongsTo(ChargeType::class);
    }

    /**
     * Purely internal plumbing — see ChargeType::legacyFacility()'s
     * docblock for the full reasoning.
     */
    public function legacyFacilityRate()
    {
        return $this->belongsTo(FacilityRate::class, 'legacy_facility_rate_id');
    }
}
