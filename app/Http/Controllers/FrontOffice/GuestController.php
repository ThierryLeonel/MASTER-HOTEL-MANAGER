<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * Guest CRUD. Route-level `permission:manage-guests` middleware (see
 * routes/web.php) is the server-side gate — enforced regardless of which
 * navigation links a given role's UI happens to show.
 */
class GuestController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request)
    {
        $guests = Guest::query()
            ->when($request->boolean('trashed'), fn ($q) => $q->onlyTrashed())
            ->when($request->search, fn ($q, $search) => $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate(20);

        return view('front-office.guests.index', compact('guests'));
    }

    public function create()
    {
        return view('front-office.guests.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'id_document_type' => 'nullable|string|max:40',
            'id_document_number' => 'nullable|string|max:100',
            'nationality' => 'nullable|string|max:80',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $guest = Guest::create([...$data, 'created_by' => $request->user()->id]);

        // Post-9.8 fix round ("if we start by creating a guest first, we
        // should be able to see 2 buttons, check in and reservation"): the
        // `new=1` flag tells guests.show to surface those two buttons
        // prominently instead of the guest just landing on a bare profile
        // page with no obvious next step.
        return redirect()->route('guests.show', ['guest' => $guest, 'new' => 1])->with('status', 'Guest created.');
    }

    public function show(Guest $guest)
    {
        $guest->load(['reservations' => fn ($q) => $q->latest(), 'stays' => fn ($q) => $q->latest()]);

        return view('front-office.guests.show', compact('guest'));
    }

    /**
     * Broader request ("...edit, delete and update all categories and
     * every input, except financial inputs"): correcting a guest's own
     * personal details (name, phone, email, ID, nationality, address,
     * notes, blacklist status). Nothing financial lives on this record —
     * a guest's payments/folios/deposits are separate rows keyed off
     * guest_id and are never touched here.
     */
    public function update(Request $request, Guest $guest)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'id_document_type' => 'nullable|string|max:40',
            'id_document_number' => 'nullable|string|max:100',
            'nationality' => 'nullable|string|max:80',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
            'is_blacklisted' => 'nullable|boolean',
            'blacklist_reason' => 'nullable|string|max:500',
        ]);
        $data['is_blacklisted'] = $request->boolean('is_blacklisted');

        $oldValues = $guest->only(array_keys($data));
        $guest->update($data);

        $this->audit->log('front_office.guest_updated', 'front_office', $guest, oldValues: $oldValues, newValues: $guest->only(array_keys($data)), actor: $request->user());

        return back()->with('status', 'Guest details updated.');
    }

    /**
     * Guest already uses SoftDeletes (see the model/migration), so this
     * never actually erases the row — every stay/reservation/payment that
     * points at this guest keeps working exactly as before, and nothing
     * historical is rewritten. "Delete" here just removes the guest from
     * the active list/search; restore() below reverses it. This is
     * intentionally unconditional (no history check) because, unlike a
     * hard delete, there is nothing here to lose.
     */
    public function destroy(Request $request, Guest $guest)
    {
        $name = $guest->full_name;
        $this->audit->log('front_office.guest_deleted', 'front_office', $guest, oldValues: $guest->toArray(), actor: $request->user());
        $guest->delete();

        return redirect()->route('guests.index')->with('status', "Guest \"{$name}\" removed. This can be undone from the trash by an Owner.");
    }

    public function restore(Request $request, int $guest)
    {
        $model = Guest::onlyTrashed()->findOrFail($guest);
        $model->restore();

        $this->audit->log('front_office.guest_restored', 'front_office', $model, actor: $request->user());

        return redirect()->route('guests.show', $model)->with('status', "Guest \"{$model->full_name}\" restored.");
    }

    /**
     * Post-9.8 fix round ("a text box that has the characteristic to
     * search from the existing guest list"): a small JSON lookup used by
     * the check-in and reservation "search or add guest" widgets (and
     * anywhere else a guest picker needs live search) instead of loading
     * every guest into a giant <select> up front. Same permission
     * (manage-guests) as the rest of this controller — no new exposure.
     */
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json([]);
        }

        $guests = Guest::query()
            ->where(function ($query) use ($q) {
                $query->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            })
            ->orderBy('first_name')
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'phone', 'email']);

        return response()->json($guests->map(fn (Guest $g) => [
            'id' => $g->id,
            'full_name' => $g->full_name,
            'first_name' => $g->first_name,
            'last_name' => $g->last_name,
            'phone' => $g->phone,
            'email' => $g->email,
        ])->values());
    }
}
