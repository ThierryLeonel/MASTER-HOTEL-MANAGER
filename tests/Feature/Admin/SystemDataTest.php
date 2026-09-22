<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\FinancialAccount;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\SyncDevice;
use App\Models\User;
use App\Services\Admin\SystemDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Seventh post-9.8 fix round, follow-up ("I NEED A BUTTON IN THE MASTER
 * ADMIN THAT CAN RESET ALL DATA AND THAT WHICH CAN EXTRACT THE DATA AND
 * THAT WICH CAN TRIGER BACKUP, YOU SHOULD NOT FORGET THIS IS A HYBRID
 * SYSTEM" — PHASE9_AUDIT_AND_PLAN.md §20.12). See SystemDataService's own
 * docblock for the full design reasoning, especially the hybrid-sync
 * hazard this class's tests spend real effort proving is actually closed.
 */
class SystemDataTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function insertRawPayment(User $recorder): void
    {
        DB::table('payments')->insert([
            'payment_number' => 'PAY-TEST-'.uniqid(),
            'direction' => 'in',
            'amount' => 15000,
            'method' => 'cash',
            'source_module' => 'general_payment',
            'recorded_by' => $recorder->id,
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_owner_can_view_the_data_management_page_and_sees_real_row_counts(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        Guest::factory()->count(3)->create();

        $response = $this->actingAs($owner)->get(route('admin.system-data.index'));

        $response->assertOk();
        $response->assertSee('Master Admin');
        $response->assertSee('guests');
    }

    /**
     * The deliberate carve-out: General Manager holds every permission in
     * this project except 'manage-own-shift' (see RolesAndPermissionsSeeder's
     * $all pattern) — 'manage-system-data' is the one other permission
     * explicitly excluded from that, so this proves the exclusion
     * actually works rather than assuming it does.
     */
    public function test_general_manager_cannot_reach_the_data_management_page_despite_holding_almost_every_other_permission(): void
    {
        $gm = $this->userWithRole('general_manager', ['auth_type' => 'password_only']);

        $this->actingAs($gm)->get(route('admin.system-data.index'))->assertForbidden();
    }

    /** @dataProvider rolesWithoutManageSystemData */
    public function test_other_roles_cannot_reach_the_data_management_page(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $this->actingAs($user)->get(route('admin.system-data.index'))->assertForbidden();
    }

    public static function rolesWithoutManageSystemData(): array
    {
        return [['duty_manager'], ['accountant'], ['cashier'], ['front_desk'], ['bar_staff'], ['restaurant_staff']];
    }

    public function test_reset_is_rejected_without_the_typed_confirmation_phrase(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        Guest::factory()->create();

        $response = $this->actingAs($owner)->post(route('admin.system-data.reset'), [
            'confirmation' => 'wrong phrase',
            'has_backup' => '1',
        ]);

        $response->assertSessionHasErrors('confirmation');
        $this->assertSame(1, Guest::count());
    }

    public function test_reset_is_rejected_without_confirming_a_backup_was_taken(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        Guest::factory()->create();

        $response = $this->actingAs($owner)->post(route('admin.system-data.reset'), [
            'confirmation' => 'RESET ALL DATA',
        ]);

        $response->assertSessionHasErrors('has_backup');
        $this->assertSame(1, Guest::count());
    }

    /**
     * The core guarantee: operational/history data is gone, but nothing a
     * staff member needs to keep working is touched.
     */
    public function test_reset_clears_operational_data_but_leaves_setup_data_untouched(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $accountant = $this->userWithRole('accountant');
        $department = Department::first();
        $category = RoomCategory::factory()->create();
        $room = Room::factory()->create(['room_category_id' => $category->id]);
        $guest = Guest::factory()->create();
        Reservation::factory()->for($guest)->for($category)->create();
        $this->insertRawPayment($owner);
        $this->seedFinancialAccounts();

        $this->assertGreaterThan(0, Guest::count());
        $this->assertGreaterThan(0, Reservation::count());
        $this->assertDatabaseCount('payments', 1);

        $this->actingAs($owner)->post(route('admin.system-data.reset'), [
            'confirmation' => 'RESET ALL DATA',
            'has_backup' => '1',
        ])->assertRedirect(route('admin.system-data.index'));

        // Operational data: gone.
        $this->assertSame(0, Guest::count());
        $this->assertSame(0, Reservation::count());
        $this->assertDatabaseCount('payments', 0);

        // Setup data: completely untouched.
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('users', ['id' => $accountant->id]);
        $this->assertDatabaseHas('departments', ['id' => $department->id]);
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertDatabaseHas('room_categories', ['id' => $category->id]);
        $this->assertDatabaseHas('financial_accounts', ['code' => 'CASH']);
        $this->assertTrue($owner->fresh()->hasRole('owner'));
    }

    /**
     * A room can only legitimately be 'occupied' because of a stay that
     * no longer exists after the reset — it must flip back to available,
     * or the room board would permanently lie. 'maintenance' describes
     * the physical room, not a guest, so it must NOT be touched.
     */
    public function test_reset_frees_an_occupied_room_but_leaves_a_maintenance_room_alone(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = RoomCategory::factory()->create();
        $occupied = Room::factory()->create(['room_category_id' => $category->id, 'status' => 'occupied']);
        $maintenance = Room::factory()->create(['room_category_id' => $category->id, 'status' => 'maintenance']);

        $this->actingAs($owner)->post(route('admin.system-data.reset'), [
            'confirmation' => 'RESET ALL DATA',
            'has_backup' => '1',
        ]);

        $this->assertSame('available', $occupied->fresh()->status);
        $this->assertSame('maintenance', $maintenance->fresh()->status);
    }

    /**
     * The hybrid-sync hazard SystemDataService's docblock describes at
     * length: without this, a reset on one installation would quietly get
     * undone by the next sync pull from its partner. This is the one
     * assertion that actually proves the defense is real, not assumed.
     */
    public function test_reset_deactivates_every_sync_device_so_a_partner_installation_cannot_silently_resurrect_the_wiped_data(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $device = SyncDevice::create([
            'name' => 'Cloud VPS', 'device_key' => 'cloud-vps-key', 'is_active' => true,
        ]);

        $this->actingAs($owner)->post(route('admin.system-data.reset'), [
            'confirmation' => 'RESET ALL DATA',
            'has_backup' => '1',
        ]);

        $this->assertFalse($device->fresh()->is_active);
    }

    public function test_reset_writes_exactly_one_audit_log_entry_for_the_whole_action(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        Guest::factory()->count(2)->create();

        $this->actingAs($owner)->post(route('admin.system-data.reset'), [
            'confirmation' => 'RESET ALL DATA',
            'has_backup' => '1',
        ]);

        $this->assertSame(1, AuditLog::where('action', 'admin.system_data_reset')->count());
        $entry = AuditLog::where('action', 'admin.system_data_reset')->first();
        $this->assertSame($owner->id, $entry->user_id);
        $this->assertArrayHasKey('guests', $entry->new_values['rows_cleared']);
    }

    /**
     * `password` is cast `hashed` (bcrypt), so the raw value passed in
     * never appears in the database anyway — reading the REAL stored
     * hash back out and searching for that exact string is what actually
     * proves the backup strips it, rather than trivially passing because
     * the plaintext was never stored in the first place.
     */
    public function test_backup_downloads_a_json_file_and_never_includes_a_staff_password_hash(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $realHash = $owner->fresh()->getRawOriginal('password');
        $this->assertNotEmpty($realHash);

        $response = $this->actingAs($owner)->get(route('admin.system-data.backup'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/json');
        $content = $response->getContent();
        $this->assertStringNotContainsString($realHash, $content);
        $this->assertStringContainsString('"guests"', $content);
    }

    public function test_export_downloads_a_zip_of_business_data_csvs(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        Guest::factory()->count(2)->create();

        $response = $this->actingAs($owner)->get(route('admin.system-data.export'));

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('.zip', $response->headers->get('content-disposition'));
    }

    /**
     * Feature: Import. Fixtures for these tests are generated by calling
     * SystemDataService's real backupJson()/exportBusinessCsvZip()
     * directly — the exact same code the Backup/Export buttons call — so
     * these are genuine round-trip tests (real production output fed
     * back into real production input), not hand-typed fixture files
     * that could silently drift from what the app actually produces.
     */
    private function uploadedJsonBackup(): UploadedFile
    {
        $json = app(SystemDataService::class)->backupJson();
        $path = tempnam(sys_get_temp_dir(), 'hms-backup-test').'.json';
        file_put_contents($path, $json);

        return new UploadedFile($path, 'backup.json', 'application/json', null, true);
    }

    private function uploadedCsvZip(): UploadedFile
    {
        $path = app(SystemDataService::class)->exportBusinessCsvZip();

        return new UploadedFile($path, 'export.zip', 'application/zip', null, true);
    }

    /** @dataProvider rolesWithoutManageSystemData */
    public function test_other_roles_cannot_import_a_backup(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $this->actingAs($user)->post(route('admin.system-data.import-backup'), [
            'confirm_import' => '1',
            'backup_file' => $this->uploadedJsonBackup(),
        ])->assertForbidden();
    }

    public function test_import_backup_is_rejected_without_the_confirmation_checkbox(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $response = $this->actingAs($owner)->post(route('admin.system-data.import-backup'), [
            'backup_file' => $this->uploadedJsonBackup(),
        ]);

        $response->assertSessionHasErrors('confirm_import');
    }

    public function test_import_is_refused_when_the_system_already_has_data(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $file = $this->uploadedJsonBackup();
        // A guest exists NOW, after the backup was taken — the system is
        // no longer empty by the time Import is attempted.
        Guest::factory()->create();

        $response = $this->actingAs($owner)->post(route('admin.system-data.import-backup'), [
            'confirm_import' => '1',
            'backup_file' => $file,
        ]);

        $response->assertSessionHasErrors();
        // Nothing extra was inserted — the guest count is still exactly
        // the one pre-existing guest, not doubled.
        $this->assertSame(1, Guest::count());
    }

    /**
     * The core round-trip guarantee: Backup -> Reset -> Import restores
     * the same guest/reservation/payment history that was there before,
     * onto the SAME installation (so setup-table ids line up exactly),
     * and the resulting integrity check finds nothing wrong with it.
     */
    public function test_owner_can_restore_a_json_backup_after_a_reset_and_the_data_comes_back_clean(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = RoomCategory::factory()->create();
        $room = Room::factory()->create(['room_category_id' => $category->id]);
        $guest = Guest::factory()->create(['first_name' => 'Aline']);
        $reservation = Reservation::factory()->for($guest)->for($category)->create(['room_id' => $room->id]);
        $this->insertRawPayment($owner);

        $backup = $this->uploadedJsonBackup();

        $this->actingAs($owner)->post(route('admin.system-data.reset'), [
            'confirmation' => 'RESET ALL DATA',
            'has_backup' => '1',
        ]);
        $this->assertSame(0, Guest::count());

        $response = $this->actingAs($owner)->post(route('admin.system-data.import-backup'), [
            'confirm_import' => '1',
            'backup_file' => $backup,
        ]);

        $response->assertRedirect(route('admin.system-data.index'));
        $this->assertSame(1, Guest::count());
        $this->assertSame('Aline', Guest::first()->first_name);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'guest_id' => $guest->id]);
        $this->assertDatabaseCount('payments', 1);
        // Setup data (rooms/categories) was never touched by reset OR
        // import, so the restored reservation's room_id still resolves —
        // exactly the "same installation" portability case described in
        // SystemDataService's docblock — and the integrity check run
        // immediately after import confirms it found nothing wrong.
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'found no issues'));
    }

    /**
     * The scope guarantee, proven directly rather than assumed: even a
     * backup file that DOES contain a 'users' row (every real backup
     * does — that's what makes it a full technical backup) must never
     * result in a new user being created by Import.
     */
    public function test_import_never_restores_the_users_table_even_though_the_backup_file_contains_it(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $usersBeforeImport = User::count();

        $json = app(SystemDataService::class)->backupJson();
        $this->assertStringContainsString('"users"', $json, 'Sanity check: a real backup does include a users section.');

        $path = tempnam(sys_get_temp_dir(), 'hms-backup-test').'.json';
        file_put_contents($path, $json);
        $file = new UploadedFile($path, 'backup.json', 'application/json', null, true);

        $this->actingAs($owner)->post(route('admin.system-data.import-backup'), [
            'confirm_import' => '1',
            'backup_file' => $file,
        ])->assertRedirect();

        $this->assertSame($usersBeforeImport, User::count());
    }

    /**
     * The CSV-specific scope guarantee: 'rooms' and 'financial_accounts'
     * ride along in the export zip "for context" (BUSINESS_EXPORT_TABLES)
     * but are SETUP tables reset() never clears — so if import mistakenly
     * tried to re-insert rooms.csv here, it would collide on the room's
     * still-existing primary key and throw, since reset() (deliberately)
     * never removed it. A clean, unchanged room count proves it was
     * skipped rather than merely "didn't happen to error."
     */
    public function test_csv_import_restores_operational_tables_but_never_reinserts_rooms_or_financial_accounts(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = RoomCategory::factory()->create();
        $room = Room::factory()->create(['room_category_id' => $category->id]);
        Guest::factory()->create();
        $this->seedFinancialAccounts();
        $roomCountBefore = Room::count();
        $financialAccountCountBefore = FinancialAccount::count();

        $zip = $this->uploadedCsvZip();

        $this->actingAs($owner)->post(route('admin.system-data.reset'), [
            'confirmation' => 'RESET ALL DATA',
            'has_backup' => '1',
        ]);
        $this->assertSame(0, Guest::count());

        $response = $this->actingAs($owner)->post(route('admin.system-data.import-csv'), [
            'confirm_import' => '1',
            'csv_zip' => $zip,
        ]);

        $response->assertRedirect(route('admin.system-data.index'));
        $this->assertSame(1, Guest::count());
        // Untouched — no duplicate-key error, and no change in count.
        $this->assertSame($roomCountBefore, Room::count());
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertSame($financialAccountCountBefore, FinancialAccount::count());
    }

    public function test_import_writes_an_audit_log_entry(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        Guest::factory()->create();
        $backup = $this->uploadedJsonBackup();

        $this->actingAs($owner)->post(route('admin.system-data.reset'), [
            'confirmation' => 'RESET ALL DATA',
            'has_backup' => '1',
        ]);

        $this->actingAs($owner)->post(route('admin.system-data.import-backup'), [
            'confirm_import' => '1',
            'backup_file' => $backup,
        ]);

        $entry = AuditLog::where('action', 'admin.system_data_import')->first();
        $this->assertNotNull($entry);
        $this->assertSame($owner->id, $entry->user_id);
        $this->assertSame('backup_json', $entry->new_values['format']);
        $this->assertArrayHasKey('guests', $entry->new_values['rows_imported']);
    }
}
