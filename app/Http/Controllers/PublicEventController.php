<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterAttendeeRequest;
use App\Http\Requests\ResendTicketRequest;
use App\Mail\TicketIssued;
use App\Models\Event;
use App\Services\EventRegistrar;
use App\Services\EventViewCounter;
use App\Services\TicketResender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class PublicEventController extends Controller
{
    public function show(Request $request, Event $event, EventViewCounter $views): View
    {
        abort_unless($event->status->isPublic(), 404);

        $views->record($event, $request);

        return view('public.event.show', ['event' => $event]);
    }

    public function register(RegisterAttendeeRequest $request, Event $event, EventRegistrar $registrar): RedirectResponse
    {
        abort_unless($event->status->isPublic(), 404);

        $outcome = $registrar->register(
            $event,
            $request->string('name')->toString(),
            $request->string('email')->toString(),
        );

        if ($outcome['result'] === EventRegistrar::OK && $outcome['ticket'] !== null && $outcome['token'] !== null) {
            // Queued, so a slow or dead relay degrades to "the email is late",
            // never to "registration failed" (AC-EMAIL-5). The locale is pinned
            // now because the queue worker has no idea who registered or in what
            // language they were reading (AC-EMAIL-6).
            Mail::to($outcome['ticket']->attendee_email)
                ->locale(app()->getLocale())
                ->queue(new TicketIssued($outcome['ticket'], $outcome['token']));
        }

        return match ($outcome['result']) {
            EventRegistrar::OK => redirect()->route('ticket.show', ['token' => $outcome['token']]),
            EventRegistrar::DUPLICATE => back()->with('status', __('Ya estás registrado con ese email. Pedí que te reenviemos tu entrada si no la encontrás.')),
            EventRegistrar::SOLD_OUT => back()->withErrors(['email' => __('El evento está agotado.')]),
            default => back()->withErrors(['email' => __('El registro para este evento está cerrado.')]),
        };
    }

    /**
     * Re-deliver a lost ticket. Mints a new token and retires the old QR
     * (ADR-008) — the original is unrecoverable because only its hash is kept.
     */
    public function resend(ResendTicketRequest $request, Event $event, TicketResender $resender): RedirectResponse
    {
        abort_unless($event->status->isPublic(), 404);

        $resender->resend(
            $event,
            $request->string('email')->toString(),
            app()->getLocale(),
        );

        // Deliberately the same answer whether or not that address holds a
        // ticket: branching here would turn the page into an attendee-list
        // oracle for anyone with a browser (AC-RESEND-2).
        return back()->with('status', __('Si ese email tiene una entrada para este evento, te la reenviamos. Revisá tu correo.'));
    }
}
