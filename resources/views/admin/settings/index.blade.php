@extends('layouts.app')
@section('title', 'Settings')
@section('content')

<div class="max-w-3xl space-y-6">
    <div class="rounded-lg bg-white p-6 shadow-sm">
        <h2 class="mb-1 text-sm font-semibold">Hotel logo</h2>
        <p class="mb-3 text-xs text-slate-500">Shown at the top of the sidebar on every page, and on printable receipts/invoices. Requires <code>php artisan storage:link</code> to have been run once on this server so uploaded files are web-reachable.</p>
        @if ($logoPath = app(\App\Services\SettingsService::class)->hotelLogoPath())
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($logoPath) }}" alt="Hotel logo" class="mb-3 h-16 w-auto rounded border border-slate-200 bg-white p-1">
        @endif
        <form method="POST" action="{{ route('admin.settings.logo') }}" enctype="multipart/form-data" class="flex items-center gap-2">
            @csrf
            <input type="file" name="logo" accept=".png,.jpg,.jpeg" required class="text-sm">
            <button class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Upload</button>
        </form>
    </div>

    <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-6">
        @csrf
        @foreach ($groups as $groupName => $settings)
            <div class="rounded-lg bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">{{ str_replace('_', ' ', $groupName) }}</h2>
                <div class="space-y-4">
                    @foreach ($settings as $setting)
                        @continue($setting->key === 'hotel_logo_path')
                        <div>
                            <label class="block text-sm font-medium mb-1">{{ str_replace('_', ' ', ucfirst($setting->key)) }}</label>
                            @if (! $setting->is_editable)
                                <input value="{{ $setting->value }}" disabled class="w-full rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">
                            @elseif ($setting->type === 'boolean')
                                <label class="inline-flex items-center gap-2 text-sm">
                                    <input type="hidden" name="settings[{{ $setting->key }}]" value="0">
                                    <input type="checkbox" name="settings[{{ $setting->key }}]" value="1" @checked($setting->castValue())>
                                    Enabled
                                </label>
                            @elseif ($setting->type === 'integer')
                                <input type="number" step="1" name="settings[{{ $setting->key }}]" value="{{ old("settings.{$setting->key}", $setting->value) }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            @elseif ($setting->type === 'decimal')
                                <input type="number" step="0.01" name="settings[{{ $setting->key }}]" value="{{ old("settings.{$setting->key}", $setting->value) }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            @else
                                <input type="text" name="settings[{{ $setting->key }}]" value="{{ old("settings.{$setting->key}", $setting->value) }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            @endif
                            @if ($setting->description)
                                <p class="mt-1 text-xs text-slate-500">{{ $setting->description }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Save settings</button>
    </form>
</div>
@endsection
