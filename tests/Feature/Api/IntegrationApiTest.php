<?php

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Mail\EventCancelled;
use App\Models\ApiToken;
use App\Models\Event;
use App\Models\User;
use App\Services\EventRegistrar;
use App\Services\TicketCheckin;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/ApiTestSupport.php';

/**
 * Integration API v1 — SPEC docs/specs/SPEC-integration-api.md, ADR-010.
 *
 * The flag is OFF in the test environment by default, exactly as it is in a
 * fresh install: every test that wants the API has to say so, which is what
 * keeps AC-API-1 honest instead of accidental.
 */
beforeEach(function (): void {
    config(['nexo.api.enabled' => true]);
});

// ── Gate & auth ─────────────────────────────────────────────────────────────

test('AC-API-1: with the API disabled every endpoint answers 404, valid token or not', function (): void {
    config(['nexo.api.enabled' => false]);

    $user = User::factory()->create();
    $raw = apiToken($user);
    $event = Event::factory()->for($user, 'organizer')->create();

    $this->getJson('/api/v1/me', bearer($raw))->assertNotFound();
    $this->getJson('/api/v1/me')->assertNotFound();
    $this->getJson("/api/v1/events/{$event->id}", bearer($raw))->assertNotFound();
    $this->postJson('/api/v1/events', ['title' => 'x'], bearer($raw))->assertNotFound();
    $this->postJson("/api/v1/events/{$event->id}/publish", [], bearer($raw))->assertNotFound();
});

test('AC-API-1: the disabled API writes nothing and does not even look a token up', function (): void {
    config(['nexo.api.enabled' => false]);

    $user = User::factory()->create();
    $raw = apiToken($user);

    $this->postJson('/api/v1/events', ['title' => 'Fantasma', 'starts_at' => now()->addDay()->toIso8601String()], bearer($raw))
        ->assertNotFound();

    expect(Event::count())->toBe(0)
        ->and(ApiToken::first()->last_used_at)->toBeNull();
});

test('AC-API-2: no token, an unknown token and a revoked token all answer 401 and touch nothing', function (): void {
    $user = User::factory()->create();
    $revoked = ApiToken::factory()->for($user)->revoked()->create([
        'token_hash' => ApiToken::hashFor($revokedRaw = ApiToken::newRawToken()),
    ]);

    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->getJson('/api/v1/me', bearer('nope-not-a-real-token'))->assertUnauthorized();
    $this->getJson('/api/v1/me', bearer($revokedRaw))->assertUnauthorized();

    expect($revoked->refresh()->last_used_at)->toBeNull();
});

test('AC-API-2: a revoked token stops working on the very next request', function (): void {
    $user = User::factory()->create();
    $raw = apiToken($user);

    $this->getJson('/api/v1/me', bearer($raw))->assertOk();

    ApiToken::query()->update(['revoked_at' => now()]);

    $this->getJson('/api/v1/me', bearer($raw))->assertUnauthorized();
});

test('AC-API-3: only the hash is stored, and a used token records when', function (): void {
    $user = User::factory()->create();
    $raw = apiToken($user);

    $token = ApiToken::first();

    expect($token->token_hash)->toBe(hash('sha256', $raw))
        ->and($token->token_hash)->not->toContain($raw)
        ->and($token->getAttributes())->not->toHaveKey('token')
        ->and($token->last_used_at)->toBeNull();

    $this->getJson('/api/v1/me', bearer($raw))->assertOk();

    expect($token->refresh()->last_used_at)->not->toBeNull();
});

test('AC-API-4: scopes are enforced in both directions, and /me works with either', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->for($user, 'organizer')->create();

    $readOnly = apiToken($user, [ApiToken::READ]);
    $writeOnly = apiToken($user, [ApiToken::WRITE]);

    // A read token cannot write anything.
    $this->postJson('/api/v1/events', ['title' => 'x', 'starts_at' => now()->addDay()->toIso8601String()], bearer($readOnly))->assertForbidden();
    $this->patchJson("/api/v1/events/{$event->id}", ['title' => 'y'], bearer($readOnly))->assertForbidden();
    $this->postJson("/api/v1/events/{$event->id}/publish", [], bearer($readOnly))->assertForbidden();
    $this->postJson("/api/v1/events/{$event->id}/close", [], bearer($readOnly))->assertForbidden();
    $this->postJson("/api/v1/events/{$event->id}/cancel", [], bearer($readOnly))->assertForbidden();

    // And a write token cannot read.
    $this->getJson("/api/v1/events/{$event->id}", bearer($writeOnly))->assertForbidden();

    // The handshake works with any valid token: it is what a consumer calls
    // before it knows what it is allowed to do.
    $this->getJson('/api/v1/me', bearer($readOnly))->assertOk();
    $this->getJson('/api/v1/me', bearer($writeOnly))->assertOk();

    expect(Event::count())->toBe(1); // nothing was created along the way
});

test('AC-API-4: /me answers the handshake the consumer links an account with', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'bar@example.com']);
    $raw = apiToken($user, [ApiToken::WRITE]);

    $this->getJson('/api/v1/me', bearer($raw))
        ->assertOk()
        ->assertExactJson([
            'email' => 'bar@example.com',
            'email_verified' => false,
            'scopes' => [ApiToken::WRITE],
        ]);
});

// ── Events ──────────────────────────────────────────────────────────────────

test('AC-API-5: POST /events creates a draft owned by the token holder and returns its public URL', function (): void {
    $user = User::factory()->create();
    $raw = apiToken($user);

    $response = $this->postJson('/api/v1/events', [
        'title' => 'Ciclo de jazz',
        'description' => 'Tres bandas',
        'starts_at' => now()->addWeek()->toIso8601String(),
        'venue' => 'La Herrería',
        'capacity' => 80,
    ], bearer($raw))->assertCreated();

    $event = Event::sole();

    expect($event->organizer_id)->toBe($user->id)
        ->and($event->status)->toBe(EventStatus::Draft)
        ->and($event->title)->toBe('Ciclo de jazz')
        ->and($event->capacity)->toBe(80);

    $response->assertJsonPath('id', $event->id)
        ->assertJsonPath('slug', $event->slug)
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('public_url', route('public.event', $event));

    // The URL in the answer is the one an attendee opens, not a guess.
    $this->get($response->json('public_url'))->assertNotFound(); // still a draft
});

test('AC-API-5: validation is the web form\'s, shaped as JSON', function (): void {
    $raw = apiToken(User::factory()->create());

    $this->postJson('/api/v1/events', [], bearer($raw))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title', 'starts_at']);

    $this->postJson('/api/v1/events', [
        'title' => str_repeat('a', 181),
        'starts_at' => 'not a date',
        'capacity' => 0,
    ], bearer($raw))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title', 'starts_at', 'capacity']);

    expect(Event::count())->toBe(0);
});

test('AC-API-5b: starts_at with an explicit offset is stored on the instance clock', function (): void {
    config(['app.timezone' => 'America/Argentina/Buenos_Aires']);
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    $raw = apiToken(User::factory()->create());

    $response = $this->postJson('/api/v1/events', [
        'title' => 'Medianoche UTC',
        // 00:00 UTC is 21:00 the previous day in Buenos Aires. Stored raw, this
        // would put the event three hours late and nothing would say so.
        'starts_at' => '2026-09-02T00:00:00Z',
    ], bearer($raw))->assertCreated();

    expect(Event::sole()->getAttributes()['starts_at'])->toBe('2026-09-01 21:00:00');

    // And it comes back in the instance's offset, not the one that was sent.
    expect($response->json('starts_at'))->toBe('2026-09-01T21:00:00-03:00');
});

test('AC-API-5b: a time without an offset is read on the instance clock, like the web form', function (): void {
    config(['app.timezone' => 'America/Argentina/Buenos_Aires']);
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    $raw = apiToken(User::factory()->create());

    $this->postJson('/api/v1/events', [
        'title' => 'Sin offset',
        'starts_at' => '2026-09-02 21:00:00',
    ], bearer($raw))->assertCreated();

    expect(Event::sole()->getAttributes()['starts_at'])->toBe('2026-09-02 21:00:00');
});

test('AC-API-6: the same external_ref twice yields one event, and the second call returns the first', function (): void {
    $user = User::factory()->create();
    $raw = apiToken($user);

    $payload = [
        'external_ref' => 'laherreria:evento:42',
        'title' => 'Peña del sábado',
        'starts_at' => now()->addWeek()->toIso8601String(),
    ];

    $first = $this->postJson('/api/v1/events', $payload, bearer($raw))->assertCreated();

    // The retry a consumer sends when a timeout left it unsure.
    $second = $this->postJson('/api/v1/events', $payload + ['title' => 'Peña del sábado (retry)'], bearer($raw))
        ->assertOk();

    expect(Event::count())->toBe(1)
        ->and($second->json('id'))->toBe($first->json('id'))
        // The retry does not overwrite: creation is idempotent, not an upsert.
        ->and(Event::sole()->title)->toBe('Peña del sábado');
});

test('AC-API-6: the database, not the check, is what guarantees it — and other organizers are unaffected', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    Event::factory()->for($mine, 'organizer')->create(['external_ref' => 'shared:ref']);

    // Same reference, different account: legitimate, two sites integrating.
    $this->postJson('/api/v1/events', [
        'external_ref' => 'shared:ref',
        'title' => 'Otro bar',
        'starts_at' => now()->addWeek()->toIso8601String(),
    ], bearer(apiToken($theirs)))->assertCreated();

    expect(Event::where('external_ref', 'shared:ref')->count())->toBe(2);

    // And the unique index holds even if the fast path is bypassed.
    expect(fn () => Event::factory()->for($mine, 'organizer')->create(['external_ref' => 'shared:ref']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('AC-API-6b: external_ref is optional, and two events without one never collide', function (): void {
    $raw = apiToken(User::factory()->create());

    $payload = ['title' => 'Sin referencia', 'starts_at' => now()->addWeek()->toIso8601String()];

    $first = $this->postJson('/api/v1/events', $payload, bearer($raw))->assertCreated();
    $second = $this->postJson('/api/v1/events', $payload, bearer($raw))->assertCreated();

    expect(Event::count())->toBe(2)
        ->and($second->json('id'))->not->toBe($first->json('id'))
        ->and(Event::pluck('external_ref')->all())->toBe([null, null]);
});

test('AC-API-6c: GET /events/{id} returns exactly the agreed field set', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->for($user, 'organizer')->withCapacity(2)->create();
    app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');

    $response = $this->getJson("/api/v1/events/{$event->id}", bearer(apiToken($user)))->assertOk();

    // Enumerated, so adding or dropping a field is a deliberate contract change.
    // v1.1: the eleven v1 keys, then the three of ADR-012 / SPEC v1.1.
    expect(array_keys($response->json()))->toBe([
        'id', 'slug', 'title', 'description', 'status', 'starts_at', 'venue',
        'capacity', 'issued_tickets_count', 'is_sold_out', 'public_url',
        'access_info', 'payment_info', 'checked_in_count',
    ]);

    $response->assertJsonPath('issued_tickets_count', 1)
        ->assertJsonPath('checked_in_count', 0)
        ->assertJsonPath('is_sold_out', false)
        ->assertJsonPath('capacity', 2);
});

test('AC-API-6c: the counter is a counter — sold out is reported, attendees are not', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->for($user, 'organizer')->withCapacity(1)->create();
    app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');

    $this->getJson("/api/v1/events/{$event->id}", bearer(apiToken($user)))
        ->assertOk()
        ->assertJsonPath('issued_tickets_count', 1)
        ->assertJsonPath('is_sold_out', true);
});

test('AC-API-6c: checked_in_count counts only tickets that came in, and the two text fields round-trip', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->for($user, 'organizer')->create();
    $a = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    app(EventRegistrar::class)->register($event, 'Beto', 'beto@example.com');
    $c = app(EventRegistrar::class)->register($event, 'Caro', 'caro@example.com');
    app(TicketCheckin::class)->checkInTicket($a['ticket'], $user);
    $c['ticket']->forceFill(['status' => TicketStatus::Revoked])->save();

    $raw = apiToken($user);

    $this->getJson("/api/v1/events/{$event->id}", bearer($raw))
        ->assertOk()
        ->assertJsonPath('checked_in_count', 1)
        ->assertJsonPath('issued_tickets_count', 2);

    $this->patchJson("/api/v1/events/{$event->id}", ['access_info' => 'Timbre B', 'payment_info' => 'Entrada $5.000'], bearer($raw))
        ->assertOk()
        ->assertJsonPath('access_info', 'Timbre B')
        ->assertJsonPath('payment_info', 'Entrada $5.000');
});

test('AC-API-7: PATCH updates only what it is sent and never regenerates the slug', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->for($user, 'organizer')->create([
        'title' => 'Título viejo',
        'slug' => 'titulo-viejo',
        'venue' => 'Sala grande',
        'external_ref' => 'laherreria:evento:7',
    ]);

    $this->patchJson("/api/v1/events/{$event->id}", [
        'title' => 'Título nuevo',
        // A consumer that tries to repoint its own idempotency key, deliberately
        // or by echoing back what it read: ignored.
        'external_ref' => 'laherreria:evento:999',
    ], bearer(apiToken($user)))
        ->assertOk()
        ->assertJsonPath('title', 'Título nuevo')
        // The link already printed on somebody else's site keeps working.
        ->assertJsonPath('slug', 'titulo-viejo');

    $event->refresh();

    expect($event->venue)->toBe('Sala grande')
        ->and($event->external_ref)->toBe('laherreria:evento:7');
});

test('AC-API-8: another organizer\'s event is a 404, never a 403', function (): void {
    $mine = User::factory()->create();
    $theirs = Event::factory()->create();
    $raw = apiToken($mine);

    $this->getJson("/api/v1/events/{$theirs->id}", bearer($raw))->assertNotFound();
    $this->patchJson("/api/v1/events/{$theirs->id}", ['title' => 'mío ahora'], bearer($raw))->assertNotFound();
    $this->postJson("/api/v1/events/{$theirs->id}/publish", [], bearer($raw))->assertNotFound();
    $this->postJson("/api/v1/events/{$theirs->id}/cancel", [], bearer($raw))->assertNotFound();

    // An id that does not exist answers identically — nothing here tells the
    // caller which events this instance has.
    $this->getJson('/api/v1/events/999999', bearer($raw))->assertNotFound();

    expect($theirs->refresh()->title)->not->toBe('mío ahora')
        ->and($theirs->status)->toBe(EventStatus::Published);
});

test('AC-API-9: publishing without a verified email is a 422 a person can act on', function (): void {
    $user = User::factory()->unverified()->create();
    $event = Event::factory()->for($user, 'organizer')->draft()->create();

    $this->postJson("/api/v1/events/{$event->id}/publish", [], bearer(apiToken($user)))
        ->assertStatus(422)
        ->assertJsonPath('code', 'email_unverified');

    expect($event->refresh()->status)->toBe(EventStatus::Draft);
});

test('AC-API-9: a verified account publishes, and publishing twice is not an error', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->for($user, 'organizer')->draft()->create();
    $raw = apiToken($user);

    $this->postJson("/api/v1/events/{$event->id}/publish", [], bearer($raw))
        ->assertOk()
        ->assertJsonPath('status', 'published');

    $this->postJson("/api/v1/events/{$event->id}/publish", [], bearer($raw))
        ->assertOk()
        ->assertJsonPath('status', 'published');

    // Now it is the page an attendee opens.
    $this->get(route('public.event', $event->refresh()))->assertOk();
});

test('AC-API-10: close stops registrations, and the event is still on', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->for($user, 'organizer')->create();

    $this->postJson("/api/v1/events/{$event->id}/close", [], bearer(apiToken($user)))
        ->assertOk()
        ->assertJsonPath('status', 'closed');

    expect($event->refresh()->status)->toBe(EventStatus::Closed);
});

test('AC-API-10: cancel mails everyone holding a ticket, exactly like the panel does', function (): void {
    Mail::fake();

    $user = User::factory()->create();
    $event = Event::factory()->for($user, 'organizer')->create();
    app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    app(EventRegistrar::class)->register($event, 'Beto', 'beto@example.com');

    $this->postJson("/api/v1/events/{$event->id}/cancel", [], bearer(apiToken($user)))
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    Mail::assertQueued(EventCancelled::class, 2);

    // Cancelling again must not mail anybody a second time.
    $this->postJson("/api/v1/events/{$event->id}/cancel", [], bearer(apiToken($user)))->assertOk();

    Mail::assertQueued(EventCancelled::class, 2);
});

test('AC-API-11: no answer of this API carries attendee names, emails or ticket tokens', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->for($user, 'organizer')->create(['title' => 'Con público']);
    $issued = app(EventRegistrar::class)->register($event, 'Ana Pérez', 'ana.perez@example.com');
    $raw = apiToken($user);

    $responses = [
        $this->getJson("/api/v1/events/{$event->id}", bearer($raw)),
        $this->patchJson("/api/v1/events/{$event->id}", ['venue' => 'Otra sala'], bearer($raw)),
        $this->postJson("/api/v1/events/{$event->id}/publish", [], bearer($raw)),
        $this->postJson("/api/v1/events/{$event->id}/close", [], bearer($raw)),
        $this->postJson("/api/v1/events/{$event->id}/cancel", [], bearer($raw)),
        $this->postJson('/api/v1/events', ['title' => 'Nuevo', 'starts_at' => now()->addDay()->toIso8601String()], bearer($raw)),
        $this->getJson('/api/v1/me', bearer($raw)),
    ];

    foreach ($responses as $response) {
        $body = (string) $response->getContent();

        expect($body)->not->toContain('Ana Pérez')
            ->and($body)->not->toContain('ana.perez@example.com')
            ->and($body)->not->toContain($issued['token'])
            ->and($body)->not->toContain($issued['ticket']->token_hash);
    }
});
