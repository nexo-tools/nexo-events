<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterAttendeeRequest;
use App\Mail\TicketIssued;
use App\Models\Event;
use App\Services\EventRegistrar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class PublicEventController extends Controller
{
    public function show(Event $event): View
    {
        abort_unless($event->status->isPublic(), 404);

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
}
