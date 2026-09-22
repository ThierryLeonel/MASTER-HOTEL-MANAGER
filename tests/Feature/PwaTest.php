<?php

namespace Tests\Feature;

use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * "i want to be able to install the application like a standalone and
 * the icon should be the logo image of the hotel in the setting" —
 * PHASE9_AUDIT_AND_PLAN.md §20.14. Covers manifest.json's content
 * (reachable without auth, reflects the current hotel name, falls back
 * to a bundled default icon with no logo uploaded, and points at a
 * properly sized generated icon once one is), the layout emitting the
 * manifest link/theme-color/apple-touch-icon tags, and the service
 * worker file itself being a genuine, deliberately non-caching static
 * file — see each test for what specifically it proves.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_manifest_is_reachable_without_authentication_and_reflects_the_hotel_name(): void
    {
        app(SettingsService::class)->set('hotel_name', 'Jagemn Palace Hotel');

        $response = $this->get('/manifest.json');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/manifest+json');
        $json = $response->json();
        $this->assertSame('Jagemn Palace Hotel', $json['name']);
        $this->assertSame('standalone', $json['display']);
        $this->assertSame(route('dashboard'), $json['start_url']);
        $this->assertNotEmpty($json['icons']);
    }

    public function test_manifest_falls_back_to_the_bundled_default_icon_when_no_logo_has_been_uploaded(): void
    {
        $response = $this->get('/manifest.json');

        $response->assertOk();
        $icons = $response->json('icons');
        $this->assertNotEmpty($icons);
        foreach ($icons as $icon) {
            $this->assertStringContainsString('icons/default-', $icon['src']);
        }
    }

    public function test_manifest_points_at_a_properly_sized_generated_icon_after_a_logo_is_uploaded(): void
    {
        Storage::fake('public');
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $file = UploadedFile::fake()->image('logo.png', 400, 200)->size(500);

        $this->actingAs($owner)->post(route('admin.settings.logo'), ['logo' => $file])->assertRedirect();

        $icon192 = app(SettingsService::class)->hotelPwaIconPath(192);
        $icon512 = app(SettingsService::class)->hotelPwaIconPath(512);
        $this->assertNotNull($icon192);
        $this->assertNotNull($icon512);
        Storage::disk('public')->assertExists($icon192);
        Storage::disk('public')->assertExists($icon512);

        // The generated file is actually a real, correctly-sized square
        // PNG — not just "some file exists at that path" — proving
        // PwaIconService really resized/padded the 400x200 upload into a
        // proper square icon rather than passing it through unchanged.
        $info = getimagesizefromstring(Storage::disk('public')->get($icon192));
        $this->assertSame(192, $info[0]);
        $this->assertSame(192, $info[1]);

        $response = $this->get('/manifest.json');
        $icons = collect($response->json('icons'))->keyBy('sizes');
        $this->assertTrue($icons->has('192x192'));
        $this->assertTrue($icons->has('512x512'));
        $this->assertStringContainsString($icon192, $icons['192x192']['src']);
    }

    public function test_uploading_a_new_logo_discards_the_old_generated_pwa_icons(): void
    {
        Storage::fake('public');
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $this->actingAs($owner)->post(route('admin.settings.logo'), [
            'logo' => UploadedFile::fake()->image('first.png', 300, 300)->size(500),
        ]);
        $firstIcon192 = app(SettingsService::class)->hotelPwaIconPath(192);
        Storage::disk('public')->assertExists($firstIcon192);

        $this->actingAs($owner)->post(route('admin.settings.logo'), [
            'logo' => UploadedFile::fake()->image('second.png', 300, 300)->size(500),
        ]);
        $secondIcon192 = app(SettingsService::class)->hotelPwaIconPath(192);

        Storage::disk('public')->assertExists($secondIcon192);
        Storage::disk('public')->assertMissing($firstIcon192);
    }

    public function test_authenticated_layout_emits_the_manifest_link_theme_color_and_apple_touch_icon(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('rel="manifest" href="'.route('pwa.manifest').'"', false);
        $response->assertSee('name="theme-color"', false);
        $response->assertSee('rel="apple-touch-icon"', false);
    }

    public function test_login_screen_also_emits_the_manifest_link(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('rel="manifest"', false);
    }

    public function test_service_worker_file_exists_and_is_deliberately_non_caching(): void
    {
        $path = public_path('sw.js');
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        // The whole point of this file (see its own docblock) is that it
        // never caches anything in a live, transactional hotel system —
        // this asserts that guarantee against the actual shipped file
        // content, not just that a file with this name exists.
        $this->assertStringNotContainsString('caches.open', $contents);
        $this->assertStringNotContainsString('respondWith', $contents);
        $this->assertStringContainsString("addEventListener('fetch'", $contents);
    }
}
