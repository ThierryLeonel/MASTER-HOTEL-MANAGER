<?php

namespace App\Http\Controllers;

use App\Services\PwaIconService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * "i want to be able to install the application like a standalone and
 * the icon should be the logo image of the hotel in the setting" —
 * §20.14. A web app manifest, served from a real Laravel route rather
 * than a static `public/manifest.json` file, specifically because its
 * `icons` have to point at whatever logo is *currently* configured in
 * Settings — a static file can't do that, and this system already has
 * exactly the right place to read that from (`SettingsService`).
 *
 * Deliberately outside the `auth` middleware group (see routes/web.php):
 * a browser fetches a page's manifest to decide whether to offer the
 * "Install app" prompt before it necessarily knows whether the visitor
 * is logged in, and a manifest is not sensitive data — it's the same
 * hotel name/icon every visitor to the login page already sees.
 *
 * The service worker itself (`public/sw.js`) is a genuine static file —
 * installability requires a *registered* service worker with a fetch
 * handler, but this system is a live, transactional hotel system where
 * silently serving a stale cached page (a folio balance, a room's
 * status) would be actively dangerous, so sw.js is deliberately a
 * pass-through no-op: it never caches anything, it only exists to
 * satisfy the installability requirement itself.
 */
class PwaController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly PwaIconService $pwaIcons,
    ) {
    }

    public function manifest(): JsonResponse
    {
        $name = $this->settings->hotelName();

        return response()->json([
            'name' => $name,
            // A manifest's short_name is shown under the home-screen icon,
            // where a long hotel name would be truncated awkwardly by the
            // OS anyway — trimmed here to a sane length instead.
            'short_name' => \Illuminate\Support\Str::limit($name, 24, ''),
            'start_url' => route('dashboard'),
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#f8fafc',
            // Matches this layout's own sidebar/header background
            // (slate-900) — see layouts/app.blade.php.
            'theme_color' => '#0f172a',
            'icons' => $this->icons(),
        ])->header('Content-Type', 'application/manifest+json');
    }

    /**
     * @return list<array{src:string,sizes:string,type:string}>
     */
    private function icons(): array
    {
        $icons = [];

        foreach (PwaIconService::SIZES as $size) {
            $path = $this->settings->hotelPwaIconPath($size);
            if ($path && Storage::disk('public')->exists($path)) {
                $icons[] = [
                    'src' => Storage::disk('public')->url($path),
                    'sizes' => "{$size}x{$size}",
                    'type' => 'image/png',
                ];
            }
        }

        if ($icons !== []) {
            return $icons;
        }

        // No logo uploaded yet, or the deployment host has no `gd`
        // extension to have generated a properly sized icon from it
        // (PwaIconService::isAvailable()) — fall back to the raw logo
        // file directly, with a generic "any" size hint rather than
        // claiming a fixed dimension the file may not actually be, so
        // installing still works even if the icon isn't perfectly
        // square. If there's no logo at all yet, fall back further to
        // this project's own bundled default icon so an installed app
        // never shows a broken image.
        if ($rawLogo = $this->settings->hotelLogoPath()) {
            $extension = strtolower(pathinfo($rawLogo, PATHINFO_EXTENSION));

            return [[
                'src' => Storage::disk('public')->url($rawLogo),
                'sizes' => 'any',
                'type' => in_array($extension, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/png',
            ]];
        }

        return [
            ['src' => asset('icons/default-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => asset('icons/default-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
        ];
    }
}
