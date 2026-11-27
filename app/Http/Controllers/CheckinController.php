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
        $this->managed($request, $event);

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
                // A released spot (ADR-011 §6): the door says so, and the scanner adds the name.
                'revoked' => '✗ '.__('Spot released: it no longer gets in'),
                'event_inactive' => '✗ '.__('Event cancelled'),
                'unknown' => '✗ '.__('Invalid ticket'),
                'starting' => __('Opening the camera…'),
                'ready' => __('Point at the ticket QR.'),
                'stopped' => __('Camera off.'),
                'noCamera' => __('We could not use the camera. Grant permission, or open this page in Safari or Chrome rather than inside another app.'),
                'offline' => __('No connection. Check the signal and try again.'),
                'throttled' => __('Too many attempts in a row. Wait a few seconds.'),
                'failed' => __('We could not validate the ticket. Try again.'),
                // A 401/419: the session ended, not the ticket (AC-PANEL-9).
                'signedOut' => __('Your session ended. Sign in again to keep scanning.'),
                'signIn' => __('Sign in again'),
            ],
        ]);
    }

    /** Process a scanned/entered token against THIS event's door. */
    public function checkin(Request $request, Event $event, TicketCheckin $checkin): RedirectResponse|JsonResponse
    {
        $this->managed($request, $event);

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
        $this->managed($request, $ticket->event);

        $outcome = $checkin->checkInTicket($ticket, $request->user());

        return back()->with('checkin', $outcome['result']);
    }

    /**
     * Owner or an accepted collaborator (ADR-011): the door is where a second
     * person is most needed. checkins.checked_by records which one scanned.
     */
    private function managed(Request $request, Event $event): void
    {
        abort_unless($event->canBeManagedBy($request->user()), 403);
    }
}
