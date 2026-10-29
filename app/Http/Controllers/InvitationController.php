<?php

namespace App\Http\Controllers;

use App\Services\CollaboratorInviter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Reviewing and accepting a collaborator invitation (ADR-011). Both routes sit
 * behind `auth`: a signed-out visitor is sent to the login page with the
 * invitation as the intended URL, so nothing here can reveal whether the
 * invited email has an account.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly CollaboratorInviter $inviter) {}

    public function show(Request $request, string $token): Response
    {
        $outcome = $this->inviter->inspect($request->user(), $token);

        abort_if($outcome['result'] === CollaboratorInviter::UNKNOWN, 404);

        if ($outcome['result'] === CollaboratorInviter::UNVERIFIED_ACCOUNT) {
            // The verification link brings them straight back here.
            redirect()->setIntendedUrl($request->fullUrl());
        }

        return response()->view('app.invitations.show', [
            'state' => $outcome['result'],
            'collaborator' => $outcome['collaborator'],
            'token' => $token,
        ], $this->status($outcome['result']));
    }

    public function accept(Request $request, string $token): Response|RedirectResponse
    {
        $outcome = $this->inviter->accept($request->user(), $token);

        abort_if($outcome['result'] === CollaboratorInviter::UNKNOWN, 404);

        if ($outcome['result'] !== CollaboratorInviter::OK || $outcome['collaborator'] === null) {
            return $this->show($request, $token);
        }

        return redirect()->route('events.manage', $outcome['collaborator']->event_id)
            ->with('status', __('Invitation accepted. You can now manage the tickets of this event.'));
    }

    private function status(string $result): int
    {
        return match ($result) {
            CollaboratorInviter::EXPIRED => 410,
            CollaboratorInviter::WRONG_EMAIL => 403,
            default => 200,
        };
    }
}
