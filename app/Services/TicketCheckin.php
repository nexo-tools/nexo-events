<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class TicketCheckin
{
    public const OK = 'ok';

    public const ALREADY = 'already';

    public const REVOKED = 'revoked';

    public const EVENT_INACTIVE = 'event_inactive';

    public const UNKNOWN = 'unknown';

    /**
     * Resolve a ticket by its opaque token and check it in.
     *
     * $atEvent scopes the scan to one door. Pass it whenever the caller knows
     * which event is being scanned: without it, a ticket belonging to a
     * DIFFERENT event would be consumed here — marked used and given a checkin
     * row — before the caller could notice and reject it. The attendee would
     * then be turned away at the event they actually registered for, and any
     * organizer could burn another organizer's tickets by scanning them at
     * their own door. The check has to happen before the mutation, not after.
     *
     * @return array{result: string, ticket: ?Ticket}
     */
    public function checkInByToken(string $token, ?User $by = null, ?Event $atEvent = null): array
    {
        $ticket = Ticket::query()->where('token_hash', hash('sha256', $token))->with('event')->first();

        if ($ticket !== null && $atEvent !== null && $ticket->event_id !== $atEvent->getKey()) {
            // Indistinguishable from an unknown token on purpose: this door has
            // no business confirming that a ticket exists for another event.
            return ['result' => self::UNKNOWN, 'ticket' => null];
        }

        return $this->check($ticket, $by);
    }

    /**
     * Manual fallback: check in a known ticket (broken QR / dead camera at the door).
     *
     * @return array{result: string, ticket: ?Ticket}
     */
    public function checkInTicket(Ticket $ticket, ?User $by = null): array
    {
        return $this->check($ticket->loadMissing('event'), $by);
    }

    /** @return array{result: string, ticket: ?Ticket} */
    private function check(?Ticket $ticket, ?User $by): array
    {
        if ($ticket === null) {
            return ['result' => self::UNKNOWN, 'ticket' => null];
        }

        if ($ticket->status === TicketStatus::Revoked) {
            return ['result' => self::REVOKED, 'ticket' => $ticket];
        }

        if (in_array($ticket->event->status, [EventStatus::Cancelled, EventStatus::Killed], true)) {
            return ['result' => self::EVENT_INACTIVE, 'ticket' => $ticket];
        }

        try {
            DB::transaction(function () use ($ticket, $by): void {
                // Insert the check-in FIRST: the UNIQUE(ticket_id) constraint makes a
                // second (concurrent) scan throw here, so exactly one entry commits.
                Checkin::query()->create([
                    'ticket_id' => $ticket->getKey(),
                    'checked_by' => $by?->getKey(),
                    'checked_at' => now(),
                ]);
                $ticket->forceFill(['status' => TicketStatus::CheckedIn->value])->save();
            });
        } catch (QueryException) {
            // Unique violation on ticket_id → the ticket was already checked in.
            return ['result' => self::ALREADY, 'ticket' => $ticket->fresh()];
        }

        return ['result' => self::OK, 'ticket' => $ticket->fresh()];
    }
}
