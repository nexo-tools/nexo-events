<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventRegistrar
{
    public const OK = 'ok';

    public const DUPLICATE = 'duplicate';

    public const SOLD_OUT = 'sold_out';

    public const CLOSED = 'closed';

    /**
     * Register an attendee (email-only) and issue a ticket, atomically:
     * - the event must accept registrations (published);
     * - capacity is enforced under a row lock — two simultaneous last-spot
     *   registrations resolve to exactly one ticket (sold out for the other);
     * - one ticket per email per event: a repeat is idempotent (returns the
     *   existing ticket, no new token).
     *
     * `$phone` is the optional attendee phone (PLAN 10.13), already normalized
     * by the request — E.164 since 10.16, null whenever the instance does not
     * collect it. A duplicate registration does not overwrite the one already
     * stored.
     *
     * @return array{result: string, ticket: ?Ticket, token: ?string}
     *                                                                `token` is the raw opaque QR token — returned ONCE, never stored.
     */
    public function register(Event $event, string $name, string $email, ?string $phone = null): array
    {
        return DB::transaction(function () use ($event, $name, $email, $phone): array {
            // Lock the event row so concurrent registrations serialize (MySQL). Under
            // sqlite tests the lock is a no-op but the capacity check stays correct.
            $locked = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->acceptsRegistrations()) {
                return ['result' => self::CLOSED, 'ticket' => null, 'token' => null];
            }

            $existing = $locked->tickets()->where('attendee_email', $email)->first();
            if ($existing !== null) {
                return ['result' => self::DUPLICATE, 'ticket' => $existing, 'token' => null];
            }

            $issued = $locked->tickets()->countsAgainstCapacity()->count(); // guests and released spots take no room
            if ($locked->capacity !== null && $issued >= $locked->capacity) {
                return ['result' => self::SOLD_OUT, 'ticket' => null, 'token' => null];
            }

            $token = Str::random(40); // opaque; only its hash is persisted

            $ticket = new Ticket([
                'attendee_name' => $name,
                'attendee_email' => $email,
                'attendee_phone' => $phone,
                // The language this person registered in. Anything sent later
                // (a cancellation, days afterwards, from the organizer's
                // session) has no request to read a locale from.
                'locale' => app()->getLocale(),
                'status' => TicketStatus::Valid,
            ]);
            $ticket->event()->associate($locked);
            $ticket->token_hash = hash('sha256', $token); // guarded — direct set, never mass-assigned
            $ticket->save();

            return ['result' => self::OK, 'ticket' => $ticket, 'token' => $token];
        });
    }
}
