<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

it('shows the registration page', function () {
    $this->get('/register')->assertOk()->assertSee('Crea tu cuenta');
});

it('registers an organizer and logs them in', function () {
    $response = $this->post('/register', [
        'name' => 'Ana Organizadora',
        'email' => 'ana@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertRedirect('/app');
    $this->assertAuthenticated();
    expect(User::where('email', 'ana@example.com')->exists())->toBeTrue();
});

it('requires name, email and a confirmed password', function () {
    $this->from('/register')->post('/register', [])
        ->assertSessionHasErrors(['name', 'email', 'password']);
});

it('AC-ABUSE-6 the sign-up form carries the honeypot, hidden from people and assistive tech', function () {
    $this->get('/register')->assertOk()
        ->assertSee('<input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">', false);
});

it('AC-ABUSE-6 a bot that fills the honeypot gets no account, no session and no verification mail', function () {
    Notification::fake();

    $this->from('/register')->post('/register', [
        'name' => 'qkzvbwmrta',
        'email' => 'someone-else@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'website' => 'http://spam.test',
    ])->assertRedirect('/register')->assertSessionHasErrors('website');

    $this->assertGuest();
    expect(User::where('email', 'someone-else@example.com')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('AC-ABUSE-6 a person who leaves the honeypot empty signs up as before', function () {
    $this->post('/register', [
        'name' => 'Ana Organizadora',
        'email' => 'ana@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'website' => '',
    ])->assertRedirect('/app');

    $this->assertAuthenticated();
});

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->from('/register')->post('/register', [
        'name' => 'X',
        'email' => 'taken@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('email');
});
