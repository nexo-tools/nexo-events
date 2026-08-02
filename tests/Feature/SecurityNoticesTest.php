<?php

use App\Mail\NexoIdLinked;
use App\Mail\PasswordChanged;
use App\Models\User;
use App\Services\NexoSso\NexoSsoUserResolver;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * The two security notices every account in the ecosystem owes its owner
 * (family rule C5): the password changed, and single sign-on was linked.
 */
it('AC-SEC-1: tells the owner when their password is changed', function (): void {
    Mail::fake();

    $user = User::factory()->create(['email' => 'ana@example.com']);
    $token = Password::createToken($user);

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertRedirect();

    // A reset is exactly what somebody who took over an inbox does first: this
    // mail is the only signal the real owner gets while they can still act.
    Mail::assertQueued(PasswordChanged::class, fn (PasswordChanged $mail): bool => $mail->hasTo('ana@example.com'));
});

it('AC-SEC-2: tells the owner the first time Nexo ID is linked to their account', function (): void {
    Mail::fake();

    $user = User::factory()->create(['email' => 'ana@example.com']);

    app(NexoSsoUserResolver::class)->resolve([
        'sub' => 'nexo-id-sub-1',
        'email' => 'ana@example.com',
        'email_verified' => true,
        'name' => 'Ana',
    ]);

    Mail::assertQueued(NexoIdLinked::class, fn (NexoIdLinked $mail): bool => $mail->hasTo('ana@example.com'));
    expect($user->fresh()->nexo_id_sub)->toBe('nexo-id-sub-1');
});

it('AC-SEC-3: does not repeat the linking notice on every sign-in', function (): void {
    Mail::fake();

    User::factory()->create(['email' => 'ana@example.com']);
    $claims = ['sub' => 'nexo-id-sub-1', 'email' => 'ana@example.com', 'email_verified' => true, 'name' => 'Ana'];

    app(NexoSsoUserResolver::class)->resolve($claims);
    app(NexoSsoUserResolver::class)->resolve($claims);
    app(NexoSsoUserResolver::class)->resolve($claims);

    // Signing in again is not news; only the link itself is.
    Mail::assertQueuedCount(1);
});

it('AC-SEC-4: sends no linking notice to an account created by SSO', function (): void {
    Mail::fake();

    app(NexoSsoUserResolver::class)->resolve([
        'sub' => 'nexo-id-sub-2',
        'email' => 'new@example.com',
        'email_verified' => true,
        'name' => 'New',
    ]);

    // Nothing was linked: the account was born from the claims, and telling
    // somebody "we linked X to your account" about an account they just created
    // through X is noise.
    Mail::assertNothingQueued();
});
