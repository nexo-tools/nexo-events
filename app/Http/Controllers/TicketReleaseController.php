<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Release a spot" (ADR-011 §6): the ticket goes to `revoked`, which capacity
 * already excludes and the door already rejects. For the reservation that was
 * never paid at an event with a capacity. Someone already inside cannot be
 * released — the update is conditional, so it cannot race a check-in into
 * undoing an entry.
 */
class TicketReleaseController extends Controller
{
    public function __invoke(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->event->canBeManagedBy($request->user()), 403);

        $released = Ticket::query()
            ->whereKey($ticket->getKey())
            ->where('status', TicketStatus::Valid->value)
            ->whereDoesntHave('checkin')
            ->update(['status' => TicketStatus::Revoked->value, 'updated_at' => now()]);

        if ($released === 0 && $ticket->fresh()?->status !== TicketStatus::Revoked) {
            return back()->withErrors(['release' => __('That person already checked in: their spot cannot be released.')]);
        }

        return back()->with('status', __('Spot released. That ticket no longer gets in.'));
    }
}
