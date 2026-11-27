<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Http\Requests\EventRequest;
use App\Models\Event;
use App\Models\Ticket;
use App\Services\EventCreator;
use App\Services\EventLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventController extends Controller
{
    public function __construct(
        private readonly EventCreator $creator,
        private readonly EventLifecycle $lifecycle,
    ) {}

    public function create(): View
    {
        return view('app.events.create');
    }

    public function store(EventRequest $request): RedirectResponse
    {
        $event = $this->creator->create($request->user(), $request->validated());

        return redirect()->route('events.edit', $event)->with('status', __('Event created.'));
    }

    public function edit(Request $request, Event $event): View
    {
        $this->owned($request, $event);

        return view('app.events.edit', ['event' => $event]);
    }

    public function update(EventRequest $request, Event $event): RedirectResponse
    {
        $this->owned($request, $event);
        $event->update($request->validated());

        return redirect()->route('events.edit', $event)->with('status', __('Event updated.'));
    }

    public function publish(Request $request, Event $event): RedirectResponse
    {
        $this->owned($request, $event);

        if ($this->lifecycle->publish($request->user(), $event) === EventLifecycle::UNVERIFIED) {
            return back()->withErrors([
                'publish' => __('Verify your email to publish events. We sent you the link when you signed up.'),
            ]);
        }

        return back()->with('status', __('Event published.'));
    }

    public function close(Request $request, Event $event): RedirectResponse
    {
        $this->managed($request, $event);
        $this->lifecycle->close($event);

        return back()->with('status', __('Registration closed.'));
    }

    public function cancel(Request $request, Event $event): RedirectResponse
    {
        $this->owned($request, $event);

        // A draft or a blocked event is not cancelled (AC-PANEL-5): saying
        // "Event cancelled." would be a lie about a page that stays hidden.
        if ($this->lifecycle->cancel($event) === EventLifecycle::UNCHANGED) {
            return back();
        }

        return back()->with('status', __('Event cancelled.'));
    }

    public function destroy(Request $request, Event $event): RedirectResponse
    {
        $this->owned($request, $event);

        $title = $event->title;

        if ($this->lifecycle->deleteDraft($event) === EventLifecycle::UNCHANGED) {
            return back()->withErrors([
                'delete' => __('Only a draft can be deleted. A published event is cancelled instead, so the people holding a ticket are told.'),
            ]);
        }

        return redirect()->route('dashboard')->with('status', __('Draft “:title” deleted.', ['title' => $title]));
    }

    public function registrations(Request $request, Event $event): View
    {
        $this->managed($request, $event);
        $active = ! in_array($event->status, [EventStatus::Cancelled, EventStatus::Killed], true);
        $tickets = $event->tickets()->with('checkin')->latest()->get();

        // Two sections in one screen (Alvaro, 2026-11-27): «Invitados» on top, then «General».
        // A released ticket is never a guest one (AC-PANEL-11), so it stays in «General»,
        // flag or not; given back, a guest goes up again.
        [$guestTickets, $generalTickets] = $tickets->partition(
            fn (Ticket $ticket): bool => $ticket->is_guest && $ticket->status !== TicketStatus::Revoked
        );

        return view('app.events.registrations', [
            'event' => $event,
            // Collaborators see names and status; attendee emails stay with the
            // owner, who is the one answerable for them (ADR-011 §2).
            'showEmails' => $event->organizer_id === $request->user()->id,
            'tickets' => $tickets,
            'guestTickets' => $guestTickets->values(),
            'generalTickets' => $generalTickets->values(),
            // «Devolver el lugar» is offered only while there is room (a guest
            // needs none); the restore itself counts again under a lock (AC-COLLAB-19).
            'eventActive' => $active,
            'canRestore' => $active && ($event->capacity === null || $event->takenSpotsCount() < $event->capacity),
        ]);
    }

    /** Owner only: content, publishing a draft, cancelling, collaborators. */
    private function owned(Request $request, Event $event): void
    {
        abort_unless($event->organizer_id === $request->user()->id, 403);
    }

    /** Owner or an accepted collaborator inside the access window (ADR-011). */
    private function managed(Request $request, Event $event): void
    {
        abort_unless($event->canBeManagedBy($request->user()), 403);
    }
}
