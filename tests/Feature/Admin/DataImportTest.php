<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\Guest;
use App\Models\MenuItem;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * "no it is suppose to be only in the Master admin" — the corrected design
 * for bulk CSV import: it lives entirely under the Master Admin — Data
 * screen (Admin\DataImportController, routes admin.system-data.import.*),
 * gated on the single Owner-only `manage-system-data` permission, not
 * scattered across each resource's own screen behind that resource's own
 * permission the way an earlier version of this feature did. These tests
 * replace GuestImportTest/ReservationImportTest/RoomImportTest/
 * MenuImportTest/StaffImportTest (deleted — they asserted the rejected
 * design) and specifically prove the correction: a role that held the OLD
 * per-resource permission (e.g. front_desk's manage-guests, duty_manager's
 * manage-rooms/manage-menu) can no longer reach any import screen at all.
 * The import mechanics themselves (matching rules, validation, the
 * preview-then-confirm flow) live in SpreadsheetImportService and are
 * unchanged by this round — see its own docblock — so these tests focus on
 * placement/permission plus one straightforward create+update pass per
 * entity type to prove the wiring works end to end.
 */
class DataImportTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function csv(array $header, array $rows): UploadedFile
    {
        $lines = [implode(',', $header)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $row));
        }

        return UploadedFile::fake()->createWithContent('import.csv', implode("\n", $lines)."\n");
    }

    // -----------------------------------------------------------------
    // Placement / permission
    // -----------------------------------------------------------------

    public function test_owner_can_view_the_import_hub_and_every_import_form(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $this->actingAs($owner)->get(route('admin.system-data.import.index'))->assertOk()->assertSee('Import data');
        $this->actingAs($owner)->get(route('admin.system-data.import.guests'))->assertOk();
        $this->actingAs($owner)->get(route('admin.system-data.import.reservations'))->assertOk();
        $this->actingAs($owner)->get(route('admin.system-data.import.rooms'))->assertOk();
        $this->actingAs($owner)->get(route('admin.system-data.import.menu'))->assertOk();
        $this->actingAs($owner)->get(route('admin.system-data.import.staff'))->assertOk();
    }

    public function test_master_admin_hub_links_to_the_import_screen(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $this->actingAs($owner)->get(route('admin.system-data.index'))
            ->assertOk()
            ->assertSee(route('admin.system-data.import.index'), false);
    }

    /** Same carve-out SystemDataTest already proves for the rest of Master Admin. */
    public function test_general_manager_cannot_reach_any_import_screen_despite_holding_almost_every_other_permission(): void
    {
        $gm = $this->userWithRole('general_manager', ['auth_type' => 'password_only']);

        $this->actingAs($gm)->get(route('admin.system-data.import.index'))->assertForbidden();
        $this->actingAs($gm)->get(route('admin.system-data.import.guests'))->assertForbidden();
    }

    /**
     * The specific correction the user asked for: front_desk held
     * 'manage-guests' under the old, rejected design and could reach the
     * guest importer through it. That must no longer work — only
     * 'manage-system-data' (Owner) does now.
     */
    public function test_front_desk_can_no_longer_reach_the_guest_importer_via_its_own_manage_guests_permission(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);

        $this->actingAs($frontDesk)->get(route('admin.system-data.import.guests'))->assertForbidden();
        $this->actingAs($frontDesk)->post(route('admin.system-data.import.guests.preview'), [
            'file' => $this->csv(['first_name', 'last_name'], [['Jane', 'Doe']]),
        ])->assertForbidden();
    }

    /** Same correction, for rooms/menu via duty_manager's manage-rooms/manage-menu. */
    public function test_duty_manager_can_no_longer_reach_the_room_or_menu_importer_via_its_own_permissions(): void
    {
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);

        $this->actingAs($dutyManager)->get(route('admin.system-data.import.rooms'))->assertForbidden();
        $this->actingAs($dutyManager)->get(route('admin.system-data.import.menu'))->assertForbidden();
    }

    /** @dataProvider rolesWithoutManageSystemData */
    public function test_other_roles_cannot_reach_the_import_hub(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $this->actingAs($user)->get(route('admin.system-data.import.index'))->assertForbidden();
    }

    public static function rolesWithoutManageSystemData(): array
    {
        return [['duty_manager'], ['accountant'], ['cashier'], ['front_desk'], ['bar_staff'], ['restaurant_staff']];
    }

    // -----------------------------------------------------------------
    // Templates
    // -----------------------------------------------------------------

    public function test_every_type_has_a_downloadable_csv_template(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        foreach ([
            'admin.system-data.import.guests.template',
            'admin.system-data.import.reservations.template',
            'admin.system-data.import.rooms.template',
            'admin.system-data.import.menu.template',
            'admin.system-data.import.staff.template',
        ] as $route) {
            $response = $this->actingAs($owner)->get(route($route));
            $response->assertOk();
            $this->assertStringContainsString('.csv', $response->headers->get('content-disposition'));
        }
    }

    // -----------------------------------------------------------------
    // Guests
    // -----------------------------------------------------------------

    public function test_guest_import_preview_then_commit_creates_and_updates(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $existing = Guest::factory()->create(['phone' => '+237600000001', 'first_name' => 'Old']);

        $file = $this->csv(
            ['first_name', 'last_name', 'phone'],
            [['New', 'Guest', '+237600000002'], ['Updated', 'Name', '+237600000001']]
        );

        $preview = $this->actingAs($owner)->post(route('admin.system-data.import.guests.preview'), ['file' => $file]);
        $preview->assertOk();
        $token = $preview->viewData('preview')['token'];
        $this->assertSame(1, $preview->viewData('preview')['counts']['create']);
        $this->assertSame(1, $preview->viewData('preview')['counts']['update']);

        $this->actingAs($owner)->post(route('admin.system-data.import.guests.commit'), ['token' => $token])
            ->assertRedirect(route('admin.system-data.import.index'));

        $this->assertDatabaseHas('guests', ['phone' => '+237600000002', 'first_name' => 'New']);
        $this->assertSame('Updated', $existing->fresh()->first_name);
    }

    // -----------------------------------------------------------------
    // Rooms
    // -----------------------------------------------------------------

    public function test_room_import_preview_then_commit_creates_a_room_in_an_existing_category(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = RoomCategory::factory()->create(['name' => 'Deluxe']);

        $file = $this->csv(['room_number', 'room_category'], [['501', 'Deluxe']]);

        $preview = $this->actingAs($owner)->post(route('admin.system-data.import.rooms.preview'), ['file' => $file]);
        $token = $preview->viewData('preview')['token'];

        $this->actingAs($owner)->post(route('admin.system-data.import.rooms.commit'), ['token' => $token])
            ->assertRedirect(route('admin.system-data.import.index'));

        $this->assertDatabaseHas('rooms', ['room_number' => '501', 'room_category_id' => $category->id]);
    }

    public function test_room_import_rejects_a_category_that_does_not_exist_rather_than_creating_one(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $file = $this->csv(['room_number', 'room_category'], [['777', 'Nonexistent Category']]);

        $preview = $this->actingAs($owner)->post(route('admin.system-data.import.rooms.preview'), ['file' => $file]);
        $this->assertSame(1, $preview->viewData('preview')['counts']['error']);
        $this->assertDatabaseMissing('rooms', ['room_number' => '777']);
    }

    // -----------------------------------------------------------------
    // Menu (outlet switching)
    // -----------------------------------------------------------------

    public function test_menu_import_creates_items_for_the_selected_outlet_and_auto_creates_the_category(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $file = $this->csv(['category', 'name', 'price'], [['Drinks', 'Mineral Water 50cl', '500']]);

        $preview = $this->actingAs($owner)->post(route('admin.system-data.import.menu.preview'), [
            'outlet' => 'restaurant',
            'file' => $file,
        ]);
        $preview->assertOk();
        $token = $preview->viewData('preview')['token'];

        $this->actingAs($owner)->post(route('admin.system-data.import.menu.commit'), [
            'token' => $token,
            'outlet' => 'restaurant',
        ])->assertRedirect(route('admin.system-data.import.index'));

        $this->assertDatabaseHas('menu_items', ['name' => 'Mineral Water 50cl', 'outlet' => 'restaurant']);
        $this->assertSame(0, MenuItem::where('outlet', 'bar')->where('name', 'Mineral Water 50cl')->count());
    }

    public function test_menu_import_rejects_an_outlet_it_does_not_recognize(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $this->actingAs($owner)->get(route('admin.system-data.import.menu', ['outlet' => 'not-a-real-outlet']))
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Reservations (guest-first ordering)
    // -----------------------------------------------------------------

    public function test_reservation_import_matches_an_existing_guest_by_phone_and_lands_pending(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create(['phone' => '+237600000003']);
        $category = RoomCategory::factory()->create();

        $file = $this->csv(
            ['guest_phone', 'room_category', 'check_in_date', 'check_out_date', 'rate_quoted'],
            [['+237600000003', $category->name, now()->addDay()->toDateString(), now()->addDays(3)->toDateString(), '25000']]
        );

        $preview = $this->actingAs($owner)->post(route('admin.system-data.import.reservations.preview'), ['file' => $file]);
        $this->assertSame(1, $preview->viewData('preview')['counts']['create']);
        $token = $preview->viewData('preview')['token'];

        $this->actingAs($owner)->post(route('admin.system-data.import.reservations.commit'), ['token' => $token])
            ->assertRedirect(route('admin.system-data.import.index'));

        $this->assertDatabaseHas('reservations', ['guest_id' => $guest->id, 'status' => 'pending']);
    }

    public function test_reservation_import_rejects_a_phone_number_with_no_matching_guest(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = RoomCategory::factory()->create();

        $file = $this->csv(
            ['guest_phone', 'room_category', 'check_in_date', 'check_out_date', 'rate_quoted'],
            [['+237699999999', $category->name, now()->addDay()->toDateString(), now()->addDays(3)->toDateString(), '25000']]
        );

        $preview = $this->actingAs($owner)->post(route('admin.system-data.import.reservations.preview'), ['file' => $file]);
        $this->assertSame(1, $preview->viewData('preview')['counts']['error']);
    }

    // -----------------------------------------------------------------
    // Staff (generated credentials, shown once)
    // -----------------------------------------------------------------

    public function test_staff_import_creates_an_account_and_returns_a_generated_credential_exactly_once(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        Department::firstOrCreate(['code' => 'FO'], ['name' => 'Front Office', 'description' => 'Reservations, check-in/out, guest folios']);

        $file = $this->csv(
            ['name', 'username', 'email', 'role', 'auth_type'],
            [['Jane Smith', 'jsmith', 'jane@example.com', 'front_desk', 'pin']]
        );

        $preview = $this->actingAs($owner)->post(route('admin.system-data.import.staff.preview'), ['file' => $file]);
        $preview->assertOk();
        $token = $preview->viewData('preview')['token'];

        $commit = $this->actingAs($owner)->post(route('admin.system-data.import.staff.commit'), ['token' => $token]);
        $commit->assertOk();

        $credentials = $commit->viewData('credentials');
        $this->assertSame(1, $credentials['created']);
        $this->assertCount(1, $credentials['credentials']);
        $this->assertSame('PIN', $credentials['credentials'][0]['credential_type']);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $credentials['credentials'][0]['credential']);

        $this->assertDatabaseHas('users', ['username' => 'jsmith', 'email' => 'jane@example.com']);
        $this->assertTrue(User::where('username', 'jsmith')->first()->hasRole('front_desk'));
    }

    public function test_staff_import_never_overwrites_an_existing_username(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $this->userWithRole('front_desk', ['username' => 'jsmith', 'email' => 'existing@example.com']);

        $file = $this->csv(
            ['name', 'username', 'email', 'role', 'auth_type'],
            [['Jane Smith', 'jsmith', 'jane@example.com', 'front_desk', 'pin']]
        );

        $preview = $this->actingAs($owner)->post(route('admin.system-data.import.staff.preview'), ['file' => $file]);
        $this->assertSame(1, $preview->viewData('preview')['counts']['error']);
    }
}
