<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventCollaborator;
use App\Services\CollaboratorInviter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The owner's "Collaborators" section (ADR-011). Owner only: inviting grants a
 * third party sight of registrants' names, which a collaborator cannot pass on.
 * Resending is inviting the same email again — the service rotates the link.
 */
class CollaboratorController extends Controller
{
    public function __construct(private readonly CollaboratorInviter $inviter) {}

    public function store(Request $request, Event $event): RedirectResponse
    {
        abort_unless($event->organizer_id === $request->user()->id, 403);

        $validated = $request->validate([
            'collaborator_email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $outcome = $this->inviter->invite($event, $validated['collaborator_email'], $request->user(), null, app()->getLocale());
        $email = $outcome['collaborator']->email ?? strtolower(trim($validated['collaborator_email']));

        return match ($outcome['result']) {
            CollaboratorInviter::UNVERIFIED => back()->withErrors([
                'collaborator_email' => __('Verify your email to invite collaborators.'),
            ]),
            CollaboratorInviter::TOO_MANY => back()->withErrors([
                'collaborator_email' => __('An event can have at most :count active collaborators. Remove someone first.', ['count' => CollaboratorInviter::MAX_ACTIVE]),
            ]),
            CollaboratorInviter::ALREADY_ACCEPTED => back()->with('status', __(':email already manages this event.', ['email' => $email])),
            // The raw link goes through a one-request flash and nowhere else
            // (ADR-011 §3): shown once so it can be shared by chat when the
            // email is slow or filtered, never stored, never shown again.
            default => back()
                ->with('status', __('Invitation sent to :email.', ['email' => $email]))
                ->with('collaborator_link', $outcome['url'])
                ->with('collaborator_link_event', $event->title),
        };
    }

    public function revoke(Request $request, Event $event, EventCollaborator $collaborator): RedirectResponse
    {
        abort_unless($event->organizer_id === $request->user()->id, 403);
        abort_unless($collaborator->event_id === $event->id, 404);

        $this->inviter->revoke($collaborator);

        return back()->with('status', __('Access removed for :email.', ['email' => $collaborator->email]));
    }
}
