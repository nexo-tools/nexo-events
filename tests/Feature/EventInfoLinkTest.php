<?php

use App\Mail\EventReminder;
use App\Mail\TicketIssued;
use App\Models\Event;
use App\Models\User;
use App\Services\EventRegistrar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The optional "More information" link per event (PLAN 10.15) — SPEC
 * docs/specs/SPEC-ticket-info.md, AC-TICKET-19..23. Its API half is in
 * IntegrationApiTest (AC-API-25..27).
 *
 * Anyone can create events on an instance, so the three properties that keep
 * the link from being a phishing aid each have a test that fails without them:
 * https only, the domain printed next to the label, and rel nofollow ugc.
 */
const IL_URL = 'https://laherreriafloresta.com.ar/evento/noche-de-rock';

function ilEvent(array $attributes = []): Event
{
    return Event::factory()->create(array_merge(['description' => 'Una noche de rock'], $attributes));
}

/** @return array<string, string> */
function ilFormPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Noche de rock',
        'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
    ], $overrides);
}

/** The anchor of the "More information" button, or '' when there is none. */
function ilButton(string $html): string
{
    preg_match('#<p class="mb-6" data-info-url>\s*(<a\b.*?</a>)\s*</p>#s', $html, $m);

    return $m[1] ?? '';
}

function ilJsonLd(string $html): string
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    // Decoded and re-encoded with unescaped slashes, so "https:\/\/…" cannot
    // hide the URL from the assertion.
    return implode("\n", array_map(
        fn (string $json): string => (string) json_encode(json_decode($json, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $m[1],
    ));
}

it('AC-TICKET-19: the link is one nullable column, and the organizer form saves, changes and clears it', function (): void {
    expect(Schema::hasColumn('events', 'info_url'))->toBeTrue();
    expect(DB::table('events')->where('id', ilEvent()->id)->value('info_url'))->toBeNull();

    $organizer = User::factory()->create();

    // Trimmed on the way in.
    $this->actingAs($organizer)->post(route('events.store'), ilFormPayload(['info_url' => '  '.IL_URL.'  ']))
        ->assertSessionHasNoErrors();

    $event = Event::where('title', 'Noche de rock')->sole();
    expect($event->info_url)->toBe(IL_URL);

    $this->actingAs($organizer)->put(route('events.update', $event), ilFormPayload(['info_url' => 'https://example.com/otra']))
        ->assertSessionHasNoErrors();
    expect($event->fresh()->info_url)->toBe('https://example.com/otra');

    // Emptying the input clears it: empty string becomes null.
    $this->actingAs($organizer)->put(route('events.update', $event), ilFormPayload(['info_url' => '']))
        ->assertSessionHasNoErrors();
    expect($event->fresh()->info_url)->toBeNull();

    // Without the field at all (an event made before 10.15), nothing is required.
    $this->actingAs($organizer)->post(route('events.store'), ilFormPayload(['title' => 'Sin link']))
        ->assertSessionHasNoErrors();
    expect(Event::where('title', 'Sin link')->sole()->info_url)->toBeNull();
});

it('AC-TICKET-19: the form asks for the link after the description, labelled and described, in es, en and pt', function (): void {
    $organizer = User::factory()->create();
    $html = (string) $this->actingAs($organizer)->withUnencryptedCookie('nexo-lang', 'es')
        ->get(route('events.create'))->assertOk()->getContent();

    expect($html)->toContain('<label for="info_url"')
        ->and($html)->toContain('type="url"')
        ->and($html)->toContain('maxlength="2048"')
        ->and($html)->toContain('aria-describedby="info_url-hint"')
        ->and($html)->toContain('Link para ver más (opcional)')
        ->and($html)->toContain(e('Un link a tu sitio con más información del evento. Tiene que empezar con https://. Se muestra como un botón «Más información».'))
        ->and(strpos($html, 'name="description"') < strpos($html, 'name="info_url"'))->toBeTrue('The link goes after the description.');

    $edit = (string) $this->actingAs($organizer)
        ->get(route('events.edit', ilEvent(['organizer_id' => $organizer->id, 'info_url' => IL_URL])))->assertOk()->getContent();
    expect($edit)->toContain('value="'.e(IL_URL).'"');

    $labels = [
        'en' => ['Link for more information (optional)', 'More information'],
        'pt' => ['Link para saber mais (opcional)', 'Mais informações'],
        'es' => ['Link para ver más (opcional)', 'Más información'],
    ];

    foreach ($labels as $locale => [$label, $button]) {
        app()->setLocale($locale);
        expect(__('Link for more information (optional)'))->toBe($label)
            ->and(__('More information'))->toBe($button);
    }
});

it('AC-TICKET-20: the web form refuses anything but an absolute https URL of at most 2048 characters', function (string $bad): void {
    $this->actingAs(User::factory()->create())
        ->post(route('events.store'), ilFormPayload(['info_url' => $bad]))
        ->assertSessionHasErrors(['info_url']);

    expect(Event::count())->toBe(0);
})->with([
    'http' => 'http://laherreriafloresta.com.ar/evento/x',
    'javascript' => 'javascript:alert(1)',
    'javascript disguised' => 'javascript://example.com/%0Aalert(1)',
    'data' => 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
    'mailto' => 'mailto:hola@example.com',
    'relative' => '/evento/noche-de-rock',
    'protocol-relative' => '//example.com/x',
    'no scheme' => 'laherreriafloresta.com.ar/evento/x',
    'ftp' => 'ftp://example.com/x',
    'quote breaking out' => 'https://example.com/"><script>alert(1)</script>',
    'longer than 2048' => 'https://example.com/'.str_repeat('a', 2049 - strlen('https://example.com/')),
]);

it('AC-TICKET-20: 2048 characters is accepted, and the error message says https', function (): void {
    $organizer = User::factory()->create();
    $limit = 'https://example.com/'.str_repeat('a', 2048 - strlen('https://example.com/'));

    $this->actingAs($organizer)->post(route('events.store'), ilFormPayload(['info_url' => $limit]))
        ->assertSessionHasNoErrors();
    expect(Event::sole()->info_url)->toBe($limit);

    $this->actingAs($organizer)->withUnencryptedCookie('nexo-lang', 'es')
        ->post(route('events.store'), ilFormPayload(['info_url' => 'http://example.com']))
        ->assertSessionHasErrors(['info_url' => 'El link tiene que ser una dirección web completa que empiece con https://.']);
});

it('AC-TICKET-21: the public page shows the button with its domain, after the description and before the form', function (): void {
    $event = ilEvent(['info_url' => IL_URL]);

    $html = (string) $this->withUnencryptedCookie('nexo-lang', 'es')
        ->get(route('public.event', $event))->assertOk()->getContent();
    $button = ilButton($html);

    expect($button)->not->toBe('')
        ->and($button)->toContain('href="'.e(IL_URL).'"')
        ->and($button)->toContain('target="_blank"')
        ->and($button)->toContain('rel="noopener noreferrer nofollow ugc"')
        ->and($button)->toContain('nexo-btn')
        ->and($button)->toContain('Más información')
        ->and($button)->toContain('(se abre en una pestaña nueva)')
        ->and(preg_match('#<span[^>]*data-info-url-host>laherreriafloresta\.com\.ar <span aria-hidden="true">↗</span></span>#', $button))->toBe(1)
        ->and(preg_match('/\son[a-z]+=/i', $button))->toBe(0);

    $description = strpos($html, 'Una noche de rock');
    $link = strpos($html, 'data-info-url');
    $form = strpos($html, 'action="'.route('public.register', $event).'"');

    expect($description < $link && $link < $form)->toBeTrue('Description, then the link, then the registration form.');
});

it('AC-TICKET-21: the domain is lower-case, without www., and a non-ASCII host shows as punycode', function (string $url, string $host): void {
    $html = (string) $this->get(route('public.event', ilEvent(['info_url' => $url])))->assertOk()->getContent();

    expect(preg_match('#data-info-url-host>'.preg_quote($host, '#').' <span#', ilButton($html)))->toBe(1);
})->with([
    'www and capitals' => ['https://WWW.LaHerreriaFloresta.com.ar/Evento/X', 'laherreriafloresta.com.ar'],
    'port' => ['https://www.example.com:8443/x', 'example.com'],
    'credentials before the real host' => ['https://laherreriafloresta.com.ar@evil.example/x', 'evil.example'],
    'subdomain kept' => ['https://entradas.example.com/x', 'entradas.example.com'],
    'cyrillic look-alike' => ['https://аpple.com/x', 'xn--pple-43d.com'],
]);

it('AC-TICKET-21: without a link, or with a stored value that is not https, nothing renders', function (?string $stored): void {
    $html = (string) $this->get(route('public.event', ilEvent(['info_url' => $stored])))->assertOk()->getContent();

    expect($html)->not->toContain('data-info-url')
        ->and($html)->not->toContain(__('More information'))
        ->and($html)->not->toContain('nofollow ugc');
})->with([
    'null' => [null],
    'http stored by some other path' => ['http://example.com/x'],
    'javascript stored by some other path' => ['javascript:alert(1)'],
    'no host' => ['https://'],
]);

it('AC-TICKET-21: the button is there whatever the registration state, and gone with the event when it is killed', function (): void {
    $closed = ilEvent(['info_url' => IL_URL, 'status' => 'closed']);
    expect(ilButton((string) $this->get(route('public.event', $closed))->assertOk()->getContent()))->not->toBe('');

    $killed = ilEvent(['info_url' => IL_URL, 'status' => 'killed']);
    $this->get(route('public.event', $killed))->assertNotFound();
});

it('AC-TICKET-22: a stored URL with quotes or markup never breaks out of the href', function (): void {
    // Not reachable through EventRequest (AC-TICKET-20 refuses it); this is
    // the output half: whatever is in the column is escaped.
    $hostile = 'https://example.com/x"><script>alert(1)</script><a href="';
    $single = "https://example.com/it's'onmouseover='alert(1)";

    $html = (string) $this->get(route('public.event', ilEvent(['info_url' => $hostile])))->assertOk()->getContent();
    $button = ilButton($html);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($button)->toContain('href="'.e($hostile).'"')
        ->and($button)->toContain('&quot;&gt;&lt;script&gt;')
        ->and(substr_count($button, '<a '))->toBe(1);

    // A single quote IS a valid URL character, so the validator lets it in.
    $this->actingAs(User::factory()->create())
        ->post(route('events.store'), ilFormPayload(['title' => 'Comillas', 'info_url' => $single]))
        ->assertSessionHasNoErrors();

    // The form creates a draft; publish it to see the public page.
    $stored = Event::where('title', 'Comillas')->sole();
    $stored->update(['status' => 'published']);

    $button = ilButton((string) $this->get(route('public.event', $stored))->assertOk()->getContent());

    expect($button)->toContain('href="'.e($single).'"')
        ->and($button)->toContain('&#039;onmouseover=&#039;')
        ->and(preg_match('/\son[a-z]+=/i', $button))->toBe(0);
});

it('AC-TICKET-23: the link is only on the public page — not in the meta, the JSON-LD, the sitemap, the ticket or its emails', function (): void {
    $event = ilEvent(['info_url' => IL_URL]);
    $issued = app(EventRegistrar::class)->register($event, 'Ana Pérez', 'ana@example.com');

    $public = (string) $this->get(route('public.event', $event))->assertOk()->getContent();
    preg_match('#<meta name="description" content="([^"]*)"#', $public, $meta);

    // Exactly once on the public page: the button's href.
    expect(substr_count($public, IL_URL))->toBe(1)
        ->and(html_entity_decode($meta[1] ?? ''))->not->toContain('laherreriafloresta')
        ->and(ilJsonLd($public))->not->toContain('laherreriafloresta')
        ->and((string) $this->get(route('sitemap'))->getContent())->not->toContain('laherreriafloresta');

    $ticket = (string) $this->get(route('ticket.show', ['token' => $issued['token']]))->assertOk()->getContent();
    expect($ticket)->not->toContain('laherreriafloresta')
        ->and($ticket)->not->toContain('data-info-url');

    foreach (['es', 'en', 'pt'] as $locale) {
        foreach ([new TicketIssued($issued['ticket'], $issued['token']), new EventReminder($issued['ticket'])] as $mail) {
            expect($mail->locale($locale)->render())->not->toContain('laherreriafloresta');
        }
    }
});
