<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use Illuminate\Http\Request;

/**
 * Guest CRUD. Route-level `permission:manage-guests` middleware (see
 * routes/web.php) is the server-side gate — enforced regardless of which
 * navigation links a given role's UI happens to show.
 */
class GuestController extends Controller
{
    public function index(Request $request)
    {
        $guests = Guest::query()
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
