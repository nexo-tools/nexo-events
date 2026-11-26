<?php

use App\Models\User;

/**
 * Two smaller findings of the 2026-11-24 usability review that are this
 * tool's own, not the shared chrome's (PLAN 10.24).
 */
it('H-nexoevents-12: signed in, the landing hero leads into the panel instead of offering to sign in', function (): void {
    $hero = function (string $html): string {
        preg_match('#<div[^>]*data-hero-actions[^>]*>(.*?)</div>#s', $html, $match);

        return $match[1] ?? '';
    };

    $signedIn = $hero((string) $this->actingAs(User::factory()->create())->get('/')->getContent());
    expect($signedIn)->toContain('href="'.route('dashboard').'"')
        ->and($signedIn)->toContain('href="'.route('events.create').'"')
        ->and($signedIn)->not->toContain(route('login'))
        ->and($signedIn)->not->toContain(route('register'));

    auth()->logout();
    $guest = $hero((string) $this->get('/')->getContent());
    expect($guest)->toContain(route('login'))->and($guest)->not->toContain(route('dashboard'));
});

it('H-nexoevents-17: native controls follow the stamped theme (the date picker icon on a dark form)', function (): void {
    // Since nexo-ui v2.1 the rule ships in the shared nexo-ui.css (identical in
    // the 6 apps), which app.css imports; the app's own patch is gone so the
    // two can never disagree.
    $ui = (string) file_get_contents(resource_path('css/nexo-ui.css'));
    $app = (string) file_get_contents(resource_path('css/app.css'));

    expect($ui)->toMatch('#:root\\[data-theme="dark"\\]\\s*\\{\\s*color-scheme:\\s*dark;#')
        ->and($ui)->toMatch('#:root\\[data-theme="light"\\]\\s*\\{\\s*color-scheme:\\s*light;#')
        ->and($app)->toContain("@import './nexo-ui.css';")
        ->and(preg_match('#color-scheme:\\s*(dark|light)#', $app))->toBe(0);
});
