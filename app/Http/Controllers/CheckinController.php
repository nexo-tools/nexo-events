<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Ticket;
use App\Services\TicketCheckin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckinController extends Controller
{
    public function scanner(Request $request, Event $event): View
    {
        $this->owned($request, $event);

        return view('app.scan', ['event' => $event]);
    }

    /** Process a scanned/entered token against THIS event's door. */
    public function checkin(Request $request, Event $event, TicketCheckin $checkin): RedirectResponse
    {
        $this->owned($request, $event);

        $outcome = $checkin->checkInByToken((string) $request->input('token'), $request->user());

        // A token from another event must not check in here.
        if ($outcome['ticket'] !== null && $outcome['ticket']->event_id !== $event->id) {
            $outcome = ['result' => TicketCheckin::UNKNOWN, 'ticket' => null];
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
