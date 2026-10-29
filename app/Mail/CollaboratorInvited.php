<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\EventCollaborator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "X invited you to manage the tickets of «event»" (ADR-011). Carries the raw
 * invitation link, which — like a ticket token — exists only in this job's
 * payload and in the inviter's one-time view: only its hash is stored.
 */
class CollaboratorInvited extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public EventCollaborator $collaborator,
        public string $inviteUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(':name invited you to manage the tickets of :event', [
                'name' => $this->collaborator->event->organizer->name,
                'event' => $this->collaborator->event->title,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.collaborator-invited',
            with: [
                'event' => $this->collaborator->event,
                'inviter' => $this->collaborator->event->organizer->name,
                'collaborator' => $this->collaborator,
                'inviteUrl' => $this->inviteUrl,
            ],
        );
    }
}
