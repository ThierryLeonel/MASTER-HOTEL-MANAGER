<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.2 (PHASE9_AUDIT_AND_PLAN.md §3.2): SettingsService and the
 * `settings` table existed since Phase 1 but had no admin screen — every
 * value could only be changed by hand-editing the database until now.
 */
class SettingsAdminTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_owner_can_view_the_settings_screen_grouped_by_category(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $response = $this->actingAs($owner)->get(route('admin.settings.index'));

        $response->assertOk();
        $response->assertSee('finance');
        $response->assertSee('Tax rate percent');
    }

    public function test_owner_can_update_a_decimal_and_an_integer_and_a_string_setting_in_one_request(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $response = $this->actingAs($owner)->post(route('admin.settings.update'), [
            'settings' => [
                'tax_rate_percent' => '20.5',
                'session_timeout_minutes' => '30',
                'hotel_name' => 'Riverside Grand Hotel',
            ],
        ]);

        $response->assertRedirect();
        $settings = app(SettingsService::class);
        $this->assertSame(20.5, $settings->taxRatePercent());
        $this->assertSame(30, $settings->sessionTimeoutMinutes());
        $this->assertSame('Riverside Grand Hotel', $settings->hotelName());
    }

    public function test_a_non_editable_setting_cannot_be_changed_through_this_endpoint(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        Setting::where('key', 'currency')->update(['is_editable' => false]);

        $this->actingAs($owner)->post(route('admin.settings.update'), [
            'settings' => ['currency' => 'USD'],
        ])->assertRedirect();

        $this->assertSame('XAF', app(SettingsService::class)->currency());
    }

    public function test_owner_can_upload_a_hotel_logo(): void
    {
        Storage::fake('public');
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $file = UploadedFile::fake()->image('logo.png', 200, 200)->size(500);

        $response = $this->actingAs($owner)->post(route('admin.settings.logo'), ['logo' => $file]);

        $response->assertRedirect();
        $path = app(SettingsService::class)->hotelLogoPath();
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_non_image_file_is_rejected_as_a_logo(): void
    {
        Storage::fake('public');
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $file = UploadedFile::fake()->create('not-an-image.pdf', 100);

        $response = $this->actingAs($owner)->post(route('admin.settings.logo'), ['logo' => $file]);

        $response->assertSessionHasErrors('logo');
    }

    /**
     * New requirement 7 ("hotel logo fix + browser favicon") diagnosis:
     * this project previously had no <link rel="icon"> tag anywhere at
     * all — see resources/views/partials/favicon.blade.php. Confirms the
     * tag now appears once a logo exists, pointing at that same uploaded
     * file, and is simply absent (not a broken image request) when none
     * has been uploaded yet.
     */
    public function test_the_browser_favicon_reflects_the_uploaded_hotel_logo(): void
    {
        Storage::fake('public');
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $noLogoYet = $this->actingAs($owner)->get(route('dashboard'));
        $noLogoYet->assertDontSee('rel="icon"', false);

        $file = UploadedFile::fake()->image('logo.png', 200, 200)->size(500);
        $this->actingAs($owner)->post(route('admin.settings.logo'), ['logo' => $file]);
        $path = app(SettingsService::class)->hotelLogoPath();

        $withLogo = $this->actingAs($owner)->get(route('dashboard'));
        $withLogo->assertSee('rel="icon"', false);
        $withLogo->assertSee(Storage::disk('public')->url($path), false);
    }

    /** @dataProvider rolesWithoutManageSettings */
    public function test_roles_without_manage_settings_permission_are_forbidden(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $this->actingAs($user)->get(route('admin.settings.index'))->assertForbidden();
    }

    public static function rolesWithoutManageSettings(): array
    {
        return [['duty_manager'], ['accountant'], ['cashier'], ['front_desk'], ['bar_staff'], ['restaurant_staff']];
    }
}
