<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiToken;
use App\Mail\CollaboratorInvitedByIntegration;
use App\Models\ApiToken;
use App\Models\Event;
use App\Models\EventCollaborator;
use App\Services\CollaboratorInviter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;

/**
 * Collaborators over HTTP (SPEC-integration-api v1.1, ADR-011). The rules are
 * CollaboratorInviter's, the same the panel uses; what is this class's own is
 * the reach — only events the integration created — and the trail: every
 * invitation made through a token mails the owner and carries the token's name.
 *
 * Collaborator emails are the consumer's own input, not attendee data; nothing
 * about registrants is ever in these answers (AC-API-11).
 */
class CollaboratorApiController extends Controller
{
    public function __construct(private readonly CollaboratorInviter $inviter) {}

    public function index(Request $request, string $event): JsonResponse
    {
        $found = $this->integrationEvent($request, $event);

        return new JsonResponse(
            $found->collaborators()->orderBy('id')->get()->map(fn (EventCollaborator $c): array => $this->payload($c))->all()
        );
    }

    public function store(Request $request, string $event): JsonResponse
    {
        $found = $this->integrationEvent($request, $event);
        $token = $this->token($request);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'locale' => ['nullable', 'string', 'in:es,en,pt'],
        ]);

        $outcome = $this->inviter->invite($found, $validated['email'], $token->user, $token->name, $validated['locale'] ?? null);

        return match ($outcome['result']) {
            CollaboratorInviter::UNVERIFIED => new JsonResponse([
                'message' => 'This account must verify its email address before it can invite collaborators.',
                'code' => 'email_unverified',
            ], 422),
            CollaboratorInviter::TOO_MANY => new JsonResponse([
                'message' => 'This event already has the maximum number of active collaborators ('.CollaboratorInviter::MAX_ACTIVE.').',
                'code' => 'too_many_collaborators',
            ], 422),
            CollaboratorInviter::ALREADY_ACCEPTED => new JsonResponse($this->payload($outcome['collaborator']), 200),
            default => $this->invited($found, $token, $outcome),
        };
    }

    public function destroy(Request $request, string $event, string $collaborator): Response
    {
        $found = $this->integrationEvent($request, $event);

        /** @var EventCollaborator $row */
        $row = $found->collaborators()->whereKey($collaborator)->firstOrFail();

        $this->inviter->revoke($row);

        return response()->noContent();
    }

    /**
     * A link was minted: tell the owner, and hand the consumer the raw link —
     * here and nowhere else (never in the list).
     *
     * @param  array{result: string, collaborator: ?EventCollaborator, url: ?string}  $outcome
     */
    private function invited(Event $event, ApiToken $token, array $outcome): JsonResponse
    {
        /** @var EventCollaborator $collaborator */
        $collaborator = $outcome['collaborator'];

        $owner = $event->organizer;
        Mail::to($owner->email)
            ->locale(app()->getLocale())
            ->queue(new CollaboratorInvitedByIntegration($collaborator, $token->name));

        return new JsonResponse(
            [...$this->payload($collaborator), 'invite_url' => $outcome['url']],
            $outcome['result'] === CollaboratorInviter::CREATED ? 201 : 200,
        );
    }

    /** @return array<string, mixed> */
    private function payload(?EventCollaborator $c): array
    {
        if ($c === null) {
            return [];
        }

        return [
            'id' => $c->id,
            'email' => $c->email,
            'status' => $c->status(),
            'invited_at' => $c->invited_at->toIso8601String(),
            'expires_at' => $c->expires_at->toIso8601String(),
            'accepted_at' => $c->accepted_at?->toIso8601String(),
            'invited_via' => $c->invited_via,
        ];
    }

    /**
     * The token owner's event, and only one an integration created: an event
     * the organizer made by hand is never opened up by a token (ADR-011 §7).
     * Anything else is 404, as everywhere in this API.
     */
    private function integrationEvent(Request $request, string $id): Event
    {
        return Event::query()
            ->where('organizer_id', $this->token($request)->user_id)
            ->whereNotNull('external_ref')
            ->whereKey($id)
            ->firstOrFail();
    }

    private function token(Request $request): ApiToken
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get(AuthenticateApiToken::ATTRIBUTE);

        return $token;
    }
}
