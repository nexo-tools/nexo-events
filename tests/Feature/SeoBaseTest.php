<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Services\EventRegistrar;

it('AC-SEO-1: every public page emits title, description, canonical and OpenGraph exactly once', function (): void {
    $event = Event::factory()->create();

    $pages = [
        route('home'),
        route('help'),
        route('legal.privacy'),
        route('legal.terms'),
        route('public.event', $event),
    ];

    foreach ($pages as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        expect($html)->toMatch('/<title>.+<\/title>/')
            ->and($html)->toContain('<link rel="canonical"')
            ->and($html)->toContain('property="og:title"')
            ->and($html)->toContain('property="og:image"')
            ->and($html)->toContain('name="twitter:card"');

        // Exactly one description: the drift this component exists to kill was
        // a page emitting its own on top of the shared one.
        expect(substr_count((string) $html, 'name="description"'))->toBe(1, "duplicate description on {$url}");
        expect(substr_count((string) $html, '<title>'))->toBe(1, "duplicate title on {$url}");
    }
});

it('AC-SEO-2: the public event page carries its OWN title and description, not the generic ones', function (): void {
    $event = Event::factory()->create(['title' => 'Feria de Editoriales', 'venue' => 'CCK']);

    $html = $this->get(route('public.event', $event))->getContent();

    expect($html)->toContain('Feria de Editoriales')
        ->and($html)->toContain('CCK')
        // og:title must be the event, or every shared link looks identical.
        ->and($html)->toMatch('/property="og:title" content="Feria de Editoriales/');
});

it('AC-SEO-3: emits schema.org Event structured data, and tells the truth when cancelled', function (): void {
    $event = Event::factory()->create();

    $html = $this->get(route('public.event', $event))->getContent();
    expect($html)->toContain('"@type":"Event"')
        ->and($html)->toContain('https://schema.org/EventScheduled');

    $event->update(['status' => EventStatus::Cancelled]);
    $cancelled = $this->get(route('public.event', $event))->getContent();

    // A cancelled event still has a page; it must not keep claiming it is on.
    expect($cancelled)->toContain('https://schema.org/EventCancelled')
        ->and($cancelled)->not->toContain('https://schema.org/EventScheduled');
});

it('AC-SEO-4: hreflang covers es/en/pt plus x-default on public pages', function (): void {
    $html = $this->get(route('home'))->getContent();

    foreach (['es', 'en', 'pt', 'x-default'] as $code) {
        expect($html)->toContain('hreflang="'.$code.'"');
    }
});

it('AC-SEO-5: private and per-person pages are noindex and carry no structured data', function (): void {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create();
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');

    $ticket = $this->get(route('ticket.show', ['token' => $issued['token']]))->getContent();
    $dashboard = $this->actingAs($organizer)->get(route('dashboard'))->getContent();
    $scanner = $this->actingAs($organizer)->get(route('events.scan', $event))->getContent();

    // NB: Pest's toContain() takes multiple NEEDLES, not a failure message —
    // passing one silently asserts the message text is in the HTML too.
    foreach ([$ticket, $dashboard, $scanner] as $html) {
        expect($html)->toContain('name="robots" content="noindex')
            ->and($html)->not->toContain('application/ld+json');
    }
});

it('AC-SEO-6: robots.txt disallows the private paths and points at the sitemap', function (): void {
    $response = $this->get('/robots.txt');

    $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    $body = $response->getContent();

    // /t/ above all: a ticket URL is a bearer credential, and an indexed one is
    // a ticket anyone can walk in with.
    foreach (['Disallow: /t/', 'Disallow: /app', 'Disallow: /login'] as $rule) {
        expect($body)->toContain($rule);
    }
    expect($body)->toContain('Sitemap: '.route('sitemap'));
});

it('AC-SEO-7: the sitemap lists published events only, with hreflang alternates', function (): void {
    $published = Event::factory()->create(['title' => 'Publico']);
    $draft = Event::factory()->draft()->create(['title' => 'Borrador']);
    $cancelled = Event::factory()->create(['title' => 'Cancelado', 'status' => EventStatus::Cancelled]);

    $response = $this->get('/sitemap.xml');
    $response->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $xml = (string) $response->getContent();

    expect($xml)->toContain(route('public.event', $published))
        ->and($xml)->not->toContain(route('public.event', $draft))
        ->and($xml)->not->toContain(route('public.event', $cancelled))
        ->and($xml)->toContain(route('legal.privacy'))
        ->and($xml)->toContain('hreflang="pt"');

    expect(simplexml_load_string($xml))->not->toBeFalse(); // valid XML
});

it('emits JSON-LD that actually parses', function () {
    // The block used to render compiled Blade internals instead of JSON: keys
    // like `@context` are Blade directives (Laravel 11 added `@context`), so the
    // template was compiling them away and shipping broken structured data on
    // every page. Asserting the tag exists is not enough — it has to parse.
    $html = $this->get('/')->assertOk()->getContent();

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    expect($matches[1])->not->toBeEmpty('No JSON-LD block was rendered.');

    foreach ($matches[1] as $block) {
        $decoded = json_decode($block, true);
        expect(json_last_error())->toBe(JSON_ERROR_NONE, 'JSON-LD is not valid JSON: '.substr($block, 0, 200));
        expect($decoded['@context'] ?? null)->toBe('https://schema.org');
        expect($decoded['@type'] ?? null)->not->toBeNull('JSON-LD has no @type.');
    }
});
