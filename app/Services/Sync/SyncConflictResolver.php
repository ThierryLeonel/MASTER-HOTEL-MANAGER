<?php

namespace App\Services\Sync;

use App\Exceptions\HmsException;
use App\Models\SyncConflict;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.7.6 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). The
 * only writer of `sync_conflicts.resolved_*` — resolving a conflict is
 * always a human decision made through the sync dashboard
 * (App\Http\Controllers\Admin\SyncDashboardController), never automatic,
 * per the `sync_conflicts` migration's own docblock: "Resolution... is a
 * human/admin action against this table, not an automatic one."
 *
 * Four resolutions, exactly matching `sync_conflicts.resolution`'s enum:
 *  - `kept_local`   — the local row already reflects this choice (the
 *    original conflict-detecting apply in SyncService::applyUpsert()
 *    never touched it in the first place) — no data change, just marks
 *    the record resolved.
 *  - `kept_incoming` — the ONLY resolution that mutates data: applies
 *    the `incoming_data` that was held back at detection time onto the
 *    local row now, the same forceFill()-and-save() SyncService itself
 *    uses for a normal (non-conflicting) upsert.
 *  - `merged` — a human has already reconciled the two versions by hand
 *    outside this flow (e.g. editing the record directly); nothing here
 *    attempts an automatic field-by-field merge, since guessing at one
 *    could produce a value neither side ever actually had.
 *  - `ignored` — deliberately leaving both recorded versions as history
 *    without changing anything, e.g. a conflict on a row that no longer
 *    matters.
 */
class SyncConflictResolver
{
    public const RESOLUTIONS = ['kept_local', 'kept_incoming', 'merged', 'ignored'];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function resolve(SyncConflict $conflict, string $resolution, User $actor, ?string $notes = null): SyncConflict
    {
        if ($conflict->isResolved()) {
            throw new HmsException('This conflict has already been resolved.');
        }

        if (! in_array($resolution, self::RESOLUTIONS, true)) {
            throw new HmsException("Unknown resolution: {$resolution}");
        }

        return DB::transaction(function () use ($conflict, $resolution, $actor, $notes) {
            if ($resolution === 'kept_incoming') {
                $this->applyIncoming($conflict);
            }

            $conflict->update([
                'resolved_at' => now(),
                'resolved_by' => $actor->id,
                'resolution' => $resolution,
                'notes' => $notes,
            ]);

            $this->audit->log('sync.conflict_resolved', 'sync', $conflict, null, $conflict->fresh()->toArray(), $notes, $actor);

            return $conflict->fresh();
        });
    }

    /**
     * Deliberately NOT re-run through SyncService::applyUpsert()'s own
     * conflict check: resolving `kept_incoming` IS the human overriding
     * that automatic hold-back decision, so re-checking it here would
     * just re-detect the exact same conflict and refuse to ever apply
     * anything. The excluded-attribute list matches applyUpsert()'s own
     * exactly, for the same reasons documented there (never copy the
     * local auto-increment id, ulid is handled separately, updated_at is
     * left to Eloquent so future conflict detection stays meaningful) —
     * plus, since the security re-audit (PHASE9_AUDIT_AND_PLAN.md §9.8),
     * SyncableModels::protectedAttributesFor() too: `incoming_data` is
     * already scrubbed of credential columns at the point a conflict is
     * recorded (exportableAttributes() strips them before they're ever
     * written to the row), but excluding them again here as well costs
     * nothing and keeps this method's own guarantee self-contained rather
     * than relying on a caller elsewhere having already done it.
     */
    private function applyIncoming(SyncConflict $conflict): void
    {
        $type = $conflict->syncable_type;

        if (! SyncableModels::isSyncable($type)) {
            throw new HmsException("'{$type}' is not (or is no longer) a recognized syncable model — cannot apply its incoming data.");
        }

        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($type), true);
        $local = $usesSoftDeletes
            ? $type::withTrashed()->whereUlid($conflict->syncable_ulid)->first()
            : $type::whereUlid($conflict->syncable_ulid)->first();

        if (! $local) {
            throw new HmsException("The local {$type} record (ulid {$conflict->syncable_ulid}) no longer exists — cannot apply the incoming data over a row that isn't there.");
        }

        $attributes = collect($conflict->incoming_data)
            ->except(['id', 'ulid', 'created_at', 'updated_at', ...SyncableModels::protectedAttributesFor($type)])
            ->toArray();
        $local->forceFill($attributes)->save();
    }
}
