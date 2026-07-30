<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Ticket;
use App\Services\TicketCheckin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckinController extends Controller
{
    public function scanner(Request $request, Event $event): View
    {
        $this->owned($request, $event);

        return view('app.scan', [
            'event' => $event,
            // Already translated here so scanner.js needs no i18n layer of its
            // own. Built in the controller rather than in a Blade `@php` block:
            // that view also uses the inline `@php(...)` form, and Blade's
            // raw-block regex would match from the FIRST `@php` to the block's
            // `@endphp`, swallowing every directive in between (it renders as a
            // 500). Keeping arrays out of the template avoids the collision.
            'scannerLabels' => [
                'ok' => '✓ '.__('Valid entry'),
                'already' => '✗ '.__('Ticket already used'),
                'revoked' => '✗ '.__('Ticket revoked'),
                'event_inactive' => '✗ '.__('Event cancelled'),
                'unknown' => '✗ '.__('Invalid ticket'),
                'starting' => __('Opening the camera…'),
                'ready' => __('Point at the ticket QR.'),
                'stopped' => __('Camera off.'),
                'noCamera' => __('We could not use the camera. Grant permission, or open this page in Safari or Chrome rather than inside another app.'),
                'offline' => __('No connection. Check the signal and try again.'),
                'throttled' => __('Too many attempts in a row. Wait a few seconds.'),
                'failed' => __('We could not validate the ticket. Try again.'),
            ],
        ]);
    }

    /** Process a scanned/entered token against THIS event's door. */
    public function checkin(Request $request, Event $event, TicketCheckin $checkin): RedirectResponse|JsonResponse
    {
        $this->owned($request, $event);

        // The event is passed INTO the check-in so a ticket from another event is
        // rejected before anything is written. Rejecting it afterwards, as this
        // did, still consumed the ticket at its real event.
        $outcome = $checkin->checkInByToken((string) $request->input('token'), $request->user(), $event);

        // The camera scanner posts here too (SPEC-scanner: one endpoint, one
        // validation path) and needs an answer it can render without a reload —
        // reopening the camera between attendees would be unusable at a door.
        if ($request->expectsJson()) {
            return response()->json([
                'result' => $outcome['result'],
                'name' => $outcome['ticket']?->attendee_name,
            ]);
        }

        return back()
            ->with('checkin', $outcome['result'])
            ->with('ticketName', $outcome['ticket']?->attendee_name);
    }

    /** Manual fallback from the registered list (broken QR / dead camera). */
    public function manual(Request $request, Ticket $ticket, TicketCheckin $checkin): RedirectResponse
    {
        $this->owned($request, $ticket->event);

        $outcome = $checkin->checkInTicket($ticket, $request->user());

        return back()->with('checkin', $outcome['result']);
    }

    private function owned(Request $request, Event $event): void
    {
        abort_unless($event->organizer_id === $request->user()->id, 403);
    }
}
