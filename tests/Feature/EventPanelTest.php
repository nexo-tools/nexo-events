<?php

use App\Models\Event;
use App\Models\EventCollaborator;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * The event's own screens (PLAN 10.18–10.23) — SPEC docs/specs/SPEC-event-panel.md.
 */
beforeEach(function (): void {
    Mail::fake();
});

/** An owner's event with one accepted collaborator. */
function panelFixture(array $attributes = []): array
{
    $event = Event::factory()->create($attributes);
    $band = User::factory()->create(['email' => 'banda@example.com']);
    EventCollaborator::factory()->for($event)->acceptedBy($band)->create();

    return [$event, $event->organizer, $band];
}

/** The hrefs inside the event navigation, in order. */
function eventNavLinks(string $html): array
{
    preg_match('#<nav[^>]*data-event-nav[^>]*>(.*?)</nav>#s', $html, $nav);
    preg_match_all('#href="([^"]+)"#', $nav[1] ?? '', $links);

    return $links[1];
}

/** The href the event navigation marks as the current page. */
function eventNavCurrent(string $html): ?string
{
    preg_match('#<nav[^>]*data-event-nav[^>]*>(.*?)</nav>#s', $html, $nav);
    preg_match('#href="([^"]+)"\s+aria-current="page"#', $nav[1] ?? '', $current);

    return $current[1] ?? null;
}

it('AC-PANEL-1: every event on the dashboard opens its summary, for the owner and for a collaborator', function (): void {
    [$event, $owner, $band] = panelFixture(['title' => 'Noche de rock']);
    $draft = Event::factory()->draft()->for($owner, 'organizer')->create();

    $forOwner = (string) $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
    $forBand = (string) $this->actingAs($band)->get(route('dashboard'))->assertOk()->getContent();

    expect($forOwner)->toContain('href="'.route('events.manage', $event).'"')
        ->and($forOwner)->toContain('href="'.route('events.manage', $draft).'"')
        ->and($forOwner)->not->toContain(route('events.edit', $event))
        ->and($forOwner)->not->toContain(route('events.edit', $draft))
        ->and($forBand)->toContain('href="'.route('events.manage', $event).'"')
        ->and($forBand)->not->toContain(route('events.edit', $event));
});

it('AC-PANEL-2: the four screens of an event share the event navigation, the current one marked', function (): void {
    [$event, $owner] = panelFixture();

    $screens = [
        route('events.manage', $event),
        route('events.registrations', $event),
        route('events.scan', $event),
        route('events.edit', $event),
    ];

    foreach ($screens as $url) {
        $html = (string) $this->actingAs($owner)->get($url)->assertOk()->getContent();

        expect(eventNavLinks($html))->toBe($screens)
            ->and(eventNavCurrent($html))->toBe($url)
            ->and($html)->toContain('data-back-to-dashboard')
            ->and($html)->toContain(route('dashboard'));
    }
});

it('AC-PANEL-2: a collaborator gets the navigation without Edit, and still cannot open it', function (): void {
    [$event, , $band] = panelFixture();

    $expected = [
        route('events.manage', $event),
        route('events.registrations', $event),
        route('events.scan', $event),
    ];

    foreach ($expected as $url) {
        $html = (string) $this->actingAs($band)->get($url)->assertOk()->getContent();

        expect(eventNavLinks($html))->toBe($expected)
            ->and(eventNavCurrent($html))->toBe($url);
    }

    $this->actingAs($band)->get(route('events.edit', $event))->assertForbidden();
});

it('AC-PANEL-2: the navigation speaks the three languages', function (): void {
    [$event, $owner] = panelFixture();

    $labels = [
        'es' => ['Resumen', 'Registrados', 'Puerta', 'Editar'],
        'en' => ['Summary', 'Registrations', 'Door', 'Edit'],
        'pt' => ['Resumo', 'Inscritos', 'Porta', 'Editar'],
    ];

    foreach ($labels as $lang => $words) {
        $html = (string) $this->actingAs($owner)->get(route('events.manage', $event).'?lang='.$lang)->getContent();
        preg_match('#<nav[^>]*data-event-nav[^>]*>(.*?)</nav>#s', $html, $nav);
        preg_match_all('#<a[^>]*>\s*([^<]+?)\s*</a>#', $nav[1], $texts);

        expect($texts[1])->toBe($words);
    }
});

it('AC-PANEL-3: the owner manages collaborators from the summary; the edit screen and a collaborator\'s summary have none', function (): void {
    [$event, $owner, $band] = panelFixture();

    $summary = (string) $this->actingAs($owner)->get(route('events.manage', $event))->getContent();
    $edit = (string) $this->actingAs($owner)->get(route('events.edit', $event))->getContent();
    $bandSummary = (string) $this->actingAs($band)->get(route('events.manage', $event))->getContent();

    expect($summary)->toContain('id="collaborators-heading"')
        ->and($summary)->toContain(route('collaborators.store', $event))
        ->and($summary)->toContain('data-collaborator="banda@example.com"')
        ->and($edit)->not->toContain('id="collaborators-heading"')
        ->and($edit)->not->toContain(route('collaborators.store', $event))
        ->and($bandSummary)->not->toContain('id="collaborators-heading"')
        ->and($bandSummary)->not->toContain('data-collaborator=');

    // Inviting from the summary brings the owner back to it.
    $this->actingAs($owner)->from(route('events.manage', $event))
        ->post(route('collaborators.store', $event), ['collaborator_email' => 'sonido@example.com'])
        ->assertRedirect(route('events.manage', $event));
});

it('AC-PANEL-3: a draft\'s summary tells the owner it is not public yet and hands out no public link', function (): void {
    $draft = Event::factory()->draft()->create();
    $published = Event::factory()->create();

    $forDraft = (string) $this->actingAs($draft->organizer)->get(route('events.manage', $draft))->getContent();
    $forPublished = (string) $this->actingAs($published->organizer)->get(route('events.manage', $published))->getContent();

    expect($forDraft)->toContain('data-draft-notice')
        ->and($forDraft)->toContain(route('events.edit', $draft))
        ->and($forDraft)->not->toContain('id="public-url"')
        ->and($forPublished)->not->toContain('data-draft-notice')
        ->and($forPublished)->toContain('id="public-url"');
});
