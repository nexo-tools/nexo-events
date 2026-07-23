<?php

use App\Models\User;

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

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->from('/register')->post('/register', [
        'name' => 'X',
        'email' => 'taken@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('email');
});
