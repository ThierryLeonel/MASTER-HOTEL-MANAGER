{{--
    New requirement 7 ("hotel logo fix + browser favicon"): diagnosed root
    cause — this project had NO <link rel="icon" ...> tag anywhere at all,
    so every browser tab showed its own generic default icon regardless
    of whether a hotel logo had ever been uploaded. Reuses the exact same
    uploaded logo (SettingsController::uploadLogo(), stored on the
    "public" disk) rather than a separate favicon upload — one logo, one
    source of truth, consistent with how the sidebar/login logo already
    works. Falls back to nothing (the browser's own default) when no logo
    has been uploaded yet, rather than a broken image request.

    This tag alone does not fix a missing storage symlink — see
    DeployHelperController::storageLink() for that half of the diagnosis;
    both are required for the icon to actually render.
--}}
@php($faviconPath = app(\App\Services\SettingsService::class)->hotelLogoPath())
@if ($faviconPath)
    <link rel="icon" type="image/png" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($faviconPath) }}">
@endif
