<?php

use App\Enums\EventStatus;
use App\Models\Event;

it('AC-DEPLOY-1: allows a deploy when no event is at its door', function (): void {
    Event::factory()->create(['starts_at' => now()->addWeek()]);

    $this->artisan('events:door-guard')->assertSuccessful();
});

it('AC-DEPLOY-2: blocks a deploy while an event is in its door window', function (): void {
    Event::factory()->create(['title' => 'Feria', 'starts_at' => now()->addMinutes(30)]);

    // Non-zero exit is what makes scripts/deploy.sh abort before `artisan down`.
    $this->artisan('events:door-guard')
        ->expectsOutputToContain('DEPLOY BLOCKED')
        ->assertFailed();
});

it('AC-DEPLOY-2: keeps blocking while the event is running, not only before it starts', function (): void {
    Event::factory()->create(['starts_at' => now()->subHour()]);

    $this->artisan('events:door-guard')->assertFailed();
});

it('AC-DEPLOY-3: ignores events that are not published — a draft strands nobody', function (): void {
    Event::factory()->draft()->create(['starts_at' => now()->addMinutes(10)]);
    Event::factory()->create(['status' => EventStatus::Cancelled, 'starts_at' => now()->addMinutes(10)]);

    $this->artisan('events:door-guard')->assertSuccessful();
});

it('AC-DEPLOY-4: reports machine-readably so a pipeline can act on it', function (): void {
    Event::factory()->create(['title' => 'Feria', 'slug' => 'feria', 'starts_at' => now()->addMinutes(15)]);

    $this->artisan('events:door-guard --json')->assertFailed();
});

it('AC-DEPLOY-5: the deploy script consults the guard before taking the app down', function (): void {
    $script = file_get_contents(base_path('scripts/deploy.sh'));

    // Match the invocations, not the header comment that explains them.
    $guardAt = strpos((string) $script, 'php artisan events:door-guard');
    $downAt = strpos((string) $script, 'php artisan down');

    // Order matters: checking after `down` would already have stranded people.
    expect($guardAt)->not->toBeFalse()
        ->and($downAt)->not->toBeFalse()
        ->and($guardAt)->toBeLessThan($downAt);
});

it('AC-DEPLOY-6: a failed deploy brings the app back up instead of leaving it dark', function (): void {
    $script = (string) file_get_contents(base_path('scripts/deploy.sh'));

    // `set -e` plus `artisan down` is a trap: any failing step between them and
    // `artisan up` leaves the site in maintenance mode for as long as nobody
    // notices. For this app that is the worst possible end state.
    expect($script)->toContain('trap cleanup EXIT')
        ->and($script)->toContain('php artisan up');

    $trapAt = strpos($script, 'trap cleanup EXIT');
    $downAt = strpos($script, 'php artisan down');

    expect($trapAt)->toBeLessThan($downAt); // armed before the app goes dark
});
