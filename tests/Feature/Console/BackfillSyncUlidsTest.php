<?php

namespace Tests\Feature\Console;

use App\Models\Guest;
use App\Models\Room;
use App\Models\RoomCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 9.7.3 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). Rows
 * inserted through Eloquent always get a ulid from HasSyncUlid, so
 * "legacy, pre-Phase-9.7.1 data" is simulated the only way it honestly
 * can be from inside a test: inserting directly via the query builder,
 * bypassing model events entirely, the same way real historical rows
 * from before that migration ran actually got created.
 */
class BackfillSyncUlidsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_assigns_a_ulid_to_every_row_that_is_missing_one(): void
    {
        DB::table('guests')->insert([
            'first_name' => 'Legacy', 'last_name' => 'Guest', 'is_blacklisted' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $category = RoomCategory::factory()->create();
        DB::table('rooms')->insert([
            'room_number' => '101', 'room_category_id' => $category->id, 'floor' => 1,
            'status' => 'available', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull(Guest::first()->ulid);
        $this->assertNull(Room::first()->ulid);

        $this->artisan('sync:backfill-ulids')->assertSuccessful();

        $this->assertNotNull(Guest::first()->fresh()->ulid);
        $this->assertNotNull(Room::first()->fresh()->ulid);
        $this->assertSame(26, strlen(Guest::first()->ulid));
    }

    public function test_it_does_not_touch_rows_that_already_have_a_ulid(): void
    {
        $guest = Guest::factory()->create();
        $originalUlid = $guest->ulid;

        $this->artisan('sync:backfill-ulids')->assertSuccessful();

        $this->assertSame($originalUlid, $guest->fresh()->ulid);
    }

    public function test_it_is_safe_to_run_twice(): void
    {
        DB::table('guests')->insert([
            'first_name' => 'Legacy', 'last_name' => 'Guest', 'is_blacklisted' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('sync:backfill-ulids')->assertSuccessful();
        $firstRunUlid = Guest::first()->ulid;

        $this->artisan('sync:backfill-ulids')->assertSuccessful();

        $this->assertSame($firstRunUlid, Guest::first()->ulid);
    }
}
