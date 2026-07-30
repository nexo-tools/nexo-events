<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Ticket;
use App\Services\QrPng;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your ticket" — the one and only transactional template in v1 (ADR-005 §6).
 * The registration confirmation *is* the ticket: event summary, the QR as an
 * embedded PNG, and the ticket link that keeps working when images don't.
 *
 * Queued (ADR-005 §5) so registration never waits on SMTP. Note the raw token
 * travels in the job payload — it has to, since only its hash is stored and the
 * QR cannot be rebuilt from that. It lives there exactly as long as the job
 * does, the same trade-off Laravel's own password-reset notification makes.
 */
class TicketIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your ticket for :event', ['event' => $this->ticket->event->title]),
        );
    }

    public function content(): Content
    {
        // Built here rather than in the constructor: at render time, so neither
        // the PNG bytes nor the rendered URL bloat the queued payload.
        return new Content(
            view: 'emails.ticket',
            with: [
                'event' => $this->ticket->event,
                'qrPng' => app(QrPng::class)->forText($this->token),
                'ticketUrl' => route('ticket.show', ['token' => $this->token]),
            ],
        );
    }
}
