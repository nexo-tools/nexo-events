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

// The reference text of the nexo-sso-client README («The "Nexo ID was linked"
// mail»): no «identity provider», «unir» in Spanish, and the password lines
// only where the password keeps working (local mode).

it('AC-SEC-5: in local mode the mail says the password keeps working, and how the account got joined', function (string $how, string $footer): void {
    config(['nexo-sso.enabled' => true, 'nexo-sso.auth_mode' => 'local']);
    $html = (new NexoIdLinked(User::factory()->create(), $how))->render();

    expect($html)->toContain(e(__('From now on you can sign in here with your Nexo ID. Your password keeps working — nothing else changed.')))
        ->toContain(e(__($footer)));
})->with([
    'by email' => [NexoSsoUserResolver::BY_EMAIL, 'If you did not do this, change your password and write to us: Nexo ID confirmed that this email address is yours, so someone with access to it linked the account.'],
    'explicit' => [NexoSsoUserResolver::EXPLICIT, 'If you did not do this, change your password and write to us: someone who knew your password signed in here and linked the account.'],
]);

it('AC-SEC-5: in transition and sso the mail never mentions a password that no longer signs in', function (string $mode, string $how, string $footer): void {
    config(['nexo-sso.enabled' => true, 'nexo-sso.auth_mode' => $mode, 'nexo-sso.transition_until' => now()->addMonth()->format('Y-m-d')]);
    $html = (new NexoIdLinked(User::factory()->create(), $how))->render();

    expect($html)->toContain(e(__('From now on you sign in here with your Nexo ID — nothing else changed.')))
        ->toContain(e(__($footer)))
        ->not->toContain(e(__('From now on you can sign in here with your Nexo ID. Your password keeps working — nothing else changed.')))
        ->not->toContain('change your password');
})->with([
    'sso, by email' => ['sso', NexoSsoUserResolver::BY_EMAIL, 'If you did not do this, write to us: Nexo ID confirmed that this email address is yours, so someone with access to it linked the account.'],
    'transition, explicit' => ['transition', NexoSsoUserResolver::EXPLICIT, 'If you did not do this, write to us: someone who knew your password signed in here and linked the account.'],
]);

it('AC-SEC-5: the mail speaks of Nexo ID, never of an «identity provider», in every language', function (string $mode, string $locale, string $expected): void {
    config(['nexo-sso.enabled' => true, 'nexo-sso.auth_mode' => $mode]);
    $html = html_entity_decode((new NexoIdLinked(User::factory()->create(), NexoSsoUserResolver::BY_EMAIL))->locale($locale)->render());

    expect($html)->toContain($expected)
        ->not->toMatch('/identity provider|proveedor de identidad|provedor de identidade/i');
})->with([
    'sso, es' => ['sso', 'es', 'Si no fuiste tú, escríbenos: Nexo ID confirmó que ese correo es tuyo, así que alguien con acceso a él unió la cuenta.'],
    'local, es' => ['local', 'es', 'Si no fuiste tú, cambia tu contraseña y escríbenos: Nexo ID confirmó que ese correo es tuyo'],
    'sso, en' => ['sso', 'en', 'Nexo ID confirmed that this email address is yours'],
    'local, pt' => ['local', 'pt', 'o Nexo ID confirmou que esse e-mail é seu'],
]);

it('AC-SEC-5: in Spanish the subject and the title say «quedó unida», as every screen says «unir»', function (string $mode, string $how): void {
    config(['nexo-sso.enabled' => true, 'nexo-sso.auth_mode' => $mode]);
    $mail = (new NexoIdLinked(User::factory()->create(), $how))->locale('es');
    $html = html_entity_decode($mail->render());

    $mail->assertHasSubject('Tu cuenta quedó unida a Nexo ID');
    expect($html)->toContain('Tu cuenta quedó unida a Nexo ID')
        ->not->toMatch('/vincul/iu');
})->with([
    'sso, by email' => ['sso', NexoSsoUserResolver::BY_EMAIL],
    'local, explicit' => ['local', NexoSsoUserResolver::EXPLICIT],
]);
