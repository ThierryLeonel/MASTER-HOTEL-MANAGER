<?php

namespace Tests\Feature\FrontOffice;

use App\Exceptions\HmsException;
use App\Models\ChargeType;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Stay;
use App\Services\Finance\ChargeTypeService;
use App\Services\FrontOffice\FolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Charge-types unification (Sept 2026 — see ChargeType's own docblock).
 * Proves three things together, all previously either broken or
 * impossible:
 *
 *  1. bar_outside_charge/bucareaux_charge — already offered by the "Post
 *     charge" form and already attempted by Pos\OrderService::close() —
 *     actually succeed now, closing a pre-existing, genuine gap (see the
 *     charge-types seed migration's own docblock).
 *  2. A brand-new, admin-added charge type (not one of the seven system
 *     ones) can be posted to a folio at all — this requires BOTH the
 *     dynamic validation (FolioTransaction::postableChargeTypeCodes())
 *     AND the folio_transactions.type column no longer being a fixed
 *     MySQL ENUM (see 2025_02_02_001300_widen_folio_transactions_type_to_string.php)
 *     — a genuine, separate defect found and fixed during this same
 *     round, since a code-only validation fix would still fail at the
 *     database the moment a real MySQL install tried to save a code the
 *     old ENUM never knew about.
 *  3. A charge type, once deactivated, is refused for NEW postings but a
 *     transaction posted while it was still active keeps counting in
 *     historical totals (allChargeTypeCodes() vs postableChargeTypeCodes()).
 */
class ChargeTypePostingTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        $this->openShiftFor('FO');
    }

    private function openFolio(): Folio
    {
        $stay = Stay::factory()->create(['status' => 'active']);

        return Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function systemChargeTypeProvider(): iterable
    {
        yield 'room charge' => ['room_charge'];
        yield 'bar charge' => ['bar_charge'];
        yield 'restaurant charge' => ['restaurant_charge'];
        yield 'front desk charge' => ['front_desk_charge'];
        yield 'other service' => ['other_service'];
        // The two-outlet gap this whole feature closed as a side effect —
        // see this class's own docblock.
        yield 'bar outside charge' => ['bar_outside_charge'];
        yield 'bucareaux charge' => ['bucareaux_charge'];
    }

    /**
     * @dataProvider systemChargeTypeProvider
     */
    public function test_every_system_charge_type_can_be_posted_to_a_folio(string $code): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $folio = $this->openFolio();

        $this->actingAs($dutyManager)->post(route('folios.charge', $folio), [
            'type' => $code,
            'amount' => 1500,
            'description' => "Test {$code}",
        ])->assertRedirect();

        $this->assertDatabaseHas('folio_transactions', ['folio_id' => $folio->id, 'type' => $code]);
    }

    public function test_a_brand_new_admin_added_charge_type_can_be_posted_to_a_folio(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $chargeType = app(ChargeTypeService::class)->create(['name' => 'Rental Charge'], $owner);

        $dutyManager = $this->userWithRole('duty_manager');
        $folio = $this->openFolio();

        $this->actingAs($dutyManager)->post(route('folios.charge', $folio), [
            'type' => $chargeType->code,
            'amount' => 7500,
            'description' => 'Projector rental',
        ])->assertRedirect();

        $this->assertDatabaseHas('folio_transactions', ['folio_id' => $folio->id, 'type' => $chargeType->code, 'amount' => '7500.00']);
        $this->assertSame('7500.00', $folio->fresh()->balance);
    }

    public function test_an_unknown_charge_type_code_is_rejected(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $folio = $this->openFolio();

        $response = $this->actingAs($dutyManager)->post(route('folios.charge', $folio), [
            'type' => 'not_a_real_charge_type',
            'amount' => 1000,
            'description' => 'Should fail',
        ]);

        $response->assertSessionHasErrors('type');
        $this->assertSame(0, FolioTransaction::count());
    }

    public function test_a_deactivated_charge_type_can_no_longer_be_posted_but_its_old_history_still_counts(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $service = app(ChargeTypeService::class);
        $chargeType = $service->create(['name' => 'Seasonal Decor Fee'], $owner);

        $folio = $this->openFolio();
        app(FolioService::class)->postCharge($folio, $chargeType->code, 4000, 'Seasonal decor', $owner);

        $service->update($chargeType, ['name' => $chargeType->name, 'is_active' => false], $owner);

        $this->assertNotContains($chargeType->code, FolioTransaction::postableChargeTypeCodes(), 'A deactivated charge type must not remain postable.');
        $this->assertContains($chargeType->code, FolioTransaction::allChargeTypeCodes(), 'A deactivated charge type must still count toward historical totals.');

        $this->expectException(HmsException::class);
        app(FolioService::class)->postCharge($folio, $chargeType->code, 1000, 'Should be refused now', $owner);
    }

    public function test_folio_department_summary_labels_a_dynamic_charge_type_by_its_current_name(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $chargeType = app(ChargeTypeService::class)->create(['name' => 'Rental Charge'], $owner);

        $folio = $this->openFolio();
        app(FolioService::class)->postCharge($folio, $chargeType->code, 6000, 'Bike rental', $owner);

        $summary = $folio->fresh()->load('transactions')->departmentSummary();

        $this->assertArrayHasKey('Rental Charge', $summary);
        $this->assertSame('6000.00', $summary['Rental Charge']);
    }
}
