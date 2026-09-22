<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Sync\Concerns\HasSyncUlid;

class Department extends Model
{
    use HasSyncUlid;
    protected $fillable = ['name', 'code', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * The `departments.code` a POS `outlet` route-segment belongs to —
     * shared by PaymentLedgerService::resolveDepartmentId() (cash
     * attribution) and ShiftGateService (the "no transaction without an
     * open shift" rule, PHASE9_AUDIT_AND_PLAN.md §21) so the mapping
     * only lives in one place. 'front_desk' -> 'FO' and 'bar_outside' ->
     * 'BAROUT' are the two irregular cases (see DepartmentSeeder); every
     * other outlet is just its name, uppercased with underscores
     * stripped.
     */
    public static function codeForOutlet(string $outlet): string
    {
        return match ($outlet) {
            'bar' => 'BAR',
            'restaurant' => 'REST',
            'front_desk' => 'FO',
            'bar_outside' => 'BAROUT',
            'bucareaux' => 'BUCA',
            default => strtoupper(str_replace('_', '', $outlet)),
        };
    }
}
