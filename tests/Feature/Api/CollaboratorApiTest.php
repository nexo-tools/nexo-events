<?php

use App\Mail\CollaboratorInvited;
use App\Mail\CollaboratorInvitedByIntegration;
use App\Models\ApiToken;
use App\Models\Event;
use App\Models\EventCollaborator;
use App\Models\User;
use App\Services\EventRegistrar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/ApiTestSupport.php';

/**
 * Integration API v1.1 — collaborators (SPEC-integration-api, ADR-011).
 */
beforeEach(function (): void {
    config(['nexo.api.enabled' => true]);
    Mail::fake();
});

/** An event the integration created: owned by $owner, with an external_ref. */
function integrationEvent(User $owner): Event
{
    $event = Event::factory()->for($owner, 'organizer')->create();
    $event->forceFill(['external_ref' => 'site:event:'.$event->id])->save();

    return $event;
}

function collaboratorsUrl(Event $event, ?int $cid = null): string
{
    return "/api/v1/events/{$event->id}/collaborators".($cid !== null ? "/{$cid}" : '');
}

test('AC-API-16: without collaborators:manage the three endpoints answer 403', function (): void {
    $owner = User::factory()->create();
    $event = integrationEvent($owner);
    $row = EventCollaborator::factory()->for($event)->create();
    $raw = apiToken($owner, [ApiToken::READ, ApiToken::WRITE]);

    $this->getJson(collaboratorsUrl($event), bearer($raw))->assertForbidden();
    $this->postJson(collaboratorsUrl($event), ['email' => 'banda@example.com'], bearer($raw))->assertForbidden();
    $this->deleteJson(collaboratorsUrl($event, $row->id), [], bearer($raw))->assertForbidden();

    expect(EventCollaborator::count())->toBe(1)->and($row->fresh()->revoked_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('AC-API-16: with the scope, GET lists collaborators and nothing about attendees', function (): void {
    $owner = User::factory()->create();
    $event = integrationEvent($owner);
    $raw = apiToken($owner, [ApiToken::MANAGE_COLLABORATORS]);
    $this->postJson(collaboratorsUrl($event), ['email' => 'banda@example.com'], bearer($raw))->assertCreated();

    $list = $this->getJson(collaboratorsUrl($event), bearer($raw))->assertOk()->json();

    expect($list)->toHaveCount(1)
        ->and(array_keys($list[0]))->toBe(['id', 'email', 'status', 'invited_at', 'expires_at', 'accepted_at', 'invited_via'])
        ->and($list[0]['status'])->toBe('pending')
        ->and($list[0]['email'])->toBe('banda@example.com');
});

test('AC-API-17: a new email answers 201 with the link once, and mails the invitee and the owner', function (): void {
    $owner = User::factory()->create();
    $event = integrationEvent($owner);
    $raw = apiToken($owner, [ApiToken::MANAGE_COLLABORATORS]);
    ApiToken::query()->latest('id')->first()->forceFill(['name' => 'Web de la sala'])->save();

    $response = $this->postJson(collaboratorsUrl($event), ['email' => 'Banda@Example.com', 'locale' => 'es'], bearer($raw))
        ->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('invited_via', 'Web de la sala');

    $url = (string) $response->json('invite_url');
    expect($url)->toStartWith(url('/app/invitaciones/'))
        ->and(EventCollaborator::sole()->invite_token_hash)->toBe(hash('sha256', basename($url)));

    Mail::assertQueued(CollaboratorInvited::class, fn (CollaboratorInvited $m): bool => $m->hasTo('banda@example.com'));
    Mail::assertQueued(CollaboratorInvitedByIntegration::class, fn (CollaboratorInvitedByIntegration $m): bool => $m->hasTo($owner->email)
        && $m->tokenName === 'Web de la sala');
});

test('AC-API-17: repeating on a pending email answers 200 and rotates; on an accepted one, 200 and no mail', function (): void {
    $owner = User::factory()->create();
    $event = integrationEvent($owner);
    $raw = apiToken($owner);

    $first = $this->postJson(collaboratorsUrl($event), ['email' => 'banda@example.com'], bearer($raw))->assertCreated();
    $hash = EventCollaborator::sole()->invite_token_hash;

    $second = $this->postJson(collaboratorsUrl($event), ['email' => 'banda@example.com'], bearer($raw))->assertOk();

    expect($second->json('invite_url'))->not->toBe($first->json('invite_url'))
        ->and(EventCollaborator::sole()->invite_token_hash)->not->toBe($hash);

    $band = User::factory()->create(['email' => 'banda@example.com']);
    $this->actingAs($band)->post($second->json('invite_url').'/aceptar')->assertRedirect();

    Mail::fake();
    $third = $this->postJson(collaboratorsUrl($event), ['email' => 'banda@example.com'], bearer($raw))
        ->assertOk()->assertJsonPath('status', 'accepted');

    expect(array_key_exists('invite_url', $third->json()))->toBeFalse();
    Mail::assertNothingQueued();
});

test('AC-API-18: DELETE revokes, is idempotent, and the collaborator is out on the next request', function (): void {
    $owner = User::factory()->create();
    $event = integrationEvent($owner);
    $band = User::factory()->create(['email' => 'banda@example.com']);
    $row = EventCollaborator::factory()->for($event)->acceptedBy($band)->create();
    $raw = apiToken($owner);

    $this->actingAs($band)->get(route('events.registrations', $event))->assertOk();

    $this->deleteJson(collaboratorsUrl($event, $row->id), [], bearer($raw))->assertNoContent();
    $this->deleteJson(collaboratorsUrl($event, $row->id), [], bearer($raw))->assertNoContent();

    expect($row->fresh()->revoked_at)->not->toBeNull();
    $this->actingAs($band)->get(route('events.registrations', $event))->assertForbidden();

    $this->getJson(collaboratorsUrl($event), bearer($raw))->assertJsonPath('0.status', 'revoked');
});

test('AC-API-19: another organizer\'s event, and the owner\'s own event without external_ref, are 404', function (): void {
    $owner = User::factory()->create();
    $raw = apiToken($owner);
    $handMade = Event::factory()->for($owner, 'organizer')->create();
    $handRow = EventCollaborator::factory()->for($handMade)->create();
    $theirs = integrationEvent(User::factory()->create());
    $theirRow = EventCollaborator::factory()->for($theirs)->create();

    foreach ([[$handMade, $handRow], [$theirs, $theirRow]] as [$event, $row]) {
        $this->getJson(collaboratorsUrl($event), bearer($raw))->assertNotFound();
        $this->postJson(collaboratorsUrl($event), ['email' => 'banda@example.com'], bearer($raw))->assertNotFound();
        $this->deleteJson(collaboratorsUrl($event, $row->id), [], bearer($raw))->assertNotFound();
        expect($row->fresh()->revoked_at)->toBeNull();
    }

    // A collaborator id of another event, through an event the token may touch.
    $mine = integrationEvent($owner);
    $this->deleteJson(collaboratorsUrl($mine, $theirRow->id), [], bearer($raw))->assertNotFound();

    expect(EventCollaborator::count())->toBe(2);
    Mail::assertNothingQueued();
});

test('AC-API-20: the eleventh active invitation is refused, and so is an unverified owner', function (): void {
    $owner = User::factory()->create();
    $event = integrationEvent($owner);
    $raw = apiToken($owner);

    config(['nexo.api.rate_write' => 100]);

    foreach (range(1, 10) as $n) {
        $this->postJson(collaboratorsUrl($event), ['email' => "banda{$n}@example.com"], bearer($raw))->assertCreated();
    }

    $this->postJson(collaboratorsUrl($event), ['email' => 'banda11@example.com'], bearer($raw))
        ->assertUnprocessable()->assertJsonPath('code', 'too_many_collaborators');

    $unverified = User::factory()->unverified()->create();
    $theirs = integrationEvent($unverified);
    $this->postJson(collaboratorsUrl($theirs), ['email' => 'banda@example.com'], bearer(apiToken($unverified)))
        ->assertUnprocessable()->assertJsonPath('code', 'email_unverified');

    expect(EventCollaborator::count())->toBe(10);
});

test('AC-API-21: the panel offers the new scope with its warning, unticked, and still refuses invented ones', function (): void {
    $organizer = User::factory()->create();

    $html = (string) $this->actingAs($organizer)->get(route('integrations.index'))->assertOk()->getContent();

    expect($html)->toContain('value="collaborators:manage"')
        ->and($html)->toContain('data-collaborators-warning')
        ->and(preg_match('/value="collaborators:manage"[^>]*checked/', $html))->toBe(0);

    $this->actingAs($organizer)->post(route('integrations.store'), [
        'name' => 'Listillo',
        'abilities' => ['collaborators:manage', 'collaborators:admin'],
    ])->assertSessionHasErrors('abilities.1');

    $this->actingAs($organizer)->post(route('integrations.store'), [
        'name' => 'Web de la sala',
        'abilities' => ['events:read', 'events:write', 'collaborators:manage'],
    ])->assertRedirect();

    expect(ApiToken::sole()->abilities)->toBe(['events:read', 'events:write', 'collaborators:manage']);
});

test('AC-API-22: with the API off the three endpoints answer 404', function (): void {
    config(['nexo.api.enabled' => false]);

    $owner = User::factory()->create();
    $event = integrationEvent($owner);
    $row = EventCollaborator::factory()->for($event)->create();
    $raw = apiToken($owner);

    $this->getJson(collaboratorsUrl($event), bearer($raw))->assertNotFound();
    $this->postJson(collaboratorsUrl($event), ['email' => 'banda@example.com'], bearer($raw))->assertNotFound();
    $this->deleteJson(collaboratorsUrl($event, $row->id), [], bearer($raw))->assertNotFound();
});

test('AC-API-22: route:cache with the flag off bakes the collaborator routes in', function (): void {
    config(['nexo.api.enabled' => false]);

    try {
        Artisan::call('route:cache');
        $contents = (string) File::get((string) collect(File::glob(base_path('bootstrap/cache/routes-*.php')))->first());

        expect($contents)->toContain('api/v1/events/{event}/collaborators')
            ->and($contents)->toContain('api/v1/events/{event}/collaborators/{collaborator}');
    } finally {
        Artisan::call('route:clear');
    }
});

test('AC-API-23: the list never carries the link nor any hash', function (): void {
    $owner = User::factory()->create();
    $event = integrationEvent($owner);
    $raw = apiToken($owner);
    $url = (string) $this->postJson(collaboratorsUrl($event), ['email' => 'banda@example.com'], bearer($raw))->json('invite_url');

    $body = (string) $this->getJson(collaboratorsUrl($event), bearer($raw))->getContent();

    expect($body)->not->toContain('invite_url')
        ->and($body)->not->toContain(basename($url))
        ->and($body)->not->toContain((string) EventCollaborator::sole()->invite_token_hash)
        ->and($body)->not->toContain('hash');
});

test('AC-API-11: the collaborator endpoints carry no attendee data either', function (): void {
    $owner = User::factory()->create();
    $event = integrationEvent($owner);
    $issued = app(EventRegistrar::class)->register($event, 'Ana Pérez', 'ana.perez@example.com');
    $raw = apiToken($owner);

    $created = $this->postJson(collaboratorsUrl($event), ['email' => 'banda@example.com'], bearer($raw));
    $responses = [
        $created,
        $this->getJson(collaboratorsUrl($event), bearer($raw)),
        $this->deleteJson(collaboratorsUrl($event, (int) $created->json('id')), [], bearer($raw)),
        $this->getJson("/api/v1/events/{$event->id}", bearer($raw)),
    ];

    foreach ($responses as $response) {
        $body = (string) $response->getContent();

        expect($body)->not->toContain('Ana Pérez')
            ->and($body)->not->toContain('ana.perez@example.com')
            ->and($body)->not->toContain($issued['token'])
            ->and($body)->not->toContain($issued['ticket']->token_hash);
    }
});

test('AC-API-13: inviting through the API is a write, and the write limit applies', function (): void {
    config(['nexo.api.rate_write' => 2]);

    $owner = User::factory()->create();
    $event = integrationEvent($owner);
    $raw = apiToken($owner);

    $this->postJson(collaboratorsUrl($event), ['email' => 'uno@example.com'], bearer($raw))->assertCreated();
    $this->postJson(collaboratorsUrl($event), ['email' => 'dos@example.com'], bearer($raw))->assertCreated();
    $this->postJson(collaboratorsUrl($event), ['email' => 'tres@example.com'], bearer($raw))->assertStatus(429);

    expect(EventCollaborator::count())->toBe(2);

    // And the gate still wins over the limiter.
    config(['nexo.api.enabled' => false]);
    $this->postJson(collaboratorsUrl($event), ['email' => 'cuatro@example.com'], bearer($raw))->assertNotFound();
});
