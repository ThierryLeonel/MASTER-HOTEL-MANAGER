<?php

namespace App\Services\Sync\Concerns;

use Illuminate\Support\Str;

/**
 * Phase 9.7.1 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). Applied to
 * every syncable model (see the `ulid` column migration's own docblock for
 * exactly which 48 models/tables that is and why).
 *
 * Deliberately NOT a mass-assignable attribute: `ulid` never appears in any
 * model's `$fillable`, and this trait assigns it directly on the model
 * instance in a `creating` listener rather than expecting it in the
 * attributes array passed to `create()`/`fill()`. A globally-unique sync
 * identifier is not something a controller, a form request, or (later) an
 * incoming sync payload should ever be able to overwrite by simply
 * including a `ulid` key — it is system-generated, once, by whichever side
 * first creates the row. If a value is already set before the `creating`
 * event fires (the one legitimate case: the sync engine's own apply step,
 * built in a later sub-phase, replaying a row that was assigned a ulid on
 * the OTHER installation), that value is preserved rather than overwritten,
 * so applying an incoming synced row keeps the identifier it was created
 * with on its origin device instead of minting a second, conflicting one.
 *
 * A ULID (Str::ulid()) rather than a UUID was chosen for the same two
 * reasons Laravel's own `HasUlids` concern exists: it is lexicographically
 * sortable by creation time (useful for outbox ordering/debugging without
 * a separate sequence column) and it is a fixed 26-character string, a
 * cheaper index than a 36-character UUID with hyphens.
 */
trait HasSyncUlid
{
    protected static function bootHasSyncUlid(): void
    {
        static::creating(function ($model): void {
            if (empty($model->ulid)) {
                $model->ulid = (string) Str::ulid();
            }
        });
    }

    /**
     * Query scope for looking a row up by its sync identifier instead of
     * its local auto-increment id — what the sync engine's apply step
     * (built in a later Phase 9.7 sub-phase) uses to find "is this row
     * already here" before deciding whether to insert or update it.
     */
    public function scopeWhereUlid($query, string $ulid)
    {
        return $query->where('ulid', $ulid);
    }
}
