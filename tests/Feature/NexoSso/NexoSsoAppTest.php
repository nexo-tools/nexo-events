<?php

// What the sso-client's screens and messages say about the tool itself
// (v2.2): its name — the chrome's brand when the app has nexo-ui, so the
// screens say the same name as the header — and the support email, only
// when the operator configured a real one.

use App\Services\NexoSso\NexoSsoApp;
use App\Support\NexoChrome;

test('AC-APP-1: the name is the chrome brand (the ecosystem registry), not APP_NAME', function (): void {
    if (! class_exists(NexoChrome::class)) {
        config(['app.name' => 'Plain App Name']);
        expect(NexoSsoApp::name())->toBe('Plain App Name');

        return;
    }

    $current = NexoChrome::current();
    expect($current)->not->toBeNull();
    config([
        'app.name' => 'NexoPlainAppName',
        'nexo-ecosystem.tools.'.$current.'.name' => 'Nexo Brand Name',
    ]);

    expect(NexoSsoApp::name())->toBe('Nexo Brand Name');
});

test('AC-APP-1: without a brand it falls back to APP_NAME', function (): void {
    config(['app.name' => 'Plain App Name', 'nexo-ecosystem.current' => 'no-such-tool']);

    expect(NexoSsoApp::name())->toBe('Plain App Name');
});

test('AC-APP-2: the support email is the configured one, trimmed', function (): void {
    config(['nexo.support_email' => '  help@alvarocdev.com ']);

    expect(NexoSsoApp::supportEmail())->toBe('help@alvarocdev.com');
});

test('AC-APP-2: no support email when it is missing, invalid or a placeholder', function (mixed $email): void {
    config(['nexo.support_email' => $email]);

    expect(NexoSsoApp::supportEmail())->toBeNull();
})->with([
    'unset' => null,
    'empty' => '',
    'not a string' => [['help@alvarocdev.com']],
    'not an email' => 'help',
    'example.com' => 'hola@example.com',
    'EXAMPLE.ORG, any case' => 'Hola@EXAMPLE.ORG',
    'example.net' => 'a@example.net',
    'a name under example.com' => 'soporte@mail.example.com',
    'a name under example.org' => 'hola@help.EXAMPLE.org',
    'a .test domain' => 'ops@tool.test',
    'a .example domain' => 'ops@tool.example',
    'a .invalid domain' => 'ops@tool.invalid',
    'localhost' => 'root@localhost',
]);
