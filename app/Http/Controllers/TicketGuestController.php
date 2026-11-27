<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «Invitado» (SPEC-event-panel AC-PANEL-11, 2026-11-27): owner and collaborators
 * mark a registered ticket as a guest — it stops counting against the capacity,
 * so its spot goes back to the public and the summary reads «120 / 150 + 8
 * invitados» — or count it again, which needs room, counted under the event-row
 * lock registrations use. A released ticket is neither. The door scans a guest
 * like anyone else, and nobody is mailed. `guest` is exactly '1' (mark) or '0'.
 */
class TicketGuestController extends Controller
{
    public function __invoke(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->event->canBeManagedBy($request->user()), 403);

        $request->validate(['guest' => ['required', 'in:0,1']]);
        $guest = $request->boolean('guest'); // '1', 1 and true mark; '0', 0 and false count again

        $outcome = DB::transaction(function () use ($ticket, $guest): string {
            $event = Event::query()->whereKey($ticket->event_id)->lockForUpdate()->firstOrFail();
            $fresh = Ticket::query()->whereKey($ticket->getKey())->firstOrFail();

            if ($fresh->status === TicketStatus::Revoked) {
                return 'released';
            }
            if ($fresh->is_guest === $guest) {
                return 'unchanged';
            }
            if (! $guest && $event->capacity !== null && $event->tickets()->countsAgainstCapacity()->count() >= $event->capacity) {
                return 'full';
            }

            $fresh->forceFill(['is_guest' => $guest])->save();

            return $guest ? 'marked' : 'unmarked';
        });

        return match ($outcome) {
            'marked' => back()->with('status', __(':name\'s ticket is now a guest one: it no longer counts against the capacity.', ['name' => $ticket->attendee_name])),
            'unmarked' => back()->with('status', __(':name\'s ticket counts against the capacity again.', ['name' => $ticket->attendee_name])),
            'full' => back()->withErrors(['guest' => __('There is no room to count this ticket against the capacity again: raise the capacity first.')]),
            'released' => back()->withErrors(['guest' => __('A released spot cannot be a guest one.')]),
            default => back(),
        };
    }
}
