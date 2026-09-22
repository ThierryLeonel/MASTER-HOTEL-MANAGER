<?php

namespace App\Services\Import;

use App\Exceptions\HmsException;
use App\Models\Department;
use App\Models\Guest;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\Admin\UserAdminService;
use App\Services\AuditLogger;
use App\Services\FrontOffice\ReservationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * "I want to be able to import data to the system" — a non-technical
 * owner's counterpart to the Master Admin Export button (which already
 * downloads CSVs) and the everyday need to bring an existing guest list,
 * room list, menu, and staff roster into this system instead of retyping
 * everything by hand.
 *
 * Deliberately CSV, not .xlsx: the export feature (SystemDataService)
 * already only produces CSV, this project's own README targets no-SSH
 * shared hosting where a new Composer dependency (e.g. PhpSpreadsheet)
 * can't reliably be installed after the fact, and every spreadsheet tool
 * a hotel owner already has (Excel, Google Sheets, LibreOffice) can save
 * a sheet as CSV in one click ("Save As" / "Download as" -> CSV). Every
 * template this service generates is itself a valid CSV a spreadsheet
 * program opens directly.
 *
 * Every import is a two-step, review-before-write flow, the same shape
 * as the Master Admin Reset confirmation (PHASE9_AUDIT_AND_PLAN.md
 * §20.12): preview() parses and validates every row but writes nothing,
 * so a mistake in the file is caught and shown, row by row, before
 * anything touches the database; commit() re-reads the exact same
 * uploaded file (by its storage token) and only then actually writes.
 * Nothing here bypasses this project's existing business rules — a
 * reservation row is created through the same ReservationService::create()
 * a member of staff filling in the on-screen form would go through, and
 * a staff row is created through the same UserAdminService::create() the
 * Users admin screen uses, so an imported row can never end up in a state
 * the rest of the system doesn't already know how to handle.
 *
 * Staff import never accepts a password or PIN from the spreadsheet — a
 * plaintext credential column is exactly the kind of thing that ends up
 * emailed, printed, or left open in a spreadsheet program, and this
 * project already goes out of its way elsewhere (backupJson()'s
 * credential stripping) to keep login secrets out of exported/imported
 * files. Instead a random credential is generated per new account and
 * handed back once, in the commit response only, never written to the
 * audit log or anywhere else.
 */
class SpreadsheetImportService
{
    private const MAX_ROWS = 2000;

    private const GUEST_ID_DOCUMENT_TYPES = ['passport', 'national_id', 'drivers_license', 'other'];

    private const ROOM_STATUSES = ['available', 'maintenance', 'out_of_service'];

    private const HOUSEKEEPING_STATUSES = ['clean', 'dirty', 'inspected'];

    private const AUTH_TYPES = ['password_only', 'password_mfa', 'pin'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReservationService $reservationService,
        private readonly UserAdminService $userAdminService,
    ) {
    }

    // -----------------------------------------------------------------
    // Shared upload / CSV plumbing
    // -----------------------------------------------------------------

    /**
     * Stores an uploaded CSV under a random token so a second request
     * (the "confirm" step) can re-read the exact same file without
     * making the browser re-upload it. Never trust a token from a
     * request that doesn't match this format — see rows()'s guard.
     */
    public function storeUpload(UploadedFile $file): string
    {
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            throw new HmsException('Please upload a .csv file — save your spreadsheet as "CSV (Comma delimited)" first, then upload that file.');
        }

        $token = (string) Str::uuid();
        $file->storeAs('imports', $token.'.csv', 'local');

        return $token;
    }

    public function discardUpload(string $token): void
    {
        Storage::disk('local')->delete($this->relativePath($token));
    }

    private function relativePath(string $token): string
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $token)) {
            throw new HmsException('That import file has expired or the link was invalid — please upload the file again.');
        }

        return "imports/{$token}.csv";
    }

    /**
     * @return array{header: list<string>, rows: list<array{line:int, data: array<string,string>}>}
     */
    private function rows(string $token): array
    {
        $path = $this->relativePath($token);

        if (! Storage::disk('local')->exists($path)) {
            throw new HmsException('That import file has expired or was already used — please upload the file again.');
        }

        $handle = fopen(Storage::disk('local')->path($path), 'r');
        if ($handle === false) {
            throw new HmsException('Could not read the uploaded file.');
        }

        $header = fgetcsv($handle);
        if ($header === false || $header === null) {
            fclose($handle);
            throw new HmsException('That file is empty — it needs a header row followed by your data.');
        }

        // Strip a UTF-8 byte-order mark Excel sometimes adds to the very
        // first cell, and normalize every header to snake_case so "First
        // Name", "first name", and "first_name" are all accepted.
        $header = array_map(fn (string $h) => $this->normalizeHeader($h), $header);
        $header[0] = ltrim($header[0], "\xEF\xBB\xBF");

        $rows = [];
        $line = 1;
        while (($raw = fgetcsv($handle)) !== false) {
            $line++;
            if ($raw === [null] || $raw === false) {
                continue;
            }
            // A short/ragged row (fewer commas than the header) pads with
            // '' rather than being silently misaligned.
            $raw = array_pad($raw, count($header), '');
            $data = array_combine($header, array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_slice($raw, 0, count($header))));

            // Skip a fully-blank row (a common trailing artifact when
            // exporting from Excel) rather than reporting it as an error.
            if (implode('', $data) === '') {
                continue;
            }

            $rows[] = ['line' => $line, 'data' => $data];

            if (count($rows) > self::MAX_ROWS) {
                fclose($handle);
                throw new HmsException('This file has more than '.self::MAX_ROWS.' rows — please split it into smaller files and import them one at a time.');
            }
        }

        fclose($handle);

        return ['header' => $header, 'rows' => $rows];
    }

    private function normalizeHeader(string $h): string
    {
        return strtolower(trim(preg_replace('/[\s\-]+/', '_', trim($h))));
    }

    private function missingHeaders(array $header, array $required): array
    {
        return array_values(array_diff($required, $header));
    }

    private function streamCsv(array $header, array $exampleRow): string
    {
        $csv = fopen('php://temp', 'w+');
        fputcsv($csv, $header);
        fputcsv($csv, $exampleRow);
        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        return $content;
    }

    private function parseBool(?string $value, bool $default): bool
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'true', 'yes', 'y', 'active'], true);
    }

    // -----------------------------------------------------------------
    // Guests
    // -----------------------------------------------------------------

    public const GUEST_HEADERS = [
        'first_name', 'last_name', 'phone', 'email', 'id_document_type',
        'id_document_number', 'nationality', 'address', 'date_of_birth', 'notes',
    ];

    public function guestTemplateCsv(): string
    {
        return $this->streamCsv(self::GUEST_HEADERS, [
            'John', 'Doe', '+237600000000', 'john@example.com', 'passport',
            'A1234567', 'Cameroonian', '123 Main St, Douala', '1990-05-14', 'Repeat guest, prefers a quiet room',
        ]);
    }

    public function previewGuests(string $token): array
    {
        return $this->buildPreview($token, self::GUEST_HEADERS, ['first_name', 'last_name'], fn (array $data) => $this->resolveGuestRow($data));
    }

    public function commitGuests(string $token, User $actor): array
    {
        return $this->runCommit($token, self::GUEST_HEADERS, ['first_name', 'last_name'],
            fn (array $data) => $this->resolveGuestRow($data),
            function (array $resolved) use ($actor) {
                if ($resolved['action'] === 'update') {
                    $guest = Guest::findOrFail($resolved['match_id']);
                    $guest->update($resolved['normalized']);
                } else {
                    $guest = Guest::create([...$resolved['normalized'], 'created_by' => $actor->id]);
                }

                return $guest;
            },
            'admin.import_guests',
            $actor,
        );
    }

    private function resolveGuestRow(array $data): array
    {
        // A user who doesn't use a given optional column (e.g. deletes
        // the whole 'notes' column from the template rather than leaving
        // it blank) must not cause a PHP "undefined array key" warning —
        // every expected column is guaranteed present here, defaulting
        // to '', before any of the checks below read it.
        $data = [...array_fill_keys(self::GUEST_HEADERS, ''), ...$data];

        $errors = [];

        if (($data['first_name'] ?? '') === '') {
            $errors[] = 'First name is required.';
        }
        if (($data['last_name'] ?? '') === '') {
            $errors[] = 'Last name is required.';
        }
        if (($data['email'] ?? '') !== '' && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email is not a valid email address.';
        }
        $docType = strtolower((string) ($data['id_document_type'] ?? ''));
        if ($docType !== '' && ! in_array($docType, self::GUEST_ID_DOCUMENT_TYPES, true)) {
            $errors[] = 'id_document_type must be one of: '.implode(', ', self::GUEST_ID_DOCUMENT_TYPES).' (or left blank).';
        }
        $dob = null;
        if (($data['date_of_birth'] ?? '') !== '') {
            try {
                $dob = \Illuminate\Support\Carbon::parse($data['date_of_birth'])->toDateString();
            } catch (\Throwable) {
                $errors[] = 'date_of_birth is not a valid date (use YYYY-MM-DD).';
            }
        }

        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'label' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''))];
        }

        $normalized = [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'] !== '' ? $data['phone'] : null,
            'email' => $data['email'] !== '' ? $data['email'] : null,
            'id_document_type' => $docType !== '' ? $docType : null,
            'id_document_number' => $data['id_document_number'] !== '' ? $data['id_document_number'] : null,
            'nationality' => $data['nationality'] !== '' ? $data['nationality'] : null,
            'address' => $data['address'] !== '' ? $data['address'] : null,
            'date_of_birth' => $dob,
            'notes' => $data['notes'] !== '' ? $data['notes'] : null,
        ];

        $existing = ($normalized['phone'] !== null)
            ? Guest::where('phone', $normalized['phone'])->first()
            : null;

        if ($existing) {
            // Only overwrite a field the spreadsheet actually supplied a
            // value for — a blank cell never erases data already on file.
            $normalized = array_filter($normalized, fn ($v) => $v !== null) + $existing->only(array_keys($normalized));

            return ['action' => 'update', 'errors' => [], 'normalized' => $normalized, 'match_id' => $existing->id, 'label' => "{$normalized['first_name']} {$normalized['last_name']} (existing guest, matched by phone)"];
        }

        return ['action' => 'create', 'errors' => [], 'normalized' => $normalized, 'match_id' => null, 'label' => "{$normalized['first_name']} {$normalized['last_name']} (new guest)"];
    }

    // -----------------------------------------------------------------
    // Rooms
    // -----------------------------------------------------------------

    public const ROOM_HEADERS = ['room_number', 'room_category', 'floor', 'status', 'housekeeping_status', 'is_active', 'notes'];

    public function roomTemplateCsv(): string
    {
        $category = RoomCategory::orderBy('id')->value('name') ?? 'Standard';

        return $this->streamCsv(self::ROOM_HEADERS, ['101', $category, '1', 'available', 'clean', 'yes', '']);
    }

    public function previewRooms(string $token): array
    {
        return $this->buildPreview($token, self::ROOM_HEADERS, ['room_number', 'room_category'], fn (array $data) => $this->resolveRoomRow($data));
    }

    public function commitRooms(string $token, User $actor): array
    {
        return $this->runCommit($token, self::ROOM_HEADERS, ['room_number', 'room_category'],
            fn (array $data) => $this->resolveRoomRow($data),
            function (array $resolved) {
                if ($resolved['action'] === 'update') {
                    $room = Room::findOrFail($resolved['match_id']);
                    $room->update($resolved['normalized']);
                } else {
                    $room = Room::create($resolved['normalized']);
                }

                return $room;
            },
            'admin.import_rooms',
            $actor,
        );
    }

    private function resolveRoomRow(array $data): array
    {
        $data = [...array_fill_keys(self::ROOM_HEADERS, ''), ...$data];

        $errors = [];

        if (($data['room_number'] ?? '') === '') {
            $errors[] = 'room_number is required.';
        }

        $category = ($data['room_category'] ?? '') !== ''
            ? RoomCategory::whereRaw('LOWER(name) = ?', [strtolower(trim($data['room_category']))])->first()
            : null;
        if (! $category) {
            $errors[] = 'room_category "'.($data['room_category'] ?? '').'" was not found — add it first under Room Categories, then re-import (spelling must match exactly).';
        }

        $status = strtolower((string) ($data['status'] ?? ''));
        if ($status === 'occupied') {
            $errors[] = 'status cannot be set to "occupied" by import — a room only becomes occupied through an actual check-in.';
        } elseif ($status !== '' && ! in_array($status, self::ROOM_STATUSES, true)) {
            $errors[] = 'status must be one of: '.implode(', ', self::ROOM_STATUSES).' (or left blank for "available").';
        }

        $housekeeping = strtolower((string) ($data['housekeeping_status'] ?? ''));
        if ($housekeeping !== '' && ! in_array($housekeeping, self::HOUSEKEEPING_STATUSES, true)) {
            $errors[] = 'housekeeping_status must be one of: '.implode(', ', self::HOUSEKEEPING_STATUSES).' (or left blank for "clean").';
        }

        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'label' => $data['room_number'] ?? '(no room number)'];
        }

        $existing = Room::where('room_number', $data['room_number'])->first();

        $normalized = [
            'room_number' => $data['room_number'],
            'room_category_id' => $category->id,
            'floor' => $data['floor'] !== '' ? $data['floor'] : null,
            'status' => $status !== '' ? $status : ($existing->status ?? 'available'),
            'housekeeping_status' => $housekeeping !== '' ? $housekeeping : ($existing->housekeeping_status ?? 'clean'),
            'is_active' => $this->parseBool($data['is_active'] ?? null, $existing->is_active ?? true),
            'notes' => $data['notes'] !== '' ? $data['notes'] : ($existing->notes ?? null),
        ];

        // Never touch a currently-occupied room's status/category through
        // import — same guard RoomController::updateStatus() already
        // enforces for the manual "change status" action.
        if ($existing && $existing->status === 'occupied') {
            $normalized['status'] = 'occupied';
        }

        if ($existing) {
            return ['action' => 'update', 'errors' => [], 'normalized' => $normalized, 'match_id' => $existing->id, 'label' => "Room {$normalized['room_number']} (existing, matched by room number)"];
        }

        return ['action' => 'create', 'errors' => [], 'normalized' => $normalized, 'match_id' => null, 'label' => "Room {$normalized['room_number']} (new)"];
    }

    // -----------------------------------------------------------------
    // Menu items (one outlet at a time — the same shape as
    // Pos\MenuController, which is already nested under pos/{outlet})
    // -----------------------------------------------------------------

    public const MENU_ITEM_HEADERS = ['category', 'name', 'description', 'price', 'is_active'];

    public function menuItemTemplateCsv(string $outlet): string
    {
        return $this->streamCsv(self::MENU_ITEM_HEADERS, ['Drinks', 'Mineral Water 50cl', 'Chilled', '500', 'yes']);
    }

    public function previewMenuItems(string $token, string $outlet): array
    {
        return $this->buildPreview($token, self::MENU_ITEM_HEADERS, ['category', 'name', 'price'], fn (array $data) => $this->resolveMenuItemRow($data, $outlet));
    }

    public function commitMenuItems(string $token, string $outlet, User $actor): array
    {
        return $this->runCommit($token, self::MENU_ITEM_HEADERS, ['category', 'name', 'price'],
            fn (array $data) => $this->resolveMenuItemRow($data, $outlet),
            function (array $resolved) {
                if ($resolved['action'] === 'update') {
                    $item = MenuItem::findOrFail($resolved['match_id']);
                    $item->update($resolved['normalized']);
                } else {
                    $item = MenuItem::create($resolved['normalized']);
                }

                return $item;
            },
            'admin.import_menu_items',
            $actor,
        );
    }

    private function resolveMenuItemRow(array $data, string $outlet): array
    {
        $data = [...array_fill_keys(self::MENU_ITEM_HEADERS, ''), ...$data];

        $errors = [];

        if (($data['category'] ?? '') === '') {
            $errors[] = 'category is required.';
        }
        if (($data['name'] ?? '') === '') {
            $errors[] = 'name is required.';
        }
        $price = null;
        if (($data['price'] ?? '') === '' || ! is_numeric($data['price'])) {
            $errors[] = 'price is required and must be a number.';
        } else {
            $price = (float) $data['price'];
        }

        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'label' => $data['name'] ?? '(no name)'];
        }

        // A menu category is simple, low-risk setup data (just a name and
        // an outlet — see MenuController::storeCategory()) so, unlike a
        // room category's required base_rate, it's safe to create
        // automatically here rather than forcing a separate setup step.
        $category = MenuCategory::firstOrCreate(['outlet' => $outlet, 'name' => $data['category']]);

        $normalized = [
            'menu_category_id' => $category->id,
            'outlet' => $outlet,
            'name' => $data['name'],
            'description' => $data['description'] !== '' ? $data['description'] : null,
            'price' => $price,
            'is_active' => $this->parseBool($data['is_active'] ?? null, true),
        ];

        $existing = MenuItem::where('outlet', $outlet)
            ->where('menu_category_id', $category->id)
            ->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])
            ->first();

        if ($existing) {
            return ['action' => 'update', 'errors' => [], 'normalized' => $normalized, 'match_id' => $existing->id, 'label' => "{$normalized['name']} (existing item, matched by name + category)"];
        }

        return ['action' => 'create', 'errors' => [], 'normalized' => $normalized, 'match_id' => null, 'label' => "{$normalized['name']} (new item)"];
    }

    // -----------------------------------------------------------------
    // Staff — create-only, never accepts a password/PIN from the file.
    // -----------------------------------------------------------------

    public const STAFF_HEADERS = ['name', 'username', 'email', 'role', 'department', 'auth_type'];

    public function staffTemplateCsv(): string
    {
        return $this->streamCsv(self::STAFF_HEADERS, ['Jane Smith', 'jsmith', 'jane@example.com', 'front_desk', 'Front Office', 'pin']);
    }

    public function previewStaff(string $token): array
    {
        return $this->buildPreview($token, self::STAFF_HEADERS, ['name', 'username', 'email', 'role'], fn (array $data) => $this->resolveStaffRow($data));
    }

    /**
     * Unlike the other four importers, this one can't share runCommit()'s
     * generic write step unmodified — it needs to hand back a
     * one-time-only generated credential per created account, which
     * nothing else here produces. Kept as its own small transaction
     * rather than bending runCommit() to a shape only this method needs.
     */
    public function commitStaff(string $token, User $actor): array
    {
        $parsed = $this->rows($token);
        $missing = $this->missingHeaders($parsed['header'], ['name', 'username', 'email', 'role']);
        if ($missing !== []) {
            throw new HmsException('The file is missing required column(s): '.implode(', ', $missing).'.');
        }

        $created = 0;
        $skipped = 0;
        $credentials = [];

        DB::transaction(function () use ($parsed, $actor, &$created, &$skipped, &$credentials) {
            foreach ($parsed['rows'] as $row) {
                $resolved = $this->resolveStaffRow($row['data']);
                if ($resolved['action'] !== 'create') {
                    $skipped++;
                    continue;
                }

                $plain = $resolved['normalized']['auth_type'] === 'pin'
                    ? (string) random_int(1000, 9999)
                    : Str::password(12);

                $user = $this->userAdminService->create([
                    ...$resolved['normalized'],
                    'password' => $resolved['normalized']['auth_type'] === 'pin' ? null : $plain,
                    'pin' => $resolved['normalized']['auth_type'] === 'pin' ? $plain : null,
                ], $actor);

                $credentials[] = [
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $resolved['normalized']['role'],
                    'credential_type' => $resolved['normalized']['auth_type'] === 'pin' ? 'PIN' : 'Password',
                    'credential' => $plain,
                ];
                $created++;
            }
        });

        $this->discardUpload($token);

        $this->audit->log('admin.import_staff', 'admin', null, null, ['created' => $created, 'skipped' => $skipped], actor: $actor);

        return ['created' => $created, 'skipped' => $skipped, 'credentials' => $credentials];
    }

    private function resolveStaffRow(array $data): array
    {
        $data = [...array_fill_keys(self::STAFF_HEADERS, ''), ...$data];

        $errors = [];

        foreach (['name', 'username', 'email', 'role'] as $field) {
            if (($data[$field] ?? '') === '') {
                $errors[] = "{$field} is required.";
            }
        }

        if (($data['email'] ?? '') !== '' && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'email is not a valid email address.';
        }

        $role = strtolower((string) ($data['role'] ?? ''));
        if ($role !== '' && ! in_array($role, RolesAndPermissionsSeeder::ROLES, true)) {
            $errors[] = 'role "'.$data['role'].'" is not a recognized role. Valid roles: '.implode(', ', RolesAndPermissionsSeeder::ROLES).'.';
        }

        $authType = strtolower((string) ($data['auth_type'] ?? '')) ?: 'password_only';
        if (! in_array($authType, self::AUTH_TYPES, true)) {
            $errors[] = 'auth_type must be one of: '.implode(', ', self::AUTH_TYPES).' (or left blank for password_only).';
        }

        $department = null;
        if (($data['department'] ?? '') !== '') {
            $department = Department::whereRaw('LOWER(name) = ?', [strtolower(trim($data['department']))])->first();
            if (! $department) {
                $errors[] = 'department "'.$data['department'].'" was not found — add it first under Departments, or leave this column blank.';
            }
        }

        if (($data['username'] ?? '') !== '' && User::where('username', $data['username'])->exists()) {
            $errors[] = 'username "'.$data['username'].'" already exists — staff import only creates new accounts; edit the existing one from Users instead.';
        }
        if (($data['email'] ?? '') !== '' && User::where('email', $data['email'])->exists()) {
            $errors[] = 'email "'.$data['email'].'" already exists — staff import only creates new accounts; edit the existing one from Users instead.';
        }

        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'label' => $data['name'] ?? '(no name)'];
        }

        $normalized = [
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'role' => $role,
            'department_id' => $department?->id,
            'auth_type' => $authType,
        ];

        return ['action' => 'create', 'errors' => [], 'normalized' => $normalized, 'match_id' => null, 'label' => "{$normalized['name']} ({$role})"];
    }

    // -----------------------------------------------------------------
    // Reservations — always created via ReservationService::create(), so
    // an imported reservation goes through the exact same 'pending'
    // approval gate (spec section 22) as one entered by hand. Guests are
    // matched by phone, never created inline here — import your guest
    // list first (see GUEST_HEADERS above), then reservations against it.
    // -----------------------------------------------------------------

    public const RESERVATION_HEADERS = [
        'guest_phone', 'room_category', 'check_in_date', 'check_out_date',
        'adults', 'children', 'rate_quoted', 'deposit_required', 'notes',
    ];

    public function reservationTemplateCsv(): string
    {
        $category = RoomCategory::orderBy('id')->value('name') ?? 'Standard';
        $rate = RoomCategory::orderBy('id')->value('base_rate') ?? '25000';

        return $this->streamCsv(self::RESERVATION_HEADERS, [
            '+237600000000', $category, now()->addDay()->toDateString(), now()->addDays(3)->toDateString(),
            '2', '0', (string) $rate, '0', 'Imported from spreadsheet',
        ]);
    }

    public function previewReservations(string $token): array
    {
        return $this->buildPreview($token, self::RESERVATION_HEADERS, ['guest_phone', 'room_category', 'check_in_date', 'check_out_date', 'rate_quoted'], fn (array $data) => $this->resolveReservationRow($data));
    }

    public function commitReservations(string $token, User $actor): array
    {
        return $this->runCommit($token, self::RESERVATION_HEADERS, ['guest_phone', 'room_category', 'check_in_date', 'check_out_date', 'rate_quoted'],
            fn (array $data) => $this->resolveReservationRow($data),
            fn (array $resolved) => $this->reservationService->create($resolved['normalized'], $actor),
            'admin.import_reservations',
            $actor,
        );
    }

    private function resolveReservationRow(array $data): array
    {
        $data = [...array_fill_keys(self::RESERVATION_HEADERS, ''), ...$data];

        $errors = [];

        $guest = null;
        if (($data['guest_phone'] ?? '') === '') {
            $errors[] = 'guest_phone is required.';
        } else {
            $guest = Guest::where('phone', $data['guest_phone'])->first();
            if (! $guest) {
                $errors[] = 'No existing guest has phone "'.$data['guest_phone'].'" — import your guest list first, then reservations.';
            }
        }

        $category = null;
        if (($data['room_category'] ?? '') === '') {
            $errors[] = 'room_category is required.';
        } else {
            $category = RoomCategory::whereRaw('LOWER(name) = ?', [strtolower(trim($data['room_category']))])->first();
            if (! $category) {
                $errors[] = 'room_category "'.$data['room_category'].'" was not found.';
            }
        }

        $checkIn = $checkOut = null;
        try {
            $checkIn = ($data['check_in_date'] ?? '') !== '' ? \Illuminate\Support\Carbon::parse($data['check_in_date'])->toDateString() : null;
        } catch (\Throwable) {
            $errors[] = 'check_in_date is not a valid date (use YYYY-MM-DD).';
        }
        try {
            $checkOut = ($data['check_out_date'] ?? '') !== '' ? \Illuminate\Support\Carbon::parse($data['check_out_date'])->toDateString() : null;
        } catch (\Throwable) {
            $errors[] = 'check_out_date is not a valid date (use YYYY-MM-DD).';
        }
        if ($checkIn === null) {
            $errors[] = 'check_in_date is required.';
        }
        if ($checkOut === null) {
            $errors[] = 'check_out_date is required.';
        }
        if ($checkIn && $checkOut && $checkOut <= $checkIn) {
            $errors[] = 'check_out_date must be after check_in_date.';
        }

        $rate = null;
        if (($data['rate_quoted'] ?? '') === '' || ! is_numeric($data['rate_quoted'])) {
            $errors[] = 'rate_quoted is required and must be a number.';
        } else {
            $rate = (float) $data['rate_quoted'];
        }

        if ($errors !== []) {
            return ['action' => 'error', 'errors' => $errors, 'label' => ($data['guest_phone'] ?? '').' / '.($data['room_category'] ?? '')];
        }

        $normalized = [
            'guest_id' => $guest->id,
            'room_category_id' => $category->id,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'adults' => is_numeric($data['adults'] ?? null) ? (int) $data['adults'] : 1,
            'children' => is_numeric($data['children'] ?? null) ? (int) $data['children'] : 0,
            'rate_quoted' => $rate,
            'deposit_required' => is_numeric($data['deposit_required'] ?? null) ? (float) $data['deposit_required'] : 0,
            'notes' => $data['notes'] !== '' ? $data['notes'] : null,
        ];

        // A lightweight safeguard against importing the same file twice
        // by accident — not a hard business rule (a guest can genuinely
        // book the same category twice), just a sensible default.
        $duplicate = Reservation::where('guest_id', $guest->id)
            ->where('room_category_id', $category->id)
            ->where('check_in_date', $checkIn)
            ->where('check_out_date', $checkOut)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->exists();

        if ($duplicate) {
            return ['action' => 'skip', 'errors' => [], 'normalized' => $normalized, 'match_id' => null, 'label' => "{$guest->full_name}, {$category->name}, {$checkIn}\u{2192}{$checkOut} — already imported (skipped)"];
        }

        return ['action' => 'create', 'errors' => [], 'normalized' => $normalized, 'match_id' => null, 'label' => "{$guest->full_name}, {$category->name}, {$checkIn}\u{2192}{$checkOut}"];
    }

    // -----------------------------------------------------------------
    // Generic preview/commit runners shared by Guests/Rooms/Menu Items
    // (Reservations reuses the same shape but calls ReservationService
    // for the actual write; Staff is different enough — a generated
    // credential per row — that it keeps its own commit method above).
    // -----------------------------------------------------------------

    /**
     * @param  callable(array):array  $resolver
     */
    private function buildPreview(string $token, array $expectedHeaders, array $requiredHeaders, callable $resolver): array
    {
        $parsed = $this->rows($token);
        $missing = $this->missingHeaders($parsed['header'], $requiredHeaders);

        $counts = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
        $rows = [];

        if ($missing === []) {
            foreach ($parsed['rows'] as $row) {
                $resolved = $resolver($row['data']);
                $counts[$resolved['action']]++;
                $rows[] = [
                    'line' => $row['line'],
                    'action' => $resolved['action'],
                    'label' => $resolved['label'],
                    'errors' => $resolved['errors'],
                ];
            }
        }

        return [
            'token' => $token,
            'missing_headers' => $missing,
            'rows' => $rows,
            'counts' => [...$counts, 'total' => count($parsed['rows'])],
        ];
    }

    /**
     * @param  callable(array):array  $resolver
     * @param  callable(array):mixed  $writer
     */
    private function runCommit(string $token, array $expectedHeaders, array $requiredHeaders, callable $resolver, callable $writer, string $auditAction, User $actor): array
    {
        $parsed = $this->rows($token);
        $missing = $this->missingHeaders($parsed['header'], $requiredHeaders);
        if ($missing !== []) {
            throw new HmsException('The file is missing required column(s): '.implode(', ', $missing).'.');
        }

        $tally = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];

        DB::transaction(function () use ($parsed, $resolver, $writer, &$tally) {
            foreach ($parsed['rows'] as $row) {
                $resolved = $resolver($row['data']);
                if (! in_array($resolved['action'], ['create', 'update'], true)) {
                    $tally[$resolved['action']]++;
                    continue;
                }

                $writer($resolved);
                $tally[$resolved['action']]++;
            }
        });

        $this->discardUpload($token);

        $this->audit->log($auditAction, 'admin', null, null, $tally, actor: $actor);

        return $tally;
    }
}
