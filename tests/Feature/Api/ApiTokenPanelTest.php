<?php

use App\Models\ApiToken;
use App\Models\User;

require_once __DIR__.'/ApiTestSupport.php';

/**
 * AC-API-15 / 15b — the organizer's side: issue, see, revoke. And the flag,
 * because a self-hoster who never turns the API on must not find a screen for
 * it in their panel.
 */
beforeEach(function (): void {
    config(['nexo.api.enabled' => true]);
});

test('AC-API-15: an organizer issues a token, and sees its raw value exactly once', function (): void {
    $organizer = User::factory()->create();

    $response = $this->actingAs($organizer)->post(route('integrations.store'), [
        'name' => 'Web La Herrería',
        'abilities' => [ApiToken::READ, ApiToken::WRITE],
    ])->assertRedirect(route('integrations.index'));

    $token = ApiToken::sole();

    expect($token->user_id)->toBe($organizer->id)
        ->and($token->name)->toBe('Web La Herrería')
        ->and($token->abilities)->toBe([ApiToken::READ, ApiToken::WRITE]);

    // The raw value travels once, in a flash, and works.
    $raw = $response->getSession()->get('api_token_plain');

    expect($raw)->toBeString()
        ->and(strlen((string) $raw))->toBe(40)
        ->and($token->token_hash)->toBe(hash('sha256', (string) $raw));

    $page = $this->actingAs($organizer)->get(route('integrations.index'))->assertOk();
    $page->assertSee($raw);
    $page->assertSee('Web La Herrería');

    // On the next visit it is gone from the screen for good.
    $this->actingAs($organizer)->get(route('integrations.index'))->assertOk()->assertDontSee($raw);

    // And it is a token that actually works.
    $this->getJson('/api/v1/me', bearer((string) $raw))->assertOk();
});

test('AC-API-15: the list shows what an organizer decides with, and nothing they cannot act on', function (): void {
    $organizer = User::factory()->create();
    ApiToken::factory()->for($organizer)->create(['name' => 'Sitio viejo', 'last_used_at' => now()->subDays(3)]);
    ApiToken::factory()->for($organizer)->create(['name' => 'Sitio nuevo']);
    ApiToken::factory()->create(['name' => 'De otra cuenta']);

    $this->actingAs($organizer)->get(route('integrations.index'))
        ->assertOk()
        ->assertSee('Sitio viejo')
        ->assertSee('Sitio nuevo')
        ->assertSee(ApiToken::WRITE)
        // Somebody else's integrations are none of this account's business.
        ->assertDontSee('De otra cuenta');
});

test('AC-API-15: revoking takes effect on the very next request', function (): void {
    $organizer = User::factory()->create();
    $raw = apiToken($organizer);
    $token = ApiToken::sole();

    $this->getJson('/api/v1/me', bearer($raw))->assertOk();

    $this->actingAs($organizer)->post(route('integrations.revoke', $token))
        ->assertRedirect(route('integrations.index'));

    expect($token->refresh()->revoked_at)->not->toBeNull();

    $this->getJson('/api/v1/me', bearer($raw))->assertUnauthorized();
});

test('AC-API-15: nobody revokes somebody else\'s integration', function (): void {
    $mine = User::factory()->create();
    $theirs = ApiToken::factory()->create();

    $this->actingAs($mine)->post(route('integrations.revoke', $theirs))->assertForbidden();

    expect($theirs->refresh()->revoked_at)->toBeNull();
});

test('AC-API-15: a token has to say what it is and what it may do', function (): void {
    $organizer = User::factory()->create();

    $this->actingAs($organizer)->post(route('integrations.store'), [])
        ->assertSessionHasErrors(['name', 'abilities']);

    // And it cannot invent a scope the product does not have.
    $this->actingAs($organizer)->post(route('integrations.store'), [
        'name' => 'Listillo',
        'abilities' => ['events:read', 'attendees:read'],
    ])->assertSessionHasErrors('abilities.1');

    expect(ApiToken::count())->toBe(0);
});

test('AC-API-15: the panel is behind auth, like the rest of the organizer area', function (): void {
    $this->get(route('integrations.index'))->assertRedirect(route('login'));
});

test('AC-API-15b: with the API off the panel does not exist, and the dashboard does not mention it', function (): void {
    config(['nexo.api.enabled' => false]);

    $organizer = User::factory()->create();
    $token = ApiToken::factory()->for($organizer)->create();

    $this->actingAs($organizer)->get(route('integrations.index'))->assertNotFound();
    $this->actingAs($organizer)->post(route('integrations.store'), ['name' => 'x', 'abilities' => [ApiToken::READ]])->assertNotFound();
    $this->actingAs($organizer)->post(route('integrations.revoke', $token))->assertNotFound();

    $this->actingAs($organizer)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('integrations.index'));

    expect(ApiToken::count())->toBe(1); // the disabled panel wrote nothing
});

test('AC-API-15b: with the API on, the dashboard offers the way in', function (): void {
    $organizer = User::factory()->create();

    $this->actingAs($organizer)->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('integrations.index'));
});

test('AC-API-15: the panel speaks the three languages of the product', function (): void {
    $organizer = User::factory()->create();

    $es = (string) $this->actingAs($organizer)->get(route('integrations.index'))->assertOk()->getContent();
    $en = (string) $this->actingAs($organizer)->get(route('integrations.index').'?lang=en')->assertOk()->getContent();
    $pt = (string) $this->actingAs($organizer)->get(route('integrations.index').'?lang=pt')->assertOk()->getContent();

    expect($es)->toContain('Integraciones')
        ->and($en)->toContain('Integrations')
        ->and($pt)->toContain('Integrações')
        // The heading is genuinely different in each, so this is translation
        // and not three copies of the English falling through.
        ->and($es)->not->toContain('<h1 class="mb-1 text-xl font-bold">Integrations</h1>');
});
