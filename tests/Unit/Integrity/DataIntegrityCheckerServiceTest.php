<?php

namespace Tests\Unit\Integrity;

use App\Models\AccountsReceivable;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Department;
use App\Models\Room;
use App\Models\Stay;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\SyncBatch;
use App\Models\SyncConflict;
use App\Models\SyncDevice;
use App\Models\SyncItem;
use App\Models\User;
use App\Services\Integrity\DataIntegrityCheckerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9.6 (PHASE9_AUDIT_AND_PLAN.md §3.7). Each test corrupts exactly
 * one invariant and confirms the checker's matching check — and only
 * that check — fails, then confirms a freshly seeded/empty database
 * passes every check with zero issues. This mirrors the service's own
 * "never a duplicated total" principle: these tests assert against the
 * checker's own reused methods (Folio::recomputeBalance(),
 * PurchaseOrder::isFullyReceived()), not a second hand-rolled
 * calculation of the same thing.
 */
class DataIntegrityCheckerServiceTest extends TestCase
{
    use RefreshDatabase;

    private function checker(): DataIntegrityCheckerService
    {
        return app(DataIntegrityCheckerService::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function resultFor(array $results, string $key): array
    {
        foreach ($results as $result) {
            if ($result['key'] === $key) {
                return $result;
            }
        }

        $this->fail("No result with key '{$key}' was returned by the checker.");
    }

    public function test_an_empty_database_passes_every_check(): void
    {
        $results = $this->checker()->run();

        $this->assertCount(8, $results);
        $this->assertFalse($this->checker()->hasFailures($results));
        foreach ($results as $result) {
            $this->assertTrue($result['passed'], "Expected '{$result['key']}' to pass on an empty database.");
            $this->assertSame(0, $result['issue_count']);
            $this->assertSame([], $result['issues']);
        }
    }

    public function test_it_detects_a_folio_balance_that_has_drifted_from_its_ledger(): void
    {
        $folio = Folio::factory()->create(['balance' => 0]);

        FolioTransaction::create([
            'folio_id' => $folio->id,
            'type' => 'room_charge',
            'amount' => 15000,
            'description' => 'Room charge',
            'source_module' => 'front_office',
            'transaction_date' => now(),
        ]);

        // Deliberately leave the denormalized balance column at 0 instead
        // of updating it to 15000, so it drifts from the ledger total.
        $folio->refresh();
        $this->assertSame('0.00', (string) $folio->balance);

        $results = $this->checker()->run();

        $result = $this->resultFor($results, 'folio_balances');
        $this->assertFalse($result['passed']);
        $this->assertSame(1, $result['issue_count']);
        $this->assertStringContainsString((string) $folio->id, $result['issues'][0]);

        // No other check should be affected by this one corruption.
        $this->assertTrue($this->resultFor($results, 'ar_ties_to_folio')['passed']);
    }

    public function test_it_detects_an_accounts_receivable_row_pointing_at_a_nonexistent_folio(): void
    {
        $folio = Folio::factory()->create();
        $creator = User::factory()->create();

        $ar = AccountsReceivable::create([
            'folio_id' => $folio->id,
            'guest_id' => $folio->stay->guest_id,
            'original_amount' => 10000,
            'amount_outstanding' => 10000,
            'status' => 'open',
            'due_date' => now()->addDays(30),
            'created_by' => $creator->id,
        ]);

        // Delete the folio out from under the AR row without touching the
        // AR row itself, so it now references a folio_id that is gone.
        $folio->delete();

        $results = $this->checker()->run();

        $result = $this->resultFor($results, 'ar_ties_to_folio');
        $this->assertFalse($result['passed']);
        $this->assertSame(1, $result['issue_count']);
        $this->assertStringContainsString((string) $ar->id, $result['issues'][0]);
    }

    public function test_it_detects_an_accounts_receivable_row_owing_more_than_it_originally_did(): void
    {
        $folio = Folio::factory()->create();
        $creator = User::factory()->create();

        $ar = AccountsReceivable::create([
            'folio_id' => $folio->id,
            'guest_id' => $folio->stay->guest_id,
            'original_amount' => 10000,
            'amount_outstanding' => 12000,
            'status' => 'open',
            'due_date' => now()->addDays(30),
            'created_by' => $creator->id,
        ]);

        $results = $this->checker()->run();

        $result = $this->resultFor($results, 'ar_outstanding_within_original');
        $this->assertFalse($result['passed']);
        $this->assertSame(1, $result['issue_count']);
        $this->assertStringContainsString((string) $ar->id, $result['issues'][0]);
    }

    public function test_it_detects_a_stay_with_no_folio(): void
    {
        $stay = Stay::factory()->create(['status' => 'active']);

        $results = $this->checker()->run();

        $result = $this->resultFor($results, 'stays_have_folio');
        $this->assertFalse($result['passed']);
        $this->assertSame(1, $result['issue_count']);
        $this->assertStringContainsString((string) $stay->id, $result['issues'][0]);
    }

    public function test_it_detects_a_room_marked_occupied_with_no_active_stay(): void
    {
        $room = Room::factory()->occupied()->create();

        $results = $this->checker()->run();

        $result = $this->resultFor($results, 'room_occupancy_matches_stays');
        $this->assertFalse($result['passed']);
        $this->assertSame(1, $result['issue_count']);
        $this->assertStringContainsString($room->room_number, $result['issues'][0]);
    }

    public function test_it_detects_an_active_stay_whose_room_is_not_marked_occupied(): void
    {
        // The room is left at its factory default status of 'available'
        // while a Stay claims to be actively using it — the inverse
        // mismatch from the previous test.
        $room = Room::factory()->create(['status' => 'available']);
        $stay = Stay::factory()->create(['status' => 'active', 'room_id' => $room->id]);

        $results = $this->checker()->run();

        $result = $this->resultFor($results, 'room_occupancy_matches_stays');
        $this->assertFalse($result['passed']);
        $this->assertSame(1, $result['issue_count']);
        $this->assertStringContainsString((string) $stay->id, $result['issues'][0]);
    }

    public function test_it_detects_a_negative_stock_batch(): void
    {
        // StockBatch has no dedicated factory (only PurchaseOrder/StockBatch
        // model classes opted into HasFactory; no matching factory file was
        // ever created), so it is built directly with ::create(), the same
        // way app/Services/Inventory/StockService.php itself creates one.
        $batch = StockBatch::create([
            'product_id' => \App\Models\Product::factory()->create()->id,
            'quantity_received' => 20,
            'quantity_remaining' => -5,
            'unit_cost' => 500,
            'received_at' => now(),
        ]);

        $results = $this->checker()->run();

        $result = $this->resultFor($results, 'no_negative_stock');
        $this->assertFalse($result['passed']);
        $this->assertSame(1, $result['issue_count']);
        $this->assertStringContainsString((string) $batch->id, $result['issues'][0]);
    }

    public function test_it_detects_a_purchase_order_marked_received_that_is_not_actually_fully_received(): void
    {
        $supplier = Supplier::factory()->create();
        $orderedBy = User::factory()->create();

        $po = PurchaseOrder::create([
            'po_number' => 'PO-INTEGRITY-1',
            'supplier_id' => $supplier->id,
            'status' => 'received',
            'ordered_by' => $orderedBy->id,
            'submitted_at' => now(),
            'approved_at' => now(),
            'received_at' => now(),
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => \App\Models\Product::factory()->create()->id,
            'quantity_ordered' => 10,
            'quantity_received' => 6,
            'unit_cost' => 100,
            'line_total' => 1000,
        ]);

        $results = $this->checker()->run();

        $result = $this->resultFor($results, 'received_pos_fully_received');
        $this->assertFalse($result['passed']);
        $this->assertSame(1, $result['issue_count']);
        $this->assertStringContainsString($po->po_number, $result['issues'][0]);
    }

    private function conflict(array $overrides = []): SyncConflict
    {
        $device = SyncDevice::create(['name' => 'Cloud VPS', 'device_key' => (string) Str::ulid(), 'shared_secret' => 'x', 'is_active' => true]);
        $batch = SyncBatch::create([
            'ulid' => (string) Str::ulid(), 'sync_device_id' => $device->id, 'direction' => 'inbound',
            'status' => 'failed', 'item_count' => 1, 'payload' => [],
        ]);
        $item = SyncItem::create([
            'sync_batch_id' => $batch->id, 'syncable_type' => Department::class,
            'syncable_ulid' => (string) Str::ulid(), 'operation' => 'update', 'status' => 'conflict',
        ]);

        return SyncConflict::create(array_merge([
            'sync_item_id' => $item->id,
            'syncable_type' => Department::class,
            'syncable_ulid' => $item->syncable_ulid,
            'local_data' => ['name' => 'Local version'],
            'incoming_data' => ['name' => 'Incoming version'],
            'detected_at' => now()->subDays(10),
        ], $overrides));
    }

    public function test_it_detects_a_sync_conflict_unresolved_for_more_than_a_week(): void
    {
        $stale = $this->conflict(['detected_at' => now()->subDays(10)]);

        $results = $this->checker()->run();

        $result = $this->resultFor($results, 'no_stale_sync_conflicts');
        $this->assertFalse($result['passed']);
        $this->assertSame(1, $result['issue_count']);
        $this->assertStringContainsString((string) $stale->id, $result['issues'][0]);
    }

    public function test_a_recently_detected_or_already_resolved_conflict_does_not_count_as_stale(): void
    {
        // Detected only 2 days ago — still within the 7-day grace window.
        $this->conflict(['detected_at' => now()->subDays(2)]);
        // Detected 30 days ago, but already resolved — no longer "sitting
        // unresolved," so it must not be flagged either.
        $this->conflict(['detected_at' => now()->subDays(30), 'resolved_at' => now(), 'resolution' => 'kept_local']);

        $results = $this->checker()->run();

        $result = $this->resultFor($results, 'no_stale_sync_conflicts');
        $this->assertTrue($result['passed']);
        $this->assertSame(0, $result['issue_count']);
    }
}
