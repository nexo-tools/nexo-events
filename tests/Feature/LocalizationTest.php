<?php

use Illuminate\Support\Facades\Process;

it('defaults to spanish on the home page', function () {
    $this->get('/')->assertSee('Crea eventos gratis');
});

it('switches with the lang parameter and persists in the shared nexo-lang cookie', function () {
    // Persistence moved from the session to a cookie scoped to the parent
    // domain, so the choice now survives across the whole Nexo ecosystem rather
    // than just this app's session.
    $this->get('/?lang=en')->assertSee('Create free events');

    // A browser carrying that cookie keeps English with no parameter.
    $this->withUnencryptedCookie('nexo-lang', 'en')->get('/')->assertSee('Create free events');
});

it('ignores unsupported locales', function () {
    $this->get('/?lang=fr')->assertSee('Crea eventos gratis');
});

it('shows the locale switcher', function () {
    $this->get('/')
        ->assertSee('lang=es', false)
        ->assertSee('lang=en', false)
        ->assertSee('lang=pt', false);
});

it('translates validation messages', function () {
    $response = $this->post('/register', [], ['Accept-Language' => 'pt']);

    $response->assertSessionHasErrors();
    expect(session('errors')->first('name'))->toContain('obrigatório');
});

it('keeps every string translated in en and pt', function () {
    $result = Process::path(base_path())
        ->run('node scripts/generate-translations.mjs --check');

    expect($result->exitCode())->toBe(0, 'Faltan traducciones: '.$result->errorOutput());
})->skip(fn () => Process::run('which node')->failed(), 'node no disponible');
