<?php

use App\Models\Event;
use App\Models\User;

/**
 * Baseline a11y from the Nexo standard. Not a substitute for a real audit —
 * these are the mechanical parts that regress silently when someone edits a
 * layout, which is exactly what a guardian is for.
 */
it('AC-A11Y-1: every page declares the right lang, a skip link and a main landmark', function (): void {
    $event = Event::factory()->create();

    foreach ([route('home'), route('help'), route('legal.privacy'), route('public.event', $event)] as $url) {
        $html = (string) $this->get($url)->assertOk()->getContent();

        expect($html)->toContain('<html lang="es"')
            ->and($html)->toContain('id="contenido"')   // the skip-link target
            ->and($html)->toContain('<main');
    }
});

it('AC-A11Y-1: the lang attribute follows the chosen locale, not just the default', function (): void {
    expect((string) $this->get(route('home').'?lang=pt')->getContent())->toContain('<html lang="pt"');
});

it('AC-A11Y-2: icon-only controls carry an accessible name', function (): void {
    $html = (string) $this->get(route('home'))->getContent();

    // The chrome's icon buttons (theme toggle, menus) are unusable with a
    // screen reader if they are just an <svg> in a <button>.
    preg_match_all('/<button\b[^>]*>(.*?)<\/button>/s', $html, $matches, PREG_SET_ORDER);

    foreach ($matches as [$button, $inner]) {
        $hasVisibleText = trim(strip_tags($inner)) !== '';
        $hasLabel = str_contains($button, 'aria-label') || str_contains($inner, 'sr-only');

        expect($hasVisibleText || $hasLabel)->toBeTrue();
    }
});

it('AC-A11Y-3: form fields are associated with a label', function (): void {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create();

    // The door scanner: used under pressure, on a phone, often one-handed.
    $html = (string) $this->actingAs($organizer)->get(route('events.scan', $event))->getContent();

    preg_match_all('/<input\b[^>]*name="([^"]+)"[^>]*>/', $html, $inputs, PREG_SET_ORDER);

    foreach ($inputs as [$tag, $name]) {
        if (in_array($name, ['_token', 'website'], true)) {
            continue; // hidden CSRF field and the honeypot are not user-facing
        }
        preg_match('/id="([^"]+)"/', $tag, $id);

        expect($id[1] ?? null)->not->toBeNull()
            ->and($html)->toContain('for="'.($id[1] ?? '').'"');
    }
});

it('AC-A11Y-4: live result regions announce themselves', function (): void {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create();

    $html = (string) $this->actingAs($organizer)->get(route('events.scan', $event))->getContent();

    // A door result that only changes colour is invisible to a screen reader.
    expect($html)->toContain('aria-live="polite"')
        ->and($html)->toContain('role="status"');
});
