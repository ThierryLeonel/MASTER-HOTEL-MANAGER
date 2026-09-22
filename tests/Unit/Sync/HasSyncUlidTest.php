<?php

namespace Tests\Unit\Sync;

use App\Models\Department;
use App\Models\Guest;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Setting;
use App\Services\Sync\Concerns\HasSyncUlid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9.7.1 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan). Exercises
 * the trait directly against two real, differently-shaped syncable models
 * (Guest has no other traits beyond HasFactory/SoftDeletes; Room has
 * HasFactory only) rather than a throwaway test model, so this proves the
 * migration + trait combination actually works against this project's own
 * schema, not just the trait in isolation.
 */
class HasSyncUlidTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_ulid_is_auto_assigned_on_creation(): void
    {
        $guest = Guest::factory()->create();

        $this->assertNotNull($guest->ulid);
        $this->assertSame(26, strlen($guest->ulid));
        // Crockford's base32 alphabet: 0-9 and A-Z minus I, L, O, U.
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $guest->ulid);
    }

    public function test_two_records_get_different_ulids(): void
    {
        $first = Guest::factory()->create();
        $second = Guest::factory()->create();

        $this->assertNotSame($first->ulid, $second->ulid);
    }

    public function test_it_works_on_a_second_unrelated_syncable_model(): void
    {
        $category = RoomCategory::factory()->create();
        $room = Room::factory()->create(['room_category_id' => $category->id]);

        $this->assertNotNull($room->ulid);
        $this->assertSame(26, strlen($room->ulid));
    }

    public function test_an_explicitly_set_ulid_is_preserved_not_overwritten(): void
    {
        // This is the case the sync engine's apply step (a later Phase 9.7
        // sub-phase) depends on: replaying a row created on the OTHER
        // installation must keep the ulid it was created with there, not
        // be silently assigned a new, conflicting one here. Since `ulid`
        // is deliberately never fillable (see the next test), the apply
        // step — like this test — sets it via direct property assignment
        // before save(), exactly the way `create(['ulid' => ...])` could
        // NOT do (that would be silently dropped by mass-assignment
        // protection and this test would then fail, which is the point of
        // keeping the two tests next to each other).
        $incomingUlid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        $department = new Department([
            'name' => 'Housekeeping',
            'code' => 'HSK',
            'is_active' => true,
        ]);
        $department->ulid = $incomingUlid;
        $department->save();

        $this->assertSame($incomingUlid, $department->ulid);
    }

    public function test_ulid_is_never_mass_assignable(): void
    {
        // 'ulid' must never appear in a syncable model's $fillable — it is
        // system-generated only. This guards against a future edit
        // accidentally adding it and reopening the mass-assignment risk
        // the trait's docblock explicitly calls out.
        $department = new Department();

        $this->assertNotContains('ulid', $department->getFillable());
    }

    public function test_where_ulid_scope_finds_the_right_row(): void
    {
        $target = Guest::factory()->create();
        Guest::factory()->create();

        $found = Guest::whereUlid($target->ulid)->first();

        $this->assertNotNull($found);
        $this->assertTrue($found->is($target));
    }

    public function test_the_settings_model_is_deliberately_not_syncable(): void
    {
        // Phase 9.7.1's scoping decision (PHASE9_AUDIT_AND_PLAN.md §6):
        // Setting is the one model excluded from this rollout.
        $this->assertNotContains(HasSyncUlid::class, class_uses(Setting::class));
    }
}
