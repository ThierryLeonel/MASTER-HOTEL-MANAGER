{{--
    Shared preview-table partial for every CSV import screen (Guests,
    Reservations, Rooms, Menu items). Expects: $preview (from
    SpreadsheetImportService's buildPreview()), $commitRoute, $backRoute,
    and $noun (e.g. "guests").
--}}
@if (!empty($preview['missing_headers']))
    <div class="rounded-lg border-2 border-red-300 bg-red-50 p-4 text-sm text-red-800">
        <p class="font-semibold">This file is missing required column(s): {{ implode(', ', $preview['missing_headers']) }}.</p>
        <p class="mt-1">Download the template again and check your column headers match exactly, then re-upload.</p>
    </div>
    <a href="{{ $backRoute }}" class="mt-4 inline-block text-sm text-slate-600 hover:underline">&larr; Try again</a>
@endif

@if (empty($preview['missing_headers']))
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="flex flex-wrap gap-4 text-sm">
            <span class="rounded bg-green-100 px-2 py-1 text-green-800">{{ $preview['counts']['create'] }} to add</span>
            <span class="rounded bg-blue-100 px-2 py-1 text-blue-800">{{ $preview['counts']['update'] }} to update</span>
            <span class="rounded bg-slate-100 px-2 py-1 text-slate-600">{{ $preview['counts']['skip'] }} to skip</span>
            <span class="rounded bg-red-100 px-2 py-1 text-red-800">{{ $preview['counts']['error'] }} with errors (won't be imported)</span>
            <span class="text-slate-400">{{ $preview['counts']['total'] }} rows total</span>
        </div>
    </div>

    <div class="mt-4 max-h-96 overflow-y-auto rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="sticky top-0 bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-2">Row</th><th class="p-2">Action</th><th class="p-2">Details</th></tr>
            </thead>
            <tbody class="divide-y">
                @foreach ($preview['rows'] as $row)
                    <tr class="{{ $row['action'] === 'error' ? 'bg-red-50' : '' }}">
                        <td class="p-2 text-slate-400">{{ $row['line'] }}</td>
                        <td class="p-2">
                            @switch($row['action'])
                                @case('create') <span class="text-green-700">Add</span> @break
                                @case('update') <span class="text-blue-700">Update</span> @break
                                @case('skip') <span class="text-slate-500">Skip</span> @break
                                @default <span class="text-red-700">Error</span>
                            @endswitch
                        </td>
                        <td class="p-2">
                            {{ $row['label'] }}
                            @if ($row['errors'])
                                <ul class="mt-1 list-disc pl-4 text-xs text-red-700">
                                    @foreach ($row['errors'] as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex items-center gap-3">
        @if ($preview['counts']['create'] + $preview['counts']['update'] > 0)
            <form method="POST" action="{{ $commitRoute }}">
                @csrf
                <input type="hidden" name="token" value="{{ $preview['token'] }}">
                @foreach (($extraFields ?? []) as $fieldName => $fieldValue)
                    <input type="hidden" name="{{ $fieldName }}" value="{{ $fieldValue }}">
                @endforeach
                <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">
                    Confirm import ({{ $preview['counts']['create'] + $preview['counts']['update'] }} rows)
                </button>
            </form>
        @else
            <p class="text-sm text-slate-500">Nothing to import yet — fix the errors above and upload the file again.</p>
        @endif
        <a href="{{ $backRoute }}" class="text-sm text-slate-600 hover:underline">Start over</a>
    </div>
@endif
