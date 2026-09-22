<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * "the icon should be the logo image of the hotel in the setting" — the
 * installable-app icon (§20.14) has to be the hotel's own logo, but a
 * logo an owner uploads through Settings can be any shape/resolution
 * (a wide letterhead crop, a tiny screenshot, whatever they have on
 * hand), while a PWA icon needs to be a proper square PNG at a couple of
 * fixed sizes (192x192, 512x512 — the two sizes every mainstream browser
 * actually asks a manifest for). This service does that conversion once,
 * at upload time (see SettingsController::uploadLogo()), rather than on
 * every manifest.json request — resizing an arbitrary image on every
 * page load/install-prompt check would be needless repeated work for
 * something that only ever changes when the owner uploads a new logo.
 *
 * Deliberately "contain", not "cover" or "stretch": the logo is scaled to
 * fit entirely inside the square canvas, centered, over a plain white
 * background — never cropped (a logo with text near its edges staying
 * fully legible matters more here than filling every pixel of the icon),
 * and never distorted by forcing a non-square logo into a square frame.
 * White rather than transparent specifically because a transparent PNG
 * home-screen icon renders as solid black on iOS, not "see-through."
 *
 * Defensive fallback (this project's established pattern — see
 * `class_exists(\ZipArchive::class)` in SystemDataService): if the `gd`
 * PHP extension isn't available on the actual deployment host,
 * generateFrom() returns an empty array rather than fataling, and the
 * caller (PwaController::manifest()) falls back to serving the original,
 * un-resized logo file directly with a generic "any" size hint instead —
 * a same-origin app icon that might not be a perfect square still
 * installs, it just isn't guaranteed to look as clean as a properly
 * sized one would.
 */
class PwaIconService
{
    public const SIZES = [192, 512];

    public function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /**
     * @return array<int,string> map of size => path on the `public` disk (e.g. 'settings/pwa-icon-192.png'), or [] if GD isn't available or the file couldn't be read as an image.
     */
    public function generateFrom(UploadedFile $logo): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $source = $this->loadImage($logo->getRealPath(), strtolower((string) $logo->getClientOriginalExtension()));
        if (! $source) {
            return [];
        }

        $paths = [];

        foreach (self::SIZES as $size) {
            $canvas = imagecreatetruecolor($size, $size);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);

            $srcW = imagesx($source);
            $srcH = imagesy($source);
            $scale = min($size / max($srcW, 1), $size / max($srcH, 1));
            $newW = max(1, (int) round($srcW * $scale));
            $newH = max(1, (int) round($srcH * $scale));
            $dstX = (int) (($size - $newW) / 2);
            $dstY = (int) (($size - $newH) / 2);

            imagecopyresampled($canvas, $source, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);

            $path = "settings/pwa-icon-{$size}.png";
            ob_start();
            imagepng($canvas);
            $contents = ob_get_clean();
            imagedestroy($canvas);

            if ($contents === false || $contents === '') {
                continue;
            }

            Storage::disk('public')->put($path, $contents);
            $paths[$size] = $path;
        }

        imagedestroy($source);

        return $paths;
    }

    /**
     * @param  array<int,string|null>  $paths
     */
    public function discard(array $paths): void
    {
        foreach ($paths as $path) {
            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    /**
     * @return resource|\GdImage|null
     */
    private function loadImage(string $path, string $extension)
    {
        $image = match ($extension) {
            'png' => @imagecreatefrompng($path),
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            default => false,
        };

        return $image !== false ? $image : null;
    }
}
