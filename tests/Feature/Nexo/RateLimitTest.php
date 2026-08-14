<?php

// Guardian: the surfaces that get hammered have a named, env-tunable limit, and
// the sign-in POST has one at all.
//
// Why it exists: every tool had rate limits and no two agreed. Two of them left
// the login POST with no route throttle whatsoever — the LoginRequest lockout is
// per email+IP, which does nothing against one machine spraying one password
// across a thousand addresses, and that is the attack that actually happens.
//
// Copy into tests/Feature/Nexo/ and list this tool's named limiters.

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/** Named limiters this tool declares, e.g. ['login-ip', 'report']. */
// The organizer and attendee surfaces limit inline per route (throttle:10,1
// and friends), which the standard allows for surfaces only this tool has.
// The integration API is the exception and needs names: its limits are keyed
// by token rather than by address, and an operator has to be able to raise
// them from the env (ADR-010, AC-API-13).
const NAMED_LIMITERS = ['nexo-api-read', 'nexo-api-write'];

/**
 * URI of the credential sign-in POST, or null if the tool has none. Matched by
 * URI and not by name because in most of the family only the GET carries the
 * `login` route name — the POST is anonymous, which is exactly why nobody
 * noticed it had no throttle.
 */
const LOGIN_POST_URI = 'login';

it('registers every named limiter it claims to have', function () {
    if (NAMED_LIMITERS === []) {
        // Legitimate: a tool whose limits are all inline per route
        // (throttle:10,1). The standard allows that for surfaces only it has —
        // what it does not allow is the sign-in POST going unthrottled, which
        // the next test asserts either way.
        test()->markTestSkipped('This tool declares no named limiters (inline throttles only).');
    }

    foreach (NAMED_LIMITERS as $name) {
        // A route referencing throttle:<name> with no limiter registered does
        // not fail loudly — it just does not limit anything.
        expect(RateLimiter::limiter($name))->not->toBeNull("Named limiter [{$name}] is not registered in AppServiceProvider.");
    }
});

it('throttles the sign-in POST at the route, not only per credential', function () {
    if (LOGIN_POST_URI === null) {
        test()->markTestSkipped('This tool has no credential sign-in.');
    }

    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r): bool => $r->uri() === LOGIN_POST_URI && in_array('POST', $r->methods(), true));

    expect($route)->not->toBeNull('No POST route at /'.LOGIN_POST_URI.'.');

    $throttles = collect($route->gatherMiddleware())->filter(fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:'));

    expect($throttles)->not->toBeEmpty('The sign-in POST has no throttle middleware — the per-credential lockout is blind to one IP trying many accounts.');
});

it('throttles every write of the integration API, not only its reads', function () {
    // A read that runs away costs a query; a write creates events and queues
    // mail to everyone holding a ticket. The write endpoints are the ones a
    // missing limiter would hurt on, so they are checked by enumeration rather
    // than by trusting the group they were written into.
    $writes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r): bool => str_starts_with($r->uri(), 'api/')
            && array_intersect(['POST', 'PATCH', 'PUT', 'DELETE'], $r->methods()) !== []);

    expect($writes)->not->toBeEmpty('No API write routes found — did the prefix change?');

    $unlimited = $writes
        ->reject(fn ($r): bool => collect($r->gatherMiddleware())
            ->contains(fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:')))
        ->map(fn ($r): string => implode('|', $r->methods()).' '.$r->uri())
        ->values()
        ->all();

    expect($unlimited)->toBe([], "API write routes with no throttle:\n".implode("\n", $unlimited));
});

it('keeps its limits configurable by the operator', function () {
    if (NAMED_LIMITERS === []) {
        test()->markTestSkipped('This tool declares no named limiters (inline throttles only).');
    }

    // A limit hardcoded in a provider is a limit nobody can raise at 3am when a
    // legitimate spike is being mistaken for an attack.
    $provider = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

    $undeclared = array_values(array_filter(
        NAMED_LIMITERS,
        fn (string $name): bool => ! str_contains($provider, "'{$name}'")
    ));

    expect($undeclared)->toBe([], 'Named limiters registered elsewhere than AppServiceProvider: '.implode(', ', $undeclared));
    expect(preg_match('/RateLimiter::for\(/', $provider))->toBe(1, 'No named limiter is declared in AppServiceProvider.');
    expect(preg_match('/Limit::perMinute\(\s*\(int\) config\(/', $provider))
        ->toBe(1, 'Named limiters must read their ceiling from config (env-tunable), not from a literal.');
});
