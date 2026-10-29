<?php

namespace App\Services;

use App\Mail\CollaboratorInvited;
use App\Models\Event;
use App\Models\EventCollaborator;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Inviting, revoking and accepting event collaborators, in one place (ADR-011,
 * SPEC-event-collaborators). The web panel and the integration API both call
 * this; how an outcome is told to a human (flash, JSON, status code) stays in
 * the controllers, like EventLifecycle.
 */
class CollaboratorInviter
{
    /** A new invitation, or a fresh one after a revocation. */
    public const CREATED = 'created';

    /** A pending or expired invitation re-sent with a new link; the old one is dead. */
    public const ROTATED = 'rotated';

    /** Already accepted: nothing to send. */
    public const ALREADY_ACCEPTED = 'already_accepted';

    public const TOO_MANY = 'too_many';

    /** Inviting needs the owner's verified email — the publish gate, reused (ADR-007 §1). */
    public const UNVERIFIED = 'unverified';

    public const OK = 'ok';

    public const UNKNOWN = 'unknown';

    public const EXPIRED = 'expired';

    public const WRONG_EMAIL = 'wrong_email';

    /** The accepting account has the right email but has not verified it yet. */
    public const UNVERIFIED_ACCOUNT = 'unverified_account';

    public const MAX_ACTIVE = 10;

    public const INVITATION_DAYS = 7;

    /**
     * `url` is the raw invitation link. It exists only in this return value and
     * in the queued mail: it is never stored and never shown again.
     *
     * @return array{result: string, collaborator: ?EventCollaborator, url: ?string}
     */
    public function invite(Event $event, string $email, ?User $invitedBy = null, ?string $invitedVia = null, ?string $locale = null): array
    {
        $organizer = $event->organizer;

        if (! $organizer->hasVerifiedEmail()) {
            return ['result' => self::UNVERIFIED, 'collaborator' => null, 'url' => null];
        }

        $email = Str::lower(trim($email));

        $outcome = DB::transaction(function () use ($event, $email, $invitedBy, $invitedVia): array {
            // Serializes invitations to one event, so two in flight cannot both
            // squeeze under the limit.
            Event::query()->whereKey($event->getKey())->lockForUpdate()->first();

            /** @var ?EventCollaborator $existing */
            $existing = $event->collaborators()->where('email', $email)->first();

            if ($existing !== null && $existing->status() === EventCollaborator::ACCEPTED) {
                return ['result' => self::ALREADY_ACCEPTED, 'collaborator' => $existing, 'raw' => null];
            }

            $wasActive = $existing !== null && $existing->isActive();

            if (! $wasActive) {
                $active = $event->collaborators()->active()->count();

                if ($active >= self::MAX_ACTIVE) {
                    return ['result' => self::TOO_MANY, 'collaborator' => null, 'raw' => null];
                }
            }

            $result = ($existing === null || $existing->status() === EventCollaborator::REVOKED)
                ? self::CREATED
                : self::ROTATED;

            $raw = Str::random(40);
            $collaborator = $existing ?? new EventCollaborator;
            $collaborator->forceFill([
                'event_id' => $event->getKey(),
                'email' => $email,
                'user_id' => null,
                'invite_token_hash' => EventCollaborator::hashFor($raw),
                'invited_by' => $invitedBy?->getKey(),
                'invited_via' => $invitedVia !== null ? Str::limit($invitedVia, 60, '') : null,
                'invited_at' => now(),
                'expires_at' => $this->expiresAt($event),
                'accepted_at' => null,
                'revoked_at' => null,
            ])->save();

            return ['result' => $result, 'collaborator' => $collaborator, 'raw' => $raw];
        });

        if ($outcome['raw'] === null) {
            return ['result' => $outcome['result'], 'collaborator' => $outcome['collaborator'], 'url' => null];
        }

        $url = route('invitations.show', ['token' => $outcome['raw']]);

        Mail::to($email)
            ->locale($locale ?: app()->getLocale())
            ->queue(new CollaboratorInvited($outcome['collaborator'], $url));

        return ['result' => $outcome['result'], 'collaborator' => $outcome['collaborator'], 'url' => $url];
    }

    /** Immediate: access ends on the collaborator's next request. Idempotent. */
    public function revoke(EventCollaborator $collaborator): void
    {
        if ($collaborator->revoked_at === null) {
            $collaborator->forceFill(['revoked_at' => now(), 'invite_token_hash' => null])->save();
        }
    }

    /**
     * What opening this invitation would mean for this user, without changing
     * anything — the review page and the accept action ask the same question.
     *
     * @return array{result: string, collaborator: ?EventCollaborator}
     */
    public function inspect(User $user, string $rawToken): array
    {
        /** @var ?EventCollaborator $collaborator */
        $collaborator = EventCollaborator::query()
            ->where('invite_token_hash', EventCollaborator::hashFor($rawToken))
            ->with('event.organizer')
            ->first();

        // Revoked and accepted rows have no hash, so they land here too:
        // unknown, revoked and already-used read the same.
        if ($collaborator === null || $collaborator->revoked_at !== null || $collaborator->accepted_at !== null) {
            return ['result' => self::UNKNOWN, 'collaborator' => null];
        }

        if ($collaborator->expires_at->isPast()) {
            return ['result' => self::EXPIRED, 'collaborator' => $collaborator];
        }

        if (Str::lower($user->email) !== $collaborator->email) {
            return ['result' => self::WRONG_EMAIL, 'collaborator' => $collaborator];
        }

        // The email match only proves something if the account owns that inbox.
        // Without this, a link shared outside email could be accepted by whoever
        // registers the invited address first (ADR-011 §3).
        if (! $user->hasVerifiedEmail()) {
            return ['result' => self::UNVERIFIED_ACCOUNT, 'collaborator' => $collaborator];
        }

        return ['result' => self::OK, 'collaborator' => $collaborator];
    }

    /** @return array{result: string, collaborator: ?EventCollaborator} */
    public function accept(User $user, string $rawToken): array
    {
        $outcome = $this->inspect($user, $rawToken);

        if ($outcome['result'] !== self::OK || $outcome['collaborator'] === null) {
            return $outcome;
        }

        // Conditional on the hash still being there: two accepts racing on one
        // link resolve to one grant (single use).
        $updated = EventCollaborator::query()
            ->whereKey($outcome['collaborator']->getKey())
            ->where('invite_token_hash', EventCollaborator::hashFor($rawToken))
            ->update([
                'user_id' => $user->getKey(),
                'accepted_at' => now(),
                'invite_token_hash' => null,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return ['result' => self::UNKNOWN, 'collaborator' => null];
        }

        return ['result' => self::OK, 'collaborator' => $outcome['collaborator']->refresh()];
    }

    /**
     * Long enough to be accepted the night before the show even when the
     * invitation went out weeks ahead (the venue's door account is invited when
     * the date is announced, not when the door opens).
     */
    public function expiresAt(Event $event): Carbon
    {
        $week = now()->addDays(self::INVITATION_DAYS);
        $dayAfter = $event->starts_at->copy()->addDay();

        return $dayAfter->greaterThan($week) ? $dayAfter : $week;
    }
}
