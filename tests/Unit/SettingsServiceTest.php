<?php

namespace Tests\Unit;

use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_falls_back_to_default_when_setting_is_absent(): void
    {
        $settings = app(SettingsService::class);

        $this->assertSame(0.0, $settings->taxRatePercent());
        $this->assertSame(15, $settings->sessionTimeoutMinutes());
    }

    public function test_set_and_get_round_trip_for_decimal_type(): void
    {
        $settings = app(SettingsService::class);

        $settings->set('tax_rate_percent', '19.25');
        \App\Models\Setting::where('key', 'tax_rate_percent')->update(['type' => 'decimal']);

        // Fresh instance to bypass in-memory request cache.
        $fresh = app(SettingsService::class);
        $this->assertEquals(19.25, $fresh->get('tax_rate_percent'));
    }

    public function test_business_policy_values_are_not_hardcoded_but_read_from_storage(): void
    {
        \App\Models\Setting::create([
            'key' => 'expense_approval_threshold',
            'group' => 'finance',
            'value' => '75000',
            'type' => 'decimal',
        ]);

        $settings = app(SettingsService::class);
        $this->assertSame(75000.0, $settings->expenseApprovalThreshold());
    }
}
