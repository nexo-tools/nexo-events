<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('app.dashboard', [
            // views_count is unique visitor-days, not hits: the number an organizer
            // actually wants, and the only one the cookieless counter can honestly give.
            'events' => $request->user()->events()->withCount(['tickets', 'views'])->latest()->get(),
            // Events somebody else owns and invited this account to manage
            // (ADR-011), while the access window is open. Never the user's own:
            // those are already listed above.
            'managed' => Event::query()
                ->where('organizer_id', '!=', $request->user()->id)
                ->where('starts_at', '>', now()->subDays(Event::COLLABORATOR_ACCESS_DAYS))
                ->whereHas('collaborators', fn ($q) => $q
                    ->where('user_id', $request->user()->id)
                    ->whereNotNull('accepted_at')
                    ->whereNull('revoked_at'))
                ->withCount('tickets')
                ->orderBy('starts_at')
                ->get(),
        ]);
    }
}
