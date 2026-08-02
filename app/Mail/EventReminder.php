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
 * "Your event is tomorrow" — the reminder an attendee reasonably expects and
 * v1 explicitly left out (routes/console.php once scheduled a nexo:send-reminders
 * that never existed).
 *
 * It carries NO QR, on purpose. Only the hash of a ticket token is stored, so
 * the code cannot be rebuilt: the only way to put a QR in here would be to
 * rotate the token, which invalidates the ticket already sitting in that
 * person's inbox — or in their phone's photo gallery, offline, which is exactly
 * how people carry tickets. Instead it points at the public event page, which
 * already carries the resend form for "I lost my ticket".
 *
 * Language comes from the ticket (tickets.locale): the reminder is sent by a
 * scheduled command, where there is no request and no session at all.
 */
class EventReminder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Ticket $ticket) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Reminder: :event', ['event' => $this->ticket->event->title]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.event-reminder',
            with: [
                'event' => $this->ticket->event,
                'ticket' => $this->ticket,
                'eventUrl' => route('public.event', $this->ticket->event),
            ],
        );
    }
}
