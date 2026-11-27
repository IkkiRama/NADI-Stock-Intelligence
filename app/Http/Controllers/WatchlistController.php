<?php

namespace App\Http\Controllers;

use App\Models\Instrument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WatchlistController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'instrument_id' => ['required', 'integer', 'exists:instruments,id'],
        ]);

        $watchlist = $request->user()->ensureDefaultWatchlist();

        $watchlist->items()->firstOrCreate(
            ['instrument_id' => $validated['instrument_id']],
            ['sort_order' => $watchlist->items()->count()],
        );

        return back();
    }

    public function destroy(Request $request, Instrument $instrument): RedirectResponse
    {
        $watchlist = $request->user()->watchlists()->where('is_default', true)->first();

        if ($watchlist) {
            $watchlist->items()->where('instrument_id', $instrument->id)->delete();
        }

        return back();
    }
}
