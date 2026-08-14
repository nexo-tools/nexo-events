<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/ApiTestSupport.php';

/**
 * AC-API-13 — the limits, and the middleware order they depend on.
 *
 * Deliberate violations: a limit nobody has ever seen fire is a limit nobody
 * knows is wired to the right key.
 */
beforeEach(function (): void {
    config(['nexo.api.enabled' => true]);
});

test('AC-API-13: writes are throttled, and the limit is the operator\'s to set', function (): void {
    config(['nexo.api.rate_write' => 2]);

    $raw = apiToken(User::factory()->create());
    $payload = fn (int $n): array => ['title' => "Evento {$n}", 'starts_at' => now()->addWeek()->toIso8601String()];

    $this->postJson('/api/v1/events', $payload(1), bearer($raw))->assertCreated();
    $this->postJson('/api/v1/events', $payload(2), bearer($raw))->assertCreated();
    $this->postJson('/api/v1/events', $payload(3), bearer($raw))->assertStatus(429);

    // The one that was refused was refused before it created anything.
    expect(Event::count())->toBe(2);
});

test('AC-API-13: reads have their own budget, so a busy counter cannot lock out writes', function (): void {
    config(['nexo.api.rate_read' => 1, 'nexo.api.rate_write' => 5]);

    $user = User::factory()->create();
    $event = Event::factory()->for($user, 'organizer')->create();
    $raw = apiToken($user);

    $this->getJson("/api/v1/events/{$event->id}", bearer($raw))->assertOk();
    $this->getJson("/api/v1/events/{$event->id}", bearer($raw))->assertStatus(429);

    // Writes are untouched by the exhausted read budget.
    $this->postJson("/api/v1/events/{$event->id}/close", [], bearer($raw))->assertOk();
});

test('AC-API-13: the bucket belongs to the token, not to the account or the address', function (): void {
    config(['nexo.api.rate_write' => 1]);

    $user = User::factory()->create();
    $first = apiToken($user);
    $second = apiToken($user);

    $payload = fn (string $t): array => ['title' => $t, 'starts_at' => now()->addWeek()->toIso8601String()];

    $this->postJson('/api/v1/events', $payload('Con el primero'), bearer($first))->assertCreated();
    $this->postJson('/api/v1/events', $payload('De nuevo'), bearer($first))->assertStatus(429);

    // Same account, same IP, a different integration: revoking or rate-limiting
    // one consumer must not silence the others.
    $this->postJson('/api/v1/events', $payload('Con el segundo'), bearer($second))->assertCreated();
});

test('AC-API-13: with the API off the answer stays 404 — the gate outranks the limiter', function (): void {
    config(['nexo.api.enabled' => false, 'nexo.api.rate_read' => 1]);

    $raw = apiToken(User::factory()->create());

    // Past the read budget on purpose: a disabled API must not start reporting
    // 429, which would tell a stranger that there is something here to throttle.
    $this->getJson('/api/v1/me', bearer($raw))->assertNotFound();
    $this->getJson('/api/v1/me', bearer($raw))->assertNotFound();
    $this->getJson('/api/v1/me', bearer($raw))->assertNotFound();
});

test('AC-API-13: the API auth middleware really does run before the throttle', function (): void {
    // This is the invisible dependency of the two behaviours above. Laravel
    // sorts route middleware by its priority list, and ThrottleRequests is on
    // it: without the prependToPriorityList() call in bootstrap/app.php the
    // throttle silently jumps to the front and keys every consumer by IP.
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r): bool => $r->uri() === 'api/v1/events' && in_array('POST', $r->methods(), true));

    expect($route)->not->toBeNull('The API create route is missing.');

    // Aliases and groups only reach the router when the HTTP kernel is built,
    // which is what turns 'api.token' into the class name below.
    app(Kernel::class);

    $gathered = array_values(array_map(
        fn (string $m): string => explode(':', $m)[0],
        array_filter(app(Router::class)->gatherRouteMiddleware($route), 'is_string')
    ));

    $auth = array_search(AuthenticateApiToken::class, $gathered, true);
    $throttle = array_search(ThrottleRequests::class, $gathered, true);

    expect($auth)->not->toBeFalse('AuthenticateApiToken is not on the route at all.');
    expect($throttle)->not->toBeFalse('The throttle is not on the route at all.');
    expect($auth)->toBeLessThan($throttle, 'The throttle runs before the API auth: the limiter can no longer key by token and a disabled API answers 429 instead of 404.');
});
