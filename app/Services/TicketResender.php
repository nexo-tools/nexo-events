<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TicketStatus;
use App\Mail\TicketIssued;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Re-deliver an attendee's ticket after they lost the email.
 *
 * This is a **credential rotation**, not a re-send of the original message
 * (ADR-008): only `sha256(token)` is stored, so the token that went out the
 * first time is unrecoverable by design. A fresh token is minted, its hash
 * replaces the old one, and the previous QR stops working from that moment.
 *
 * The caller must not branch on the return value in a way a visitor can
 * observe — the public response is identical whether or not the address holds
 * a ticket, or the event page becomes an attendee-list oracle (AC-RESEND-2).
 */
class TicketResender
{
    /** Mail goes only to the address already on the ticket, never to a caller-supplied one. */
    public function resend(Event $event, string $email, string $locale): bool
    {
        $rotated = DB::transaction(function () use ($event, $email): ?array {
            /** @var Ticket|null $ticket */
            $ticket = $event->tickets()
                ->where('attendee_email', $email)
                ->lockForUpdate()
                ->first();

            // A revoked ticket stays dead: rotation must never revive one (ADR-008 §4).
            if ($ticket === null || $ticket->status === TicketStatus::Revoked) {
                return null;
            }

            $token = Str::random(40);
            $ticket->token_hash = hash('sha256', $token); // guarded — never mass-assigned
            $ticket->save();

            return ['ticket' => $ticket, 'token' => $token];
        });

        if ($rotated === null) {
            return false;
        }

        Mail::to($rotated['ticket']->attendee_email)
            ->locale($locale)
            ->queue(new TicketIssued($rotated['ticket'], $rotated['token']));

        return true;
    }
}
