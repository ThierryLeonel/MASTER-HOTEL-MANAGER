<?php

namespace Tests\Feature\FrontOffice;

use App\Models\AccountsReceivable;
use App\Models\Company;
use App\Models\Folio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Broader request ("...edit, delete and update all categories and every
 * input, except financial inputs"): Company could previously only be
 * created. `credit_limit` is informational only; the actual debt ledger
 * lives entirely in AccountsReceivable, never touched by this edit.
 */
class CompanyEditDeleteTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_front_desk_can_update_a_company(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $company = Company::create(['name' => 'Old Corp', 'is_active' => true, 'created_by' => $frontDesk->id]);

        $this->actingAs($frontDesk)->put(route('companies.update', $company), [
            'name' => 'New Corp',
            'is_active' => '0',
        ])->assertRedirect();

        $company->refresh();
        $this->assertSame('New Corp', $company->name);
        $this->assertFalse($company->is_active);
    }

    public function test_a_company_with_accounts_receivable_history_cannot_be_deleted(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $company = Company::create(['name' => 'Debtor Corp', 'is_active' => true, 'created_by' => $frontDesk->id]);
        $folio = Folio::factory()->create();
        AccountsReceivable::create([
            'folio_id' => $folio->id, 'guest_id' => $folio->stay->guest_id, 'company_id' => $company->id,
            'original_amount' => 50000, 'amount_outstanding' => 50000, 'status' => 'open',
            'due_date' => now()->addDays(30), 'created_by' => $frontDesk->id,
        ]);

        $response = $this->actingAs($frontDesk)->delete(route('companies.destroy', $company));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    public function test_an_unused_company_can_be_deleted(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $company = Company::create(['name' => 'Unused Corp', 'is_active' => true, 'created_by' => $frontDesk->id]);

        $this->actingAs($frontDesk)->delete(route('companies.destroy', $company))->assertRedirect();

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }
}
