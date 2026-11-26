<?php

use App\Http\Controllers\Auth\NexoSsoController;
use Illuminate\Support\Facades\Route;

// Standalone mode: with SSO disabled these routes don't exist at all. (AC-CFG-1)
// The controller re-checks the flag per request as defense in depth.
if (! config('nexo-sso.enabled')) {
    return;
}

// Sign-in starts. Guests only: an authenticated user has nothing to start.
Route::middleware(['web', 'guest'])->group(function (): void {
    Route::get('/auth/nexo/redirect', [NexoSsoController::class, 'redirect'])->name('nexo-sso.redirect');
    Route::get('/auth/nexo/silent', [NexoSsoController::class, 'silent'])->name('nexo-sso.silent');

    // «First time in <App>?» (transition mode only; 404 otherwise): a Nexo ID
    // sign-in that matched no account waits in the session (15 min) while the
    // person chooses «Create my account» or «I already have an account with
    // another email». POST + CSRF. (AC-NEWACCOUNT)
    Route::get('/auth/nexo/new-account', [NexoSsoController::class, 'newAccount'])->name('nexo-sso.new-account');
    Route::post('/auth/nexo/new-account', [NexoSsoController::class, 'storeNewAccount'])->name('nexo-sso.new-account.store');
});

Route::middleware('web')->group(function (): void {
    // No `guest`: the callback also completes the periodic re-validation of a
    // signed-in session. It resolves by the mode saved with the state. (AC-RECHECK)
    Route::get('/auth/nexo/callback', [NexoSsoController::class, 'callback'])->name('nexo-sso.callback');

    // Entry point for links from other Nexo apps (switcher, deep links, "Sign in
    // again"): ?to=/same-origin/path. Signed in → `to`; guest → Nexo ID. (AC-ENTER)
    Route::get('/auth/nexo/enter', [NexoSsoController::class, 'enter'])->name('nexo-sso.enter');

    // "You signed out of all of Nexo" / "Your Nexo ID session ended". (AC-SIGNEDOUT)
    Route::get('/auth/nexo/signed-out', [NexoSsoController::class, 'signedOut'])->name('nexo-sso.signed-out');
});

Route::middleware(['web', 'auth'])->group(function (): void {
    // Re-validation bounce (prompt=none), started by NexoSsoSessionGuard. (AC-RECHECK)
    Route::get('/auth/nexo/recheck', [NexoSsoController::class, 'recheck'])->name('nexo-sso.recheck');

    // RP-initiated (central) logout. Point this tool's logout button here when SSO
    // is enabled: it ends the local session AND the Nexo ID session. POST + CSRF,
    // mirroring the framework's own logout. (AC-LOGOUT-1) With the page's session
    // already gone (419, or a guest) it goes to Nexo ID's hint-less end_session,
    // which confirms first (NexoSsoStaleLogout).
    Route::post('/auth/nexo/logout', [NexoSsoController::class, 'logout'])->name('nexo-sso.logout');

    // «Link my account» (transition and local; 404 in sso): joins the signed-in
    // local account to a Nexo ID, whatever the emails. POST + CSRF. (AC-JOIN)
    Route::post('/auth/nexo/link', [NexoSsoController::class, 'link'])->name('nexo-sso.link');

    // «Link this account?» — a join whose Nexo ID email is not this account's
    // own waits here (15 min, bound to the signed-in user) until its owner,
    // seeing both emails, confirms. POST + CSRF. (AC-JOIN-CONFIRM)
    Route::get('/auth/nexo/link/confirm', [NexoSsoController::class, 'linkConfirm'])->name('nexo-sso.link.confirm');
    Route::post('/auth/nexo/link/confirm', [NexoSsoController::class, 'storeLinkConfirm'])->name('nexo-sso.link.confirm.store');
});

// Back-channel logout: Nexo ID calls it server to server. Stateless by
// construction — out of the `web` group (this file is usually required from
// routes/web.php, so the group is excluded explicitly): no session, no CSRF, no
// cookies. Its own throttle bucket, 60/min per IP. (AC-BCL-C)
Route::post('/auth/nexo/backchannel-logout', [NexoSsoController::class, 'backchannel'])
    ->withoutMiddleware('web')
    ->middleware('throttle:60,1,nexo-sso-backchannel')
    ->name('nexo-sso.backchannel');
