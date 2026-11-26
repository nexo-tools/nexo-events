<?php

declare(strict_types=1);

namespace App\Services\NexoSso;

use App\Mail\NexoIdLinked;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Maps verified OIDC claims to a local user. ADAPTATION POINTS: newUser() (the
 * tool's users table) and linked() (what the tool does when an existing account
 * gets joined to a Nexo ID — Nexo tools send the "Nexo ID was linked" notice).
 */
class NexoSsoUserResolver
{
    /** An existing account joined because Nexo ID vouched for its email. */
    public const BY_EMAIL = 'email';

    /** An existing account joined because its owner asked, whatever the email ("Link my account"). */
    public const EXPLICIT = 'explicit';

    /**
     * The local user for these claims: by sub, else linked by verified email,
     * else a new account.
     *
     * @param  array<string, mixed>  $claims
     */
    public function resolve(array $claims): User
    {
        return $this->find($claims) ?? $this->create($claims);
    }

    /**
     * The local user these claims already belong to — by sub, or an existing
     * account not joined to any Nexo ID yet, linked now by its provider-
     * verified email — or null when nobody matches (the caller decides whether
     * to create one).
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws NexoSsoLinkRefusedException the email matches but Nexo ID didn't verify it
     * @throws NexoSsoLinkConflictException the email matches an account joined to another Nexo ID
     */
    public function find(array $claims): ?User
    {
        $sub = $this->sub($claims);

        // Returning user: match by stable sub, even if the email changed. (AC-LINK-3)
        $user = User::query()->where('nexo_id_sub', $sub)->first();
        if ($user !== null) {
            return $user;
        }

        $email = (string) ($claims['email'] ?? '');
        if ($email === '') {
            throw new NexoSsoException('Missing email claim (is the email scope granted?).');
        }

        // Existing local account: link only on a provider-verified email —
        // otherwise an attacker could claim someone else's account. (AC-LINK-2)
        $existing = User::query()->where('email', $email)->first();
        if ($existing === null) {
            return null;
        }

        if (($claims['email_verified'] ?? false) !== true) {
            throw new NexoSsoLinkRefusedException('Email not verified by the identity provider.');
        }

        // Already joined to ANOTHER Nexo ID (its owner may have linked one with
        // a different email): the email it still carries here proves nothing
        // any more — that mailbox may not even be theirs now. One account, one
        // identity: never re-joined behind its owner's back. (AC-LINK-4)
        if ((string) $existing->getAttribute('nexo_id_sub') !== '') {
            throw new NexoSsoLinkConflictException(NexoSsoLinkConflictException::ACCOUNT_TAKEN);
        }

        try {
            $existing->forceFill(['nexo_id_sub' => $sub])->save();
        } catch (UniqueConstraintViolationException) {
            // Another request joined that Nexo ID to some account in between.
            throw new NexoSsoLinkConflictException(NexoSsoLinkConflictException::SUB_TAKEN);
        }

        $this->linked($existing, $claims, self::BY_EMAIL);

        return $existing;
    }

    /**
     * First login, no local account: create one from the claims. (AC-LINK-1)
     *
     * @param  array<string, mixed>  $claims
     */
    public function create(array $claims): User
    {
        $sub = $this->sub($claims);

        $user = $this->newUser($claims);
        $user->forceFill(['nexo_id_sub' => $sub])->save();

        return $user;
    }

    /**
     * Joins $user — the person signed in here, who asked for it — to the Nexo
     * ID of these claims, whatever the two emails are. Refused when that Nexo
     * ID already belongs to another account, or when $user is already joined to
     * a different Nexo ID: one account, one identity, both ways. The caller
     * asks the person first when needsConfirmation() says so. (AC-JOIN)
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws NexoSsoLinkConflictException
     */
    public function link(User $user, array $claims): void
    {
        $sub = $this->sub($claims);

        if (! $this->assertLinkable($user, $claims)) {
            return; // already this one: nothing changes
        }

        try {
            $user->forceFill(['nexo_id_sub' => $sub])->save();
        } catch (UniqueConstraintViolationException) {
            // Another request joined that Nexo ID to some account in between.
            throw new NexoSsoLinkConflictException(NexoSsoLinkConflictException::SUB_TAKEN);
        }

        $this->linked($user, $claims, self::EXPLICIT);
    }

    /**
     * The checks of link() without joining anything: throws when that Nexo ID
     * already belongs to another account, or $user to a different Nexo ID.
     * False when $user is already joined to exactly this one (nothing to do).
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws NexoSsoLinkConflictException
     */
    public function assertLinkable(User $user, array $claims): bool
    {
        $sub = $this->sub($claims);

        $owner = User::query()->where('nexo_id_sub', $sub)->first();
        if ($owner !== null && ! $owner->is($user)) {
            throw new NexoSsoLinkConflictException(NexoSsoLinkConflictException::SUB_TAKEN);
        }

        $current = (string) $user->getAttribute('nexo_id_sub');
        if ($current === $sub) {
            return false;
        }

        if ($current !== '') {
            throw new NexoSsoLinkConflictException(NexoSsoLinkConflictException::ACCOUNT_TAKEN);
        }

        return true;
    }

    /**
     * Must the person confirm, seeing both emails, before $user is joined to
     * the Nexo ID of these claims? Yes unless Nexo ID vouches for this very
     * account's email: otherwise the Nexo ID that answered may well be
     * somebody else's — a session left open on a shared computer answers a
     * «Link my account» with 0 clicks. (AC-JOIN-CONFIRM)
     *
     * @param  array<string, mixed>  $claims
     */
    public function needsConfirmation(User $user, array $claims): bool
    {
        $nexoEmail = mb_strtolower(trim((string) ($claims['email'] ?? '')));
        $localEmail = mb_strtolower(trim((string) $user->getAttribute('email')));

        return ($claims['email_verified'] ?? false) !== true || $nexoEmail === '' || $nexoEmail !== $localEmail;
    }

    /**
     * ADAPTATION POINT — align with this tool's users table.
     *
     * @param  array<string, mixed>  $claims
     */
    protected function newUser(array $claims): User
    {
        $user = new User;
        $user->forceFill([
            'name' => (string) ($claims['name'] ?? Str::before((string) $claims['email'], '@')),
            'email' => (string) $claims['email'],
            // Random local password: this account authenticates via SSO. If the
            // tool also offers local login, "forgot password" still works.
            'password' => Str::password(40),
            'email_verified_at' => ($claims['email_verified'] ?? false) === true ? now() : null,
        ]);
        $user->save();

        return $user;
    }

    /**
     * ADAPTATION POINT — an existing account was just joined to a Nexo ID for
     * the first time, $how = BY_EMAIL (its verified email matched) or EXPLICIT
     * (its owner asked, maybe with another email). Nexo tools tell the owner
     * here (the NexoIdLinked mail). Empty by default.
     *
     * Nexo Events: two things.
     *   - By email, Nexo ID just vouched for this very address, so a local
     *     account that never confirmed it is confirmed now. Otherwise an SSO
     *     organizer would be asked to verify an address Nexo ID already
     *     verified, and the publish gate (ADR-007 §1) and a collaborator
     *     invitation (ADR-011) would never open for them. An EXPLICIT join may
     *     carry another email, which proves nothing about this one.
     *   - The owner hears about it from the tool where it happened, never from
     *     nexo-id: if they didn't do it, this mail is how they notice.
     *
     * @param  array<string, mixed>  $claims
     */
    protected function linked(User $user, array $claims, string $how): void
    {
        if ($how === self::BY_EMAIL && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Mail::to($user->email)
            ->locale(app()->getLocale())
            ->queue(new NexoIdLinked($user, $how));
    }

    /** @param  array<string, mixed>  $claims */
    private function sub(array $claims): string
    {
        $sub = $claims['sub'] ?? null;
        if (! is_string($sub) || $sub === '') {
            throw new NexoSsoException('Missing sub claim.');
        }

        return $sub;
    }
}
