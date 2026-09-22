<?php

namespace Tests\Unit\Hr;

use App\Models\PayrollDeductionType;
use App\Services\Hr\PayrollDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollDeductionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_flat_percent_deduction_is_a_simple_percentage_of_gross(): void
    {
        PayrollDeductionType::create([
            'name' => 'Social Security', 'code' => 'SS', 'calculation_method' => 'flat_percent',
            'flat_rate_percent' => 10, 'is_active' => true, 'display_order' => 1,
        ]);

        $result = app(PayrollDeductionService::class)->calculate(240000);

        $this->assertSame(24000.0, $result['total']);
        $this->assertCount(1, $result['breakdown']);
    }

    public function test_progressive_brackets_only_tax_the_slice_within_each_band(): void
    {
        $type = PayrollDeductionType::create([
            'name' => 'Income Tax', 'code' => 'IRPP', 'calculation_method' => 'progressive_brackets',
            'is_active' => true, 'display_order' => 1,
        ]);
        $type->brackets()->createMany([
            ['min_amount' => 0, 'max_amount' => 200000, 'rate_percent' => 0, 'display_order' => 1],
            ['min_amount' => 200000, 'max_amount' => 500000, 'rate_percent' => 10, 'display_order' => 2],
            ['min_amount' => 500000, 'max_amount' => null, 'rate_percent' => 20, 'display_order' => 3],
        ]);

        // 600000 gross: 0 on the first 200000, 10% on the next 300000
        // (=30000), 20% on the remaining 100000 (=20000) => 50000 total.
        $result = app(PayrollDeductionService::class)->calculate(600000);

        $this->assertSame(50000.0, $result['total']);
    }

    public function test_a_gross_pay_entirely_within_the_zero_rated_bracket_produces_no_deduction(): void
    {
        $type = PayrollDeductionType::create([
            'name' => 'Income Tax', 'code' => 'IRPP', 'calculation_method' => 'progressive_brackets',
            'is_active' => true, 'display_order' => 1,
        ]);
        $type->brackets()->create(['min_amount' => 0, 'max_amount' => 200000, 'rate_percent' => 0, 'display_order' => 1]);

        $result = app(PayrollDeductionService::class)->calculate(150000);

        $this->assertSame(0.0, $result['total']);
        $this->assertCount(0, $result['breakdown']);
    }

    public function test_an_inactive_deduction_type_is_ignored(): void
    {
        PayrollDeductionType::create([
            'name' => 'Retired Levy', 'code' => 'OLD', 'calculation_method' => 'flat_percent',
            'flat_rate_percent' => 5, 'is_active' => false, 'display_order' => 1,
        ]);

        $result = app(PayrollDeductionService::class)->calculate(240000);

        $this->assertSame(0.0, $result['total']);
    }
}
