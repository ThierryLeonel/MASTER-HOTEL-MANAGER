@extends('layouts.app')
@section('title', 'Import Menu')
@section('content')

@php
    $outlets = ['bar' => 'Bar', 'restaurant' => 'Restaurant', 'front_desk' => 'Front Desk', 'bar_outside' => 'Bar Outside', 'bucareaux' => 'Bucareaux'];
@endphp

<div class="mb-4 flex items-center justify-between">
    <h1 class="text-lg font-semibold">Import a menu from a spreadsheet</h1>
    <a href="{{ route('admin.system-data.import.index') }}" class="text-sm text-slate-600 hover:underline">&larr; Back to Import Data</a>
</div>

<div class="mb-4 flex flex-wrap gap-2">
    @foreach ($outlets as $value => $label)
        <a href="{{ route('admin.system-data.import.menu', ['outlet' => $value]) }}"
           class="rounded px-3 py-1.5 text-sm {{ $outlet === $value ? 'bg-slate-800 text-white' : 'bg-slate-200 hover:bg-slate-300' }}">{{ $label }}</a>
    @endforeach
</div>

@if (!$preview)
    <div class="max-w-2xl space-y-4 rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-slate-600">
            Upload a CSV spreadsheet of the <strong>{{ $outlets[$outlet] }}</strong> menu — a category that doesn't
            exist yet is created automatically, and an item that already exists (same category and name) has its
            price/description updated rather than being duplicated.
            <a href="{{ route('admin.system-data.import.menu.template', ['outlet' => $outlet]) }}" class="font-medium text-slate-800 underline">Download a template</a>
            to see the exact columns expected, fill it in, save as CSV, then upload it below.
        </p>
        <form method="POST" action="{{ route('admin.system-data.import.menu.preview') }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <input type="hidden" name="outlet" value="{{ $outlet }}">
            <input type="file" name="file" accept=".csv,.txt" required class="block w-full text-sm">
            @error('file') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Preview import</button>
        </form>
    </div>
@else
    @include('imports._preview', [
        'commitRoute' => route('admin.system-data.import.menu.commit'),
        'backRoute' => route('admin.system-data.import.menu', ['outlet' => $outlet]),
        'extraFields' => ['outlet' => $outlet],
    ])
@endif

@endsection
