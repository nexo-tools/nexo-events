<?php

use App\Models\Event;
use Illuminate\Support\Facades\Process;

/**
 * The scanner and a session that ended mid-door (PLAN 10.23) — SPEC
 * docs/specs/SPEC-event-panel.md. The camera path itself needs a browser; this
 * pins down the two halves that do not: what the endpoint answers, and what
 * scanner.js makes of each answer (its node test).
 */
it('AC-PANEL-9: scanner.js reads a 401 or 419 as a closed session and keeps every other answer as it was', function (): void {
    if (Process::run('node --version')->failed()) {
        $this->markTestSkipped('node is not available in this environment.');
    }

    $run = Process::path(base_path())->run(['node', '--test', '--test-reporter=tap', 'tests/js/scanner.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('# pass 5')
        ->and($run->output())->toContain('# fail 0');
});

it('AC-PANEL-9: the door page hands the scanner the closed-session words in the visitor\'s language', function (): void {
    $event = Event::factory()->create();

    foreach (['es' => ['Tu sesión se cerró.', 'Volver a entrar'], 'en' => ['Your session ended.', 'Sign in again'], 'pt' => ['Sua sessão foi encerrada.', 'Entrar de novo']] as $lang => [$message, $button]) {
        $html = (string) $this->actingAs($event->organizer)->get(route('events.scan', $event).'?lang='.$lang)->assertOk()->getContent();
        preg_match("#data-scanner-labels='([^']*)'#", $html, $raw);
        $labels = json_decode(html_entity_decode($raw[1]), true);

        expect($labels['signedOut'])->toStartWith($message)
            ->and($labels['signIn'])->toBe($button)
            ->and($labels['failed'])->not->toBe($labels['signedOut']);
    }
});

it('AC-PANEL-9: a scan without a session is answered with a redirect to sign in — what the scanner now reads as a closed session', function (): void {
    $event = Event::factory()->create();

    // Today's web routes send even a JSON request to sign in (only api/*
    // renders JSON errors). The scanner does not follow it (redirect: manual);
    // following it used to end in "No connection". The 419 of a dead CSRF
    // token cannot be produced here — the framework skips CSRF under unit
    // tests — and is exercised in the browser pass instead.
    $this->post(route('events.checkin', $event), ['token' => 'x'], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertRedirect(route('login'));
});
