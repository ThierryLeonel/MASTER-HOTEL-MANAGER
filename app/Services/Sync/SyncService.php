<?php

namespace App\Services\Sync;

use App\Models\SyncBatch;
use App\Models\SyncCheckpoint;
use App\Models\SyncConflict;
use App\Models\SyncDevice;
use App\Models\SyncItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Phase 9.7.3 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). The
 * engine itself: turns "rows changed since last time" into an outbound
 * batch, and turns an inbound batch into applied rows — idempotently, and
 * without ever silently overwriting a row the other side didn't know
 * about when it made its own change.
 *
 * This class does not talk to the network. Phase 9.7.4 (the transport)
 * calls buildOutboxBatch()/acknowledgeOutboxBatch() on the sending side
 * and applyIncomingBatch() on the receiving side; everything in between —
 * actually moving bytes between the local server and the cloud VPS — is
 * deliberately out of scope here.
 */
class SyncService
{
    public function __construct(private readonly SyncLogger $logger)
    {
    }

    /**
     * Scans every syncable table (App\Services\Sync\SyncableModels::ALL —
     * the same 48 tables Phase 9.7.1 gave a `ulid` column) for rows
     * changed since this device's last successfully-sent outbound
     * checkpoint, and packages them into a new pending SyncBatch.
     *
     * Deliberately skips any row whose `ulid` is still NULL — a row
     * created before Phase 9.7.1's migration ran, and not yet fixed by
     * `php artisan sync:backfill-ulids` (Phase 9.7.3). This is a
     * conscious choice, not a silent gap: syncing a row with no stable
     * identity would mean minting a fresh ulid for it right here, which
     * would make it a DIFFERENT record from the other installation's
     * point of view the next time IT independently decided to sync that
     * same pre-existing row. Skipping it and requiring the backfill
     * command to run first keeps identity assignment in exactly one
     * place (HasSyncUlid on create, or the backfill command for
     * pre-existing data) instead of two.
     */
    public function buildOutboxBatch(SyncDevice $device): SyncBatch
    {
        $checkpoint = $device->checkpointFor('outbound')
            ?? SyncCheckpoint::create(['sync_device_id' => $device->id, 'direction' => 'outbound']);

        $since = $checkpoint->last_synced_at;
        $items = [];

        foreach (SyncableModels::ALL as $class) {
            /** @var class-string<Model> $class */
            $query = $class::query();

            if ($this->usesSoftDeletes($class)) {
                $query->withTrashed();
            }

            $query->whereNotNull('ulid');
            if ($since) {
                $query->where('updated_at', '>', $since);
            }

            foreach ($query->orderBy('updated_at')->cursor() as $row) {
                $items[] = [
                    'type' => $class,
                    'ulid' => $row->ulid,
                    'operation' => $this->isTrashed($row) ? 'delete' : 'update',
                    'data' => $this->exportableAttributes($row),
                ];
            }
        }

        $batch = SyncBatch::create([
            'ulid' => (string) Str::ulid(),
            'sync_device_id' => $device->id,
            'direction' => 'outbound',
            'status' => 'pending',
            'item_count' => count($items),
            'payload' => $items,
        ]);

        foreach ($items as $item) {
            SyncItem::create([
                'sync_batch_id' => $batch->id,
                'syncable_type' => $item['type'],
                'syncable_ulid' => $item['ulid'],
                'operation' => $item['operation'],
                'status' => 'pending',
            ]);
        }

        return $batch->fresh();
    }

    /**
     * Called once the (Phase 9.7.4) transport confirms the other side
     * has the batch. Marks every item applied, advances the outbound
     * checkpoint, and logs it. Deliberately a separate step from
     * buildOutboxBatch() rather than assuming "built" means "delivered" —
     * a batch that was built but never successfully sent must not
     * advance the checkpoint, or the rows in it would never be retried.
     */
    public function acknowledgeOutboxBatch(SyncBatch $batch): SyncBatch
    {
        $batch->update(['status' => 'applied', 'applied_at' => now()]);
        $batch->items()->update(['status' => 'applied']);

        $device = $batch->device;
        $checkpoint = $device->checkpointFor('outbound')
            ?? SyncCheckpoint::create(['sync_device_id' => $device->id, 'direction' => 'outbound']);
        $checkpoint->update(['last_synced_at' => now()]);

        $this->logger->info('batch_sent', "Sent and acknowledged {$batch->item_count} item(s).", $device);

        return $batch->fresh();
    }

    /**
     * Applies a batch received from another device. Safe to call twice
     * with the same $batchUlid: if a batch with that ulid was already
     * recorded as applied for this device+direction, it is returned
     * as-is without touching anything a second time — the idempotency
     * §3.1 explicitly requires ("replaying a batch twice is safe").
     *
     * @param  list<array{type: string, ulid: string, operation: string, data: array<string, mixed>}>  $items
     */
    public function applyIncomingBatch(SyncDevice $fromDevice, string $batchUlid, array $items): SyncBatch
    {
        $existing = SyncBatch::where('ulid', $batchUlid)->where('direction', 'inbound')->first();
        if ($existing && $existing->isApplied()) {
            $this->logger->info('batch_replayed', "Batch {$batchUlid} was already applied; skipped re-applying it.", $fromDevice);

            return $existing;
        }

        $batch = $existing ?? SyncBatch::create([
            'ulid' => $batchUlid,
            'sync_device_id' => $fromDevice->id,
            'direction' => 'inbound',
            'status' => 'pending',
            'item_count' => count($items),
            'payload' => $items,
        ]);

        $checkpoint = $fromDevice->checkpointFor('inbound')
            ?? SyncCheckpoint::create(['sync_device_id' => $fromDevice->id, 'direction' => 'inbound']);

        foreach ($items as $item) {
            // Phase 9.7.4: applyItem() is typed to expect an array shape,
            // which is guaranteed when this is called from SyncServiceTest
            // directly, but no longer once the transport controller passes
            // through whatever JSON an authenticated-but-untrusted caller
            // sent. A malformed entry (a bare string, a number) is recorded
            // as its own failed item rather than throwing a TypeError that
            // would abort every other item still in the same batch.
            if (! is_array($item)) {
                SyncItem::create([
                    'sync_batch_id' => $batch->id,
                    'syncable_type' => 'unknown',
                    'syncable_ulid' => 'unknown',
                    'operation' => 'update',
                    'status' => 'failed',
                    'error_message' => 'Malformed item: expected an object with type/ulid/operation/data.',
                ]);
                $this->logger->error('item_failed', 'Malformed item received (not an object).', $fromDevice);

                continue;
            }

            $this->applyItem($batch, $item, $checkpoint, $fromDevice);
        }

        $failed = $batch->items()->where('status', 'failed')->exists();
        $batch->update(['status' => $failed ? 'failed' : 'applied', 'applied_at' => now()]);
        // The checkpoint still advances even if some individual items
        // failed or conflicted: both outcomes are permanently recorded
        // (sync_items.status, sync_conflicts) and re-scanning the same
        // window on a future sync would not resolve them differently —
        // the batch-ulid idempotency check above already prevents this
        // exact batch from being reprocessed regardless.
        $checkpoint->update(['last_synced_at' => now()]);

        $this->logger->info('batch_received', "Applied {$batch->item_count} item(s) from {$fromDevice->name}.", $fromDevice);

        return $batch->fresh();
    }

    /**
     * @param  array{type: string, ulid: string, operation: string, data: array<string, mixed>}  $itemPayload
     */
    private function applyItem(SyncBatch $batch, array $itemPayload, SyncCheckpoint $inboundCheckpoint, SyncDevice $fromDevice): SyncItem
    {
        $type = $itemPayload['type'] ?? null;
        $ulid = $itemPayload['ulid'] ?? null;
        $operation = $itemPayload['operation'] ?? 'update';
        $data = $itemPayload['data'] ?? [];

        $syncItem = SyncItem::create([
            'sync_batch_id' => $batch->id,
            'syncable_type' => (string) $type,
            'syncable_ulid' => (string) $ulid,
            'operation' => in_array($operation, ['create', 'update', 'delete'], true) ? $operation : 'update',
            'status' => 'pending',
        ]);

        try {
            if (! $type || ! SyncableModels::isSyncable($type)) {
                throw new \RuntimeException("Unknown or non-syncable model type: ".var_export($type, true));
            }
            if (! $ulid) {
                throw new \RuntimeException('Incoming item is missing its syncable ulid.');
            }

            if ($operation === 'delete') {
                $this->applyDelete($type, $ulid, $syncItem);
            } else {
                $this->applyUpsert($type, $ulid, $data, $syncItem, $inboundCheckpoint, $fromDevice);
            }
        } catch (\Throwable $e) {
            $syncItem->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            $this->logger->error('item_failed', $e->getMessage(), $fromDevice, ['type' => $type, 'ulid' => $ulid]);
        }

        return $syncItem->fresh();
    }

    /**
     * The core conflict rule: if a local row with this ulid already
     * exists AND it was modified (its own `updated_at`) after the last
     * time we successfully finished processing a batch FROM this same
     * device, then something changed here that the sender never saw —
     * applying the incoming version now would silently throw that local
     * change away. So instead: record both versions in sync_conflicts,
     * mark this item `conflict` (not `applied`), and leave the local row
     * untouched. A human resolves it later (Phase 9.7.6's dashboard);
     * this method never guesses which side should win.
     */
    private function applyUpsert(string $type, string $ulid, array $data, SyncItem $syncItem, SyncCheckpoint $inboundCheckpoint, SyncDevice $fromDevice): void
    {
        /** @var Model|null $local */
        $local = $this->usesSoftDeletes($type)
            ? $type::withTrashed()->whereUlid($ulid)->first()
            : $type::whereUlid($ulid)->first();

        if ($local && $inboundCheckpoint->last_synced_at && $local->updated_at && $local->updated_at->gt($inboundCheckpoint->last_synced_at)) {
            SyncConflict::create([
                'sync_item_id' => $syncItem->id,
                'syncable_type' => $type,
                'syncable_ulid' => $ulid,
                'local_data' => $this->exportableAttributes($local),
                'incoming_data' => $data,
                'detected_at' => now(),
            ]);
            $syncItem->update(['status' => 'conflict']);
            $this->logger->warning('conflict_detected', "Conflict applying {$type} {$ulid}: local row changed since the last sync with this device.", $fromDevice);

            return;
        }

        // 'id' is the local auto-increment PK and must never be copied
        // across installations. 'ulid' is carried at the item level
        // already and is handled specially below. 'updated_at' is
        // deliberately left to Eloquent's own timestamp management
        // rather than copied from the sender: this is what keeps the
        // conflict check above meaningful (a row's updated_at reflects
        // "the last time THIS installation touched it," including via a
        // sync apply, so a genuine local edit after that point is always
        // detectable) and sidesteps clock-skew between two servers.
        //
        // Security re-audit finding (PHASE9_AUDIT_AND_PLAN.md §9.8,
        // fixed): SyncableModels::protectedAttributesFor() is also
        // excluded here, on the APPLY side, not only in
        // exportableAttributes() on the export side. The export-side fix
        // alone would only stop THIS installation's own legitimate
        // outbox from leaking credentials — it would do nothing against
        // an adversarial sender who already holds a valid (e.g. stolen)
        // device secret and crafts a push payload that explicitly
        // includes a 'password'/'pin_hash'/etc. key. Filtering here too
        // means the receiving side itself can never forceFill a
        // protected column via sync, regardless of what a counterpart —
        // trusted or compromised — actually sends.
        $attributes = collect($data)
            ->except(['id', 'ulid', 'updated_at', ...SyncableModels::protectedAttributesFor($type)])
            ->toArray();

        if ($local) {
            unset($attributes['created_at']); // never rewrite an existing row's true creation time
            $local->forceFill($attributes)->save();
        } else {
            $local = new $type();
            $local->forceFill($attributes);
            $local->ulid = $ulid; // preserved as-is by HasSyncUlid's "only if empty" rule
            $local->save();
        }

        $syncItem->update(['status' => 'applied']);
    }

    /**
     * Deletion is intentionally conservative. A SoftDeletes model (Guest,
     * User — see grep -l SoftDeletes app/Models/*.php) still has a row to
     * find and mark deleted_at on, so that propagates like any other
     * field change. Every other syncable model has no tombstone at all:
     * this sync design (per its own outbox scan, which can only see rows
     * that still exist) has no way to detect a hard delete on the
     * SENDING side in the first place, so buildOutboxBatch() never emits
     * a 'delete' operation for them — but if one is ever received anyway
     * (e.g. a future manual/administrative trigger), unconditionally
     * deleting a business/financial record via an automated sync process
     * is exactly the kind of silent data loss this whole engine exists
     * to avoid, so it is skipped with a clear reason instead.
     */
    private function applyDelete(string $type, string $ulid, SyncItem $syncItem): void
    {
        $local = $this->usesSoftDeletes($type)
            ? $type::withTrashed()->whereUlid($ulid)->first()
            : $type::whereUlid($ulid)->first();

        if (! $local) {
            $syncItem->update(['status' => 'applied']); // already gone (or never existed here) — idempotent no-op.

            return;
        }

        if ($this->usesSoftDeletes($type)) {
            if (! $local->trashed()) {
                $local->delete();
            }
            $syncItem->update(['status' => 'applied']);

            return;
        }

        $syncItem->update([
            'status' => 'skipped',
            'error_message' => "{$type} has no soft-delete support; this sync engine does not perform hard deletes of business records automatically.",
        ]);
    }

    private function usesSoftDeletes(string $class): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($class), true);
    }

    private function isTrashed(Model $row): bool
    {
        return method_exists($row, 'trashed') && $row->trashed();
    }

    /**
     * The raw, as-stored attribute values (not accessor-mutated output),
     * so a round trip through the outbox and back applies cleanly without
     * double-casting or losing precision on decimal columns.
     *
     * Security re-audit finding (PHASE9_AUDIT_AND_PLAN.md §9.8): this is the
     * single choke point every outbound sync payload AND every conflict
     * snapshot (`local_data`/`incoming_data`, see `applyUpsert()` above)
     * passes through — `getAttributes()` deliberately bypasses `$hidden`
     * (that's the whole point of using it instead of `toArray()`, so casts
     * round-trip exactly), which previously meant a `User` row's
     * `password`/`pin_hash` bcrypt hash and `totp_secret`/`totp_recovery_codes`
     * ciphertext went out on the wire and could sit in cleartext-JSON inside
     * `sync_conflicts`, visible to any `manage-sync` holder (Duty Manager
     * included) via `/admin/sync/conflicts`. Stripping
     * `SyncableModels::protectedAttributesFor()`'s columns here — rather
     * than only at the receiving end — means they are never transmitted or
     * stored in a conflict row in the first place, not merely hidden after
     * the fact from one screen.
     *
     * @return array<string, mixed>
     */
    private function exportableAttributes(Model $row): array
    {
        $attributes = $row->getAttributes();

        foreach (SyncableModels::protectedAttributesFor(get_class($row)) as $key) {
            unset($attributes[$key]);
        }

        return $attributes;
    }
}
