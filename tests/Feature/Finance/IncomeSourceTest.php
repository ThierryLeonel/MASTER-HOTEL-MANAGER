<?php

namespace Tests\Feature\Finance;

use App\Models\IncomeSource;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Direct request, fast follow-up to the income-source dropdown
 * ("the list should not be fixed... the accountant, or finance officer
 * [should be able to] update, create [it], and [the owner should be able
 * to] update, create and delete it"): the managed `income_sources` list
 * behind the "Income source" dropdown on the general "record a payment"
 * screen. "Finance officer" in the request maps onto this system's
 * existing `accountant` role — no separate role exists or was asked for
 * elsewhere in this system.
 */
class IncomeSourceTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_accountant_can_create_an_income_source(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('finance.income-sources.store'), [
            'name' => 'Vending machine income',
        ])->assertRedirect();

        $source = IncomeSource::where('name', 'Vending machine income')->first();
        $this->assertNotNull($source);
        $this->assertSame($accountant->id, $source->created_by);
    }

    public function test_accountant_can_rename_an_income_source(): void
    {
        $accountant = $this->userWithRole('accountant');
        $source = IncomeSource::create(['name' => 'Rent']);

        $this->actingAs($accountant)->put(route('finance.income-sources.update', $source), [
            'name' => 'Property rent',
        ])->assertRedirect();

        $this->assertSame('Property rent', $source->fresh()->name);
        $this->assertSame($accountant->id, $source->fresh()->updated_by);
    }

    public function test_owner_can_create_update_and_delete_an_income_source(): void
    {
        $owner = $this->userWithRole('owner');

        $this->actingAs($owner)->post(route('finance.income-sources.store'), [
            'name' => 'Sale of scrap',
        ])->assertRedirect();
        $source = IncomeSource::where('name', 'Sale of scrap')->firstOrFail();

        $this->actingAs($owner)->put(route('finance.income-sources.update', $source), [
            'name' => 'Sale of scrap or used equipment',
        ])->assertRedirect();
        $this->assertSame('Sale of scrap or used equipment', $source->fresh()->name);

        $this->actingAs($owner)->delete(route('finance.income-sources.destroy', $source))->assertRedirect();
        $this->assertNull(IncomeSource::find($source->id));
    }

    /**
     * Direct request wording: "the owner should be able to update, create
     * and delete it" — delete is the more destructive action and stays
     * Owner-only, the same split already established for
     * 'delete-users'/'void-transactions'. General Manager holds
     * `manage-income-sources` (create/update) via $all but not
     * `delete-income-sources`.
     */
    public function test_general_manager_holds_manage_income_sources_but_not_delete_income_sources(): void
    {
        $gm = $this->userWithRole('general_manager');
        $source = IncomeSource::create(['name' => 'Rent']);

        $this->actingAs($gm)->put(route('finance.income-sources.update', $source), [
            'name' => 'Property rent',
        ])->assertRedirect();

        $this->actingAs($gm)->delete(route('finance.income-sources.destroy', $source))->assertForbidden();
        $this->assertNotNull(IncomeSource::find($source->id));
    }

    /**
     * The accountant explicitly asked for create/update only — delete
     * stays Owner-only.
     */
    public function test_accountant_cannot_delete_an_income_source(): void
    {
        $accountant = $this->userWithRole('accountant');
        $source = IncomeSource::create(['name' => 'Rent']);

        $this->actingAs($accountant)->delete(route('finance.income-sources.destroy', $source))->assertForbidden();
        $this->assertNotNull(IncomeSource::find($source->id));
    }

    public function test_a_role_without_manage_income_sources_cannot_create_or_update_one(): void
    {
        $frontDesk = $this->pinUser('front_desk');
        $source = IncomeSource::create(['name' => 'Rent']);

        $this->actingAs($frontDesk)->post(route('finance.income-sources.store'), [
            'name' => 'Should not be created',
        ])->assertForbidden();

        $this->actingAs($frontDesk)->put(route('finance.income-sources.update', $source), [
            'name' => 'Should not be renamed',
        ])->assertForbidden();

        $this->assertSame('Rent', $source->fresh()->name);
        $this->assertSame(0, IncomeSource::where('name', 'Should not be created')->count());
    }

    public function test_a_duplicate_income_source_name_is_rejected(): void
    {
        $accountant = $this->userWithRole('accountant');
        IncomeSource::create(['name' => 'Rent']);

        $response = $this->actingAs($accountant)->post(route('finance.income-sources.store'), [
            'name' => 'Rent',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, IncomeSource::where('name', 'Rent')->count());
    }

    /**
     * The whole point of keeping `payments.income_source` as free text
     * with no foreign key to this table (see IncomeSource's own
     * docblock): deleting an entry from the managed list must never
     * rewrite, blank out, or otherwise touch a historical payment already
     * recorded under that name.
     */
    public function test_deleting_an_income_source_does_not_touch_historical_payments(): void
    {
        $owner = $this->userWithRole('owner');
        $accountant = $this->userWithRole('accountant', ['auth_type' => 'password_only']);
        $this->openShiftFor('FO');
        $source = IncomeSource::create(['name' => 'Rent']);

        $this->actingAs($accountant)->post(route('payments.store'), [
            'amount' => 15000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'notes' => 'Shop rent',
            'income_source' => 'Rent',
        ])->assertRedirect();

        $this->actingAs($owner)->delete(route('finance.income-sources.destroy', $source))->assertRedirect();

        $payment = Payment::first();
        $this->assertSame('Rent', $payment->income_source);
    }
}
