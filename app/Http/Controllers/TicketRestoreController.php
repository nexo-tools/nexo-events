<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «Devolver el lugar» (ADR-011 §6, 2026-11-27): the undo of releasing a spot.
 * The ticket goes back to `valid` — the same QR gets in again; nothing is sent
 * to the person, as releasing sends nothing — only while the event has room
 * for it. The room is counted under the same event-row lock as a registration
 * (EventRegistrar), so a restore and a last-spot sign-up cannot both win. A
 * cancelled event gives nothing back. (AC-COLLAB-19)
 */
class TicketRestoreController extends Controller
{
    public function __invoke(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->event->canBeManagedBy($request->user()), 403);

        $outcome = DB::transaction(function () use ($ticket): string {
            $event = Event::query()->whereKey($ticket->event_id)->lockForUpdate()->firstOrFail();

            if (in_array($event->status, [EventStatus::Cancelled, EventStatus::Killed], true)) {
                return 'inactive';
            }

            // A guest takes no room (AC-PANEL-11), so it comes back even when the event is full.
            $isGuest = (bool) Ticket::query()->whereKey($ticket->getKey())->value('is_guest');
            if (! $isGuest && $event->capacity !== null && $event->tickets()->countsAgainstCapacity()->count() >= $event->capacity) {
                return 'full';
            }

            // Conditional: only a released ticket comes back (a double click is a no-op).
            return Ticket::query()
                ->whereKey($ticket->getKey())
                ->where('status', TicketStatus::Revoked->value)
                ->update(['status' => TicketStatus::Valid->value, 'updated_at' => now()]) === 1 ? 'restored' : 'unchanged';
        });

        return match ($outcome) {
            'restored' => back()->with('status', __('Spot given back. That ticket gets in again.')),
            'full' => back()->withErrors(['restore' => __('There is no room left to give this spot back: raise the capacity or release another spot first.')]),
            'inactive' => back()->withErrors(['restore' => __('This event was cancelled: its spots cannot be given back.')]),
            default => back(),
        };
    }
}
