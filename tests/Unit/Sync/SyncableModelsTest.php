<?php

namespace Tests\Unit\Sync;

use App\Models\Setting;
use App\Services\Sync\SyncableModels;
use Tests\TestCase;

/**
 * Phase 9.7.3 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan).
 */
class SyncableModelsTest extends TestCase
{
    public function test_it_has_exactly_48_entries_matching_the_phase_9_7_1_migration(): void
    {
        $this->assertCount(48, SyncableModels::ALL);
    }

    public function test_every_entry_is_a_real_unique_class(): void
    {
        $this->assertSame(count(SyncableModels::ALL), count(array_unique(SyncableModels::ALL)));
        foreach (SyncableModels::ALL as $class) {
            $this->assertTrue(class_exists($class), "{$class} does not exist.");
        }
    }

    public function test_setting_is_not_in_the_list(): void
    {
        $this->assertNotContains(Setting::class, SyncableModels::ALL);
    }

    public function test_is_syncable_matches_the_list(): void
    {
        $this->assertTrue(SyncableModels::isSyncable(\App\Models\Guest::class));
        $this->assertFalse(SyncableModels::isSyncable(Setting::class));
        $this->assertFalse(SyncableModels::isSyncable('App\\Models\\DoesNotExist'));
    }
}
