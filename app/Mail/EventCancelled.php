<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "The event was cancelled" — sent to everyone holding a valid ticket when the
 * organizer cancels.
 *
 * Until now cancelling was silent: the public page said cancelled and the
 * person with the ticket found out at the door. The organizer cancels from
 * their own session, days after the attendee registered, so the language of
 * this mail comes from the ticket (tickets.locale), not from whoever clicked.
 *
 * Queued like every mail in the ecosystem: a cancellation with 200 tickets
 * cannot hold the organizer's request open, and a slow relay must not turn into
 * a failed cancellation for an event that was in fact cancelled.
 */
class EventCancelled extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Ticket $ticket) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Cancelled: :event', ['event' => $this->ticket->event->title]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.event-cancelled',
            with: ['event' => $this->ticket->event, 'ticket' => $this->ticket],
        );
    }
}
