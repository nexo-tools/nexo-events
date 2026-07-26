<?php

use App\Models\Event;

/**
 * In an events app the timezone is not cosmetic: `starts_at` is compared against
 * `now()` by the door guard, and an app pinned to UTC while its organizers think
 * in local time shifts every one of those comparisons by the offset. The event
 * page would still *display* the time the organizer typed, so the mistake is
 * invisible until a deploy freeze opens hours late.
 *
 * This shipped hardcoded to 'UTC', ignoring APP_TIMEZONE, while the sibling
 * tools read the env var. A static check because the value cannot be exercised
 * from inside a test run that pins its own environment.
 */
it('AC-TZ-1: the app timezone is env-driven, never hardcoded', function (): void {
    $config = (string) file_get_contents(config_path('app.php'));

    expect($config)->toMatch("/'timezone'\s*=>\s*env\('APP_TIMEZONE'/")
        ->and($config)->not->toMatch("/'timezone'\s*=>\s*'UTC'/");
});

it('AC-TZ-2: event times and the door guard agree on one clock', function (): void {
    // Both sides of every door-window comparison must read the same timezone;
    // a mismatch here is what silently shifts the deploy freeze.
    $event = Event::factory()->create(['starts_at' => now()->addMinutes(30)]);

    expect($event->starts_at->timezone->getName())->toBe(now()->timezone->getName());
    $this->artisan('events:door-guard')->assertFailed(); // it IS inside the window
});
