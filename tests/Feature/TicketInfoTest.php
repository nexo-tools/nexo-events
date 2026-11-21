<?php

use App\Enums\TicketStatus;
use App\Mail\EventReminder;
use App\Mail\TicketIssued;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EventRegistrar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket-holder info and payment text — SPEC docs/specs/SPEC-ticket-info.md, ADR-012.
 *
 * Every addition to e/* and t/* here has a presence test and an absence test:
 * those are the attendee surfaces, and the absence half is the one that keeps
 * an address from leaking onto a public page.
 */
const TI_ADDRESS = 'Calle Falsa 123, timbre B';
const TI_PRICE = 'Entry $8.000 · pay at the door';
const TI_STREET = 'Av. Avellaneda 3456 (timbre 2), Floresta';

/** @return array{event: Event, token: string, ticket: Ticket} */
function tiTicketFor(array $attributes = []): array
{
    $event = Event::factory()->create(array_merge(['venue' => 'La Sala · Floresta'], $attributes));
    $issued = app(EventRegistrar::class)->register($event, 'Ana Pérez', 'ana@example.com');

    return ['event' => $event, 'token' => $issued['token'], 'ticket' => $issued['ticket']];
}

/** The JSON-LD the event page embeds, decoded. */
function tiJsonLd(string $html): array
{
    // The page carries more than one JSON-LD block (the site's own, then the
    // event's): pick the Event.
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    foreach ($m[1] as $json) {
        $data = json_decode($json, true);
        if (is_array($data) && ($data['@type'] ?? null) === 'Event') {
            return $data;
        }
    }

    return [];
}

function tiMetaDescription(string $html): string
{
    preg_match('#<meta name="description" content="([^"]*)"#', $html, $m);

    return html_entity_decode($m[1] ?? '');
}

it('AC-TICKET-1: the ticket page shows where and the ticket-holder info', function (): void {
    ['token' => $token] = tiTicketFor(['access_info' => TI_ADDRESS]);

    $html = (string) $this->get(route('ticket.show', ['token' => $token]))->assertOk()->getContent();

    expect($html)->toContain('La Sala · Floresta')
        ->and($html)->toContain(TI_ADDRESS)
        ->and($html)->toContain(__('Only for ticket holders'));
});

it('AC-TICKET-1, AC-TICKET-17: the public page, its meta description, its JSON-LD and the sitemap never carry the ticket-holder info or the private address', function (): void {
    ['event' => $event] = tiTicketFor(['access_info' => TI_ADDRESS, 'access_address' => TI_STREET, 'description' => 'Una noche de rock']);

    $html = (string) $this->get(route('public.event', $event))->assertOk()->getContent();
    $sitemap = (string) $this->get(route('sitemap'))->getContent();

    foreach ([TI_ADDRESS, TI_STREET, 'Av. Avellaneda', rawurlencode(TI_STREET)] as $secret) {
        expect(str_contains($html, $secret))->toBeFalse("The public page carries {$secret}.")
            ->and(str_contains(tiMetaDescription($html), $secret))->toBeFalse("The meta description carries {$secret}.")
            ->and(str_contains(json_encode(tiJsonLd($html), JSON_UNESCAPED_UNICODE), $secret))->toBeFalse("The JSON-LD carries {$secret}.")
            ->and(str_contains($sitemap, $secret))->toBeFalse("The sitemap carries {$secret}.");
    }

    expect($html)->not->toContain(__('Only for ticket holders'))
        ->and($html)->not->toContain('data-access-address')
        ->and($html)->not->toContain('google.com/maps')
        ->and($html)->not->toContain('maps.apple.com');
});

it('AC-TICKET-2: the ticket email and the reminder carry both rows, in es, en and pt', function (): void {
    ['ticket' => $ticket, 'token' => $token] = tiTicketFor(['access_info' => TI_ADDRESS, 'payment_info' => TI_PRICE]);

    $labels = [
        'es' => ['Solo para quien tiene entrada', 'Entrada'],
        'en' => ['Only for ticket holders', 'Entry'],
        'pt' => ['Só para quem tem ingresso', 'Entrada'],
    ];

    foreach ($labels as $locale => [$accessLabel, $entryLabel]) {
        foreach ([new TicketIssued($ticket, $token), new EventReminder($ticket)] as $mail) {
            $html = $mail->locale($locale)->render();

            expect($html)->toContain(TI_ADDRESS)
                ->and($html)->toContain(e(TI_PRICE))
                ->and($html)->toContain($accessLabel)
                ->and($html)->toContain($entryLabel);
        }
    }
});

it('AC-TICKET-3: the price text is on the public page before the form, and the JSON-LD stops claiming a price', function (): void {
    ['event' => $event] = tiTicketFor(['payment_info' => TI_PRICE]);

    $html = (string) $this->get(route('public.event', $event))->assertOk()->getContent();

    $pricePosition = strpos($html, e(TI_PRICE));
    $formPosition = strpos($html, route('public.register', $event));

    expect($pricePosition)->not->toBeFalse()
        ->and($formPosition)->not->toBeFalse()
        ->and($pricePosition < $formPosition)->toBeTrue('payment_info must come before the registration form.');

    $offers = tiJsonLd($html)['offers'];

    expect(array_key_exists('price', $offers))->toBeFalse()
        ->and(array_key_exists('priceCurrency', $offers))->toBeFalse()
        ->and($offers['availability'])->toBe('https://schema.org/InStock')
        ->and($offers['url'])->toBe(route('public.event', $event));
});

it('AC-TICKET-3: without the price text the JSON-LD offer is exactly the v1 one', function (): void {
    ['event' => $event] = tiTicketFor();

    $html = (string) $this->get(route('public.event', $event))->assertOk()->getContent();

    // Key order included: toBe on arrays is identity, so this is the v1 bytes.
    expect(tiJsonLd($html)['offers'])->toBe([
        '@type' => 'Offer',
        'price' => '0',
        'priceCurrency' => 'ARS',
        'availability' => 'https://schema.org/InStock',
        'url' => route('public.event', $event),
    ]);
});

it('AC-TICKET-4: the door scanner shows the price text when there is one, and nothing when not', function (): void {
    $organizer = User::factory()->create();
    $paid = Event::factory()->for($organizer, 'organizer')->create(['payment_info' => TI_PRICE]);
    $free = Event::factory()->for($organizer, 'organizer')->create();

    $withNote = (string) $this->actingAs($organizer)->get(route('events.scan', $paid))->assertOk()->getContent();
    $without = (string) $this->actingAs($organizer)->get(route('events.scan', $free))->assertOk()->getContent();

    expect($withNote)->toContain(e(TI_PRICE))->and($withNote)->toContain('data-payment-info')
        ->and($without)->not->toContain('data-payment-info');
});

it('AC-TICKET-5: with both fields empty no new block or row appears anywhere', function (): void {
    ['event' => $event, 'ticket' => $ticket, 'token' => $token] = tiTicketFor();

    $ticketPage = (string) $this->get(route('ticket.show', ['token' => $token]))->getContent();
    $eventPage = (string) $this->get(route('public.event', $event))->getContent();
    $mails = (new TicketIssued($ticket, $token))->locale('en')->render()
        .(new EventReminder($ticket))->locale('en')->render();

    foreach ([$ticketPage, $eventPage, $mails] as $html) {
        expect($html)->not->toContain('data-access-info')
            ->and($html)->not->toContain('data-access-address')
            ->and($html)->not->toContain('data-maps')
            ->and($html)->not->toContain('data-payment-info')
            ->and($html)->not->toContain('Only for ticket holders')
            ->and($html)->not->toContain('>Entry<');
    }

    // The mail panel keeps exactly its v1 rows.
    expect(substr_count($mails, 'text-transform:uppercase; letter-spacing:0.04em;'))->toBeGreaterThan(0);
});

it('AC-TICKET-6: the schema change is two nullable columns and the rules are the same on both surfaces', function (): void {
    expect(Schema::hasColumns('events', ['access_info', 'payment_info']))->toBeTrue();

    // Additive: an event created without them stores NULL, not ''.
    $event = Event::factory()->create();
    expect(DB::table('events')->where('id', $event->id)->value('access_info'))->toBeNull();

    $organizer = User::factory()->create();

    $this->actingAs($organizer)->post(route('events.store'), [
        'title' => 'Demasiado largo',
        'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
        'access_info' => str_repeat('a', 1001),
        'payment_info' => str_repeat('b', 301),
    ])->assertSessionHasErrors(['access_info', 'payment_info']);

    config(['nexo.api.enabled' => true]);
    require_once __DIR__.'/Api/ApiTestSupport.php';

    $this->postJson('/api/v1/events', [
        'title' => 'Demasiado largo',
        'starts_at' => now()->addWeek()->toIso8601String(),
        'access_info' => str_repeat('a', 1001),
        'payment_info' => str_repeat('b', 301),
    ], bearer(apiToken($organizer)))->assertUnprocessable()->assertJsonValidationErrors(['access_info', 'payment_info']);

    $this->actingAs($organizer)->post(route('events.store'), [
        'title' => 'En el límite',
        'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
        'access_info' => str_repeat('a', 1000),
        'payment_info' => str_repeat('b', 300),
    ])->assertSessionHasNoErrors();

    expect(Event::where('title', 'En el límite')->value('payment_info'))->toBe(str_repeat('b', 300));
});

it('AC-TICKET-7: every new string is translated, not left in English', function (): void {
    $strings = [
        'Only for ticket holders',
        'Only for ticket holders (optional)',
        'Price and how to pay (optional)',
        'Entry',
    ];

    foreach (['es', 'pt'] as $locale) {
        $map = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

        foreach ($strings as $string) {
            expect(isset($map[$string]) && $map[$string] !== '')->toBeTrue("[{$locale}] {$string} is not translated.");
        }
    }

    // The form renders them, in the app shell, without breaking the a11y wiring.
    $html = (string) $this->actingAs(User::factory()->create())->get(route('events.create'))->assertOk()->getContent();

    expect($html)->toContain('aria-describedby="access_info-hint"')
        ->and($html)->toContain('aria-describedby="payment_info-hint"');
});

it('AC-TICKET-8: a released ticket says so on its page and no longer shows a QR', function (): void {
    ['event' => $event, 'ticket' => $ticket, 'token' => $token] = tiTicketFor(['access_info' => TI_ADDRESS, 'payment_info' => TI_PRICE]);
    $ticket->update(['status' => TicketStatus::Revoked]);

    $html = (string) $this->get(route('ticket.show', ['token' => $token]))->assertOk()->getContent();
    $key = 'The organizer released your spot: this ticket is no longer valid at the door. If you think this is a mistake, talk to whoever is organizing the event.';
    $notice = __($key);

    expect($html)->toContain('data-ticket-released')
        ->and($html)->toContain('role="alert"')
        ->and($html)->toContain(e($notice))
        ->and($html)->not->toContain('data-ticket-qr')
        ->and($html)->not->toContain(e(__('Show this QR at the door.')))
        // Everything else a ticket holder reads stays.
        ->and($html)->toContain(e($event->title))
        ->and($html)->toContain('Ana Pérez')
        ->and($html)->toContain('La Sala · Floresta')
        ->and($html)->toContain(TI_ADDRESS)
        ->and($html)->toContain(e(TI_PRICE));

    foreach (['es', 'pt'] as $locale) {
        $map = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);
        expect(isset($map[$key]) && $map[$key] !== $key)->toBeTrue("[{$locale}] the released-ticket notice is not translated.");
    }
});

it('AC-TICKET-8: a valid and a checked-in ticket keep their QR and show no released notice', function (): void {
    ['ticket' => $valid, 'token' => $validToken] = tiTicketFor();
    $checkedIn = tiTicketFor();
    $checkedIn['ticket']->update(['status' => TicketStatus::CheckedIn]);

    $validHtml = (string) $this->get(route('ticket.show', ['token' => $validToken]))->assertOk()->getContent();
    $checkedInHtml = (string) $this->get(route('ticket.show', ['token' => $checkedIn['token']]))->assertOk()->getContent();

    expect($valid->fresh()->status)->toBe(TicketStatus::Valid);

    foreach ([$validHtml, $checkedInHtml] as $html) {
        expect($html)->toContain('data-ticket-qr')
            ->and($html)->toContain(e(__('Show this QR at the door.')))
            ->and($html)->not->toContain('data-ticket-released');
    }

    // The checked-in ticket keeps its own flash; the valid one has none.
    expect($checkedInHtml)->toContain(e(__('You have already checked in to this event.')))
        ->and($validHtml)->not->toContain(e(__('You have already checked in to this event.')));
});
