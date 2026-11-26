<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Mail\EventCancelled;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * The publish / close / cancel transitions, in one place. Extracted from
 * EventController alongside EventCreator when the integration API arrived
 * (ADR-010 §6): the API is specified to behave exactly like the panel, and the
 * only way for two surfaces to be unable to disagree is for them to run the
 * same code. The cancellation mail loop in particular — a copy of it would be
 * a copy of a promise made to people holding a ticket.
 *
 * Each method returns what happened; how that is told to a human (a redirect
 * with a flash, or JSON) stays in the controllers.
 */
class EventLifecycle
{
    public const OK = 'ok';

    /** Publishing needs a verified email (ADR-007 §1) — the same gate on both surfaces. */
    public const UNVERIFIED = 'unverified';

    /** Nothing to do from this state (cancelling a draft or a blocked event, deleting a non-draft). */
    public const UNCHANGED = 'unchanged';

    public function publish(User $organizer, Event $event): string
    {
        // Publishing is the one action that puts content under this instance's
        // public domain, so it is the one gated on a verified email — the
        // cheapest effective brake on drive-by spam events (ADR-007 §1).
        if (! $organizer->hasVerifiedEmail()) {
            return self::UNVERIFIED;
        }

        if (in_array($event->status, [EventStatus::Draft, EventStatus::Closed], true)) {
            $event->update(['status' => EventStatus::Published]);
        }

        return self::OK;
    }

    public function close(Event $event): string
    {
        if ($event->status === EventStatus::Published) {
            $event->update(['status' => EventStatus::Closed]);
        }

        return self::OK;
    }

    public function cancel(Event $event): string
    {
        // Only on the transition INTO cancelled: cancelling an already
        // cancelled event must not mail everyone a second time (the button
        // stays on screen, and a double click is one refresh away).
        if ($event->status === EventStatus::Cancelled) {
            return self::OK;
        }

        // Only an event people could see can be cancelled. A cancelled event
        // shows its public page (EventStatus::isPublic), so "cancelling" a
        // draft would have PUBLISHED it — and cancelling a blocked one would
        // have brought back a page the kill-switch took down (SPEC-event-panel
        // AC-PANEL-5). A draft is deleted instead (deleteDraft).
        if (! in_array($event->status, [EventStatus::Published, EventStatus::Closed], true)) {
            return self::UNCHANGED;
        }

        $event->update(['status' => EventStatus::Cancelled]);

        // Cancelling used to be silent: the public page said cancelled and the
        // person holding a ticket found out at the door. Each mail goes in the
        // language that person registered in, not the organizer's.
        foreach ($event->tickets()->where('status', '!=', TicketStatus::Revoked->value)->cursor() as $ticket) {
            Mail::to($ticket->attendee_email)
                ->locale($ticket->locale ?: config('app.locale'))
                ->queue(new EventCancelled($ticket));
        }

        return self::OK;
    }

    /**
     * A draft nobody holds a ticket for can go entirely (SPEC-event-panel
     * AC-PANEL-4): it was never public, so nobody is owed an explanation.
     * Its invitations, views and reports cascade with it. Anything else is
     * cancelled instead, which tells the people holding a ticket.
     */
    public function deleteDraft(Event $event): string
    {
        if ($event->status !== EventStatus::Draft || $event->tickets()->exists()) {
            return self::UNCHANGED;
        }

        $event->delete();

        return self::OK;
    }
}
