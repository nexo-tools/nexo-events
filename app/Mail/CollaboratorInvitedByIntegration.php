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
 * To the OWNER, every time a connected system invites someone to one of their
 * events (ADR-011 §7). It is the loud half of the collaborators:manage scope:
 * a leaked token that starts inviting addresses cannot do it silently.
 */
class CollaboratorInvitedByIntegration extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public EventCollaborator $collaborator,
        public string $tokenName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(':system invited :email to manage :event', [
                'system' => $this->tokenName,
                'email' => $this->collaborator->email,
                'event' => $this->collaborator->event->title,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.collaborator-invited-by-integration',
            with: [
                'event' => $this->collaborator->event,
                'collaborator' => $this->collaborator,
                'tokenName' => $this->tokenName,
                'manageUrl' => route('events.edit', $this->collaborator->event),
            ],
        );
    }
}
