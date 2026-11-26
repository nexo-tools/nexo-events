<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\NexoSso\NexoSsoLinkConflictException;
use App\Services\NexoSso\NexoSsoNewAccount;
use App\Services\NexoSso\NexoSsoPendingLink;
use App\Services\NexoSso\NexoSsoSession;
use App\Services\NexoSso\NexoSsoUserResolver;
use Illuminate\Auth\Events\Login;

/**
 * «I already have an account with another email» (transition mode, nexo-sso-
 * client v2.1): after choosing it on /auth/nexo/new-account, the person signs
 * in with that account's password. When that sign-in happens in the same
 * browser session and inside the 15-minute window, and that Nexo ID is still
 * free, this hands the waiting Nexo ID to /auth/nexo/link/confirm («Link this
 * account?», both emails side by side) as the next page — it never joins
 * anything by itself. Whoever typed the password decides there: on a shared
 * computer the «First time» choice may have been somebody else's.
 * (AC-NEWACCOUNT, AC-JOIN-CONFIRM)
 *
 * It listens to the framework's Login event, so it works with whatever login
 * code the tool has (Breeze's LoginRequest, Auth::attempt, attemptWhen) as
 * long as the login answers with redirect()->intended(): a failed password
 * fires no Login, so nothing is offered; neither does a "remember me" cookie
 * login (that is not the person typing a password now). The SSO callback and
 * the new-account screen forget the pending sign-in before their own
 * Auth::login(), so it can only ever fire here.
 *
 * Registered by Laravel's event discovery (app/Listeners); a tool that turned
 * discovery off registers it by hand: Event::listen(Login::class, self::class).
 */
final class NexoSsoLinkOnPasswordLogin
{
    public function __construct(private readonly NexoSsoUserResolver $resolver) {}

    public function handle(Login $event): void
    {
        if (! config('nexo-sso.enabled') || $event->guard !== 'web' || $event->remember || ! $event->user instanceof User) {
            return;
        }

        $request = request();
        if (! $request->hasSession()) {
            return;
        }

        $pending = NexoSsoNewAccount::armed($request);
        if ($pending === null) {
            return;
        }

        // Single use, whatever happens next.
        NexoSsoNewAccount::forget($request);

        try {
            $this->resolver->assertLinkable($event->user, $pending['claims']);
        } catch (NexoSsoLinkConflictException $e) {
            // The password sign-in stands; the account just stays unlinked.
            NexoSsoSession::noticeError($request, $e->userMessage());

            return;
        }

        NexoSsoPendingLink::hold($request, $event->user, $pending['claims'], $pending['id_token'], $pending['return'], $pending['at']);
        $request->session()->put('url.intended', route('nexo-sso.link.confirm'));
    }
}
