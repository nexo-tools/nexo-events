<?php

use App\Models\User;
use App\Services\NexoSso\NexoSsoFlows;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/NexoSsoTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'nexo-sso.enabled' => true,
        'nexo-sso.issuer' => 'https://nexoid.test',
        'nexo-sso.client_id' => '11111111-2222-3333-4444-555555555555',
    ]);
});

test('AC-LINK-1: first SSO login with no local account creates and links a local user', function (): void {
    nexoSsoFakeProvider();

    nexoSsoCallback($this)->assertRedirect(config('nexo-sso.home'));

    $user = User::query()->where('email', 'user@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->nexo_id_sub)->toBe('user-uuid-0001')
        ->and($user->name)->toBe('Test User');
    $this->assertAuthenticatedAs($user);
});

// NOTE: don't call nexoSsoFakeProvider() twice in one test — Http::fake stacks
// stubs and the first registration keeps winning for the same URL.
test('AC-LINK-2: linking is refused when the provider has not verified the email', function (): void {
    $local = User::factory()->create(['email' => 'user@example.com']);

    nexoSsoFakeProvider([
        'access_token' => 'fake', 'token_type' => 'Bearer',
        'id_token' => nexoSsoIdToken(['email_verified' => false]),
    ]);

    nexoSsoCallback($this)->assertRedirect(route('login'))->assertSessionHasErrors('nexo_sso');
    $this->assertGuest();
    expect($local->fresh()->nexo_id_sub)->toBeNull();
});

test('AC-LINK-2: a provider-verified email links the existing local account', function (): void {
    $local = User::factory()->create(['email' => 'user@example.com']);

    nexoSsoFakeProvider();

    nexoSsoCallback($this)->assertRedirect(config('nexo-sso.home'));
    expect($local->fresh()->nexo_id_sub)->toBe('user-uuid-0001')
        ->and(User::query()->count())->toBe(1);
    $this->assertAuthenticatedAs($local->fresh());
});

test('AC-LINK-3: returning users match by sub even if their email changed on Nexo ID', function (): void {
    $linked = User::factory()->create(['email' => 'old@example.com']);
    $linked->forceFill(['nexo_id_sub' => 'user-uuid-0001'])->save();

    nexoSsoFakeProvider([
        'access_token' => 'fake', 'token_type' => 'Bearer',
        'id_token' => nexoSsoIdToken(['email' => 'brand-new@example.com']),
    ]);

    nexoSsoCallback($this)->assertRedirect(config('nexo-sso.home'));

    $this->assertAuthenticatedAs($linked->fresh());
    expect(User::query()->count())->toBe(1); // no duplicate account
});

test('AC-LINK-4: an account already joined to ANOTHER Nexo ID is never re-joined through its email', function (): void {
    // Its owner linked a Nexo ID with another email; the address the account
    // still carries here may not even be theirs any more.
    $local = User::factory()->create(['email' => 'user@example.com']);
    $local->forceFill(['nexo_id_sub' => 'owner-sub'])->save();

    nexoSsoFakeProvider(); // sub user-uuid-0001, verified user@example.com

    nexoSsoCallback($this)->assertRedirect(route('login'))->assertSessionHasErrors([
        'nexo_sso' => __('This account is already linked to another Nexo ID account.'),
    ]);

    $this->assertGuest();
    expect($local->fresh()->nexo_id_sub)->toBe('owner-sub')
        ->and(User::query()->count())->toBe(1);
});

test('AC-LINK-4: a silent attempt that meets such an account stays invisible: back to the page, a guest', function (): void {
    $local = User::factory()->create(['email' => 'user@example.com']);
    $local->forceFill(['nexo_id_sub' => 'owner-sub'])->save();
    config(['nexo-sso.silent' => true]);

    nexoSsoFakeProvider();

    nexoSsoCallback($this, mode: NexoSsoFlows::SILENT, return: '/nexo-sso-tests/landing')
        ->assertRedirect(url('/nexo-sso-tests/landing'))
        ->assertSessionDoesntHaveErrors();

    $this->assertGuest();
    expect($local->fresh()->nexo_id_sub)->toBe('owner-sub');
});
