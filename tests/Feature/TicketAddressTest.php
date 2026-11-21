<?php

use App\Enums\TicketStatus;
use App\Mail\EventReminder;
use App\Mail\TicketIssued;
use App\Models\Event;
use App\Models\EventCollaborator;
use App\Models\User;
use App\Services\EventRegistrar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The private venue address with copy and map buttons (PLAN 10.14) — SPEC
 * docs/specs/SPEC-ticket-info.md. Its never-public half is asserted together
 * with access_info in TicketInfoTest (AC-TICKET-17).
 */
const TA_ADDRESS = 'Calle Ñandú 742 & Pje. 3 (timbre B), CABA';

/** @return array{event: Event, token: string} */
function taTicketFor(array $attributes = []): array
{
    $event = Event::factory()->create(array_merge(['venue' => 'La Sala · Floresta'], $attributes));
    $issued = app(EventRegistrar::class)->register($event, 'Ana Pérez', 'ana@example.com');

    return ['event' => $event, 'token' => $issued['token'], 'ticket' => $issued['ticket']];
}

function taGoogle(): string
{
    return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(TA_ADDRESS);
}

function taApple(): string
{
    return 'https://maps.apple.com/?q='.rawurlencode(TA_ADDRESS);
}

it('AC-TICKET-14: the address is one nullable column with the same 255 limit on the web form as on the API', function (): void {
    expect(Schema::hasColumn('events', 'access_address'))->toBeTrue();

    $event = Event::factory()->create();
    expect(DB::table('events')->where('id', $event->id)->value('access_address'))->toBeNull();

    $organizer = User::factory()->create();

    $this->actingAs($organizer)->post(route('events.store'), [
        'title' => 'Demasiado larga',
        'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
        'access_address' => str_repeat('a', 256),
    ])->assertSessionHasErrors(['access_address']);

    $this->actingAs($organizer)->post(route('events.store'), [
        'title' => 'En el límite',
        'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
        'access_address' => str_repeat('a', 255),
    ])->assertSessionHasNoErrors();

    $stored = Event::where('title', 'En el límite')->sole();
    expect($stored->access_address)->toBe(str_repeat('a', 255));

    $this->actingAs($organizer)->put(route('events.update', $stored), [
        'title' => 'En el límite',
        'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
        'access_address' => TA_ADDRESS,
    ])->assertSessionHasNoErrors();

    expect($stored->fresh()->access_address)->toBe(TA_ADDRESS);
});

it('AC-TICKET-14: the organizer form asks for the address before the ticket-holder text, labelled and described', function (): void {
    $html = (string) $this->actingAs(User::factory()->create())->get(route('events.create'))->assertOk()->getContent();

    expect($html)->toContain('<label for="access_address"')
        ->and($html)->toContain('aria-describedby="access_address-hint"')
        ->and($html)->toContain(e(__('Address (only for ticket holders, optional)')))
        ->and(strpos($html, 'name="access_address"') < strpos($html, 'name="access_info"'))->toBeTrue('The address goes before access_info.');

    $labels = [
        'es' => 'Dirección (solo para quien tiene entrada, opcional)',
        'en' => 'Address (only for ticket holders, optional)',
        'pt' => 'Endereço (só para quem tem ingresso, opcional)',
    ];

    foreach ($labels as $locale => $label) {
        app()->setLocale($locale);
        expect(__('Address (only for ticket holders, optional)'))->toBe($label);
    }
});

it('AC-TICKET-15: the ticket page shows the address with a copy button and both map links', function (): void {
    ['token' => $token] = taTicketFor(['access_address' => TA_ADDRESS]);

    $html = (string) $this->withUnencryptedCookie('nexo-lang', 'es')
        ->get(route('ticket.show', ['token' => $token]))->assertOk()->getContent();

    // The address itself, as text, in the element the button copies from.
    expect($html)->toContain('data-access-address')
        ->and($html)->toContain('<span id="access-address" class="break-words">'.e(TA_ADDRESS).'</span>');

    // The copy button: progressive enhancement through the bundle, no inline JS.
    preg_match('/<button\b[^>]*data-copy="access-address"[^>]*>([^<]*)<\/button>/', $html, $button);
    expect($button)->not->toBeEmpty()
        ->and(trim($button[1]))->toBe('Copiar dirección')
        ->and($button[0])->toContain('hidden')
        ->and($button[0])->toContain('type="button"')
        ->and($button[0])->toContain('data-copied="Copiada"')
        ->and($button[0])->toContain('nexo-btn')
        ->and(preg_match('/\son[a-z]+=/i', $button[0]))->toBe(0);

    // The two map links, encoded, in a new tab, with names that say so.
    foreach (['google' => [taGoogle(), 'Google Maps'], 'apple' => [taApple(), 'Apple Maps']] as $app => [$url, $name]) {
        preg_match('/<a\b[^>]*data-maps="'.$app.'"[^>]*>([^<]*)<\/a>/', $html, $link);

        expect($link)->not->toBeEmpty()
            ->and($link[0])->toContain('href="'.e($url).'"')
            ->and($link[0])->toContain('target="_blank"')
            ->and($link[0])->toContain('rel="noopener noreferrer"')
            ->and($link[0])->toContain('aria-label="'.e("Abrir la dirección en {$name} (pestaña nueva)").'"')
            ->and($link[0])->toContain('nexo-btn')
            ->and(trim($link[1]))->toBe($name);
    }

    // The query really is rawurlencode'd: no raw space, ampersand or Ñ inside it.
    expect(taGoogle())->toContain('query=Calle%20%C3%91and%C3%BA%20742%20%26%20Pje.%203%20%28timbre%20B%29%2C%20CABA');

    // The bundle carries the behaviour: reveal, copy, "Copied" for two seconds.
    $js = (string) file_get_contents(resource_path('js/app.js'));
    expect($js)->toContain("querySelectorAll('[data-copy]')")
        ->and($js)->toContain('navigator.clipboard.writeText')
        ->and($js)->toContain('button.dataset.copied')
        ->and($js)->toContain('2000');

    foreach (['en' => ['Copy address', 'Address copied'], 'pt' => ['Copiar endereço', 'Copiado']] as $locale => [$copy, $copied]) {
        app()->setLocale($locale);
        expect(__('Copy address'))->toBe($copy)->and(__('Address copied'))->toBe($copied);
    }
});

it('AC-TICKET-15: a released ticket keeps the address, as it keeps access_info', function (): void {
    ['token' => $token, 'ticket' => $ticket] = taTicketFor(['access_address' => TA_ADDRESS, 'access_info' => 'Timbre B']);
    $ticket->update(['status' => TicketStatus::Revoked]);

    $html = (string) $this->get(route('ticket.show', ['token' => $token]))->assertOk()->getContent();

    expect($html)->toContain('data-ticket-released')
        ->and($html)->toContain('data-access-info')
        ->and($html)->toContain('data-access-address')
        ->and($html)->toContain(e(TA_ADDRESS))
        ->and($html)->toContain(e(taGoogle()));
});

it('AC-TICKET-15: the organizer\'s ticket management page shows the address to the owner and to a collaborator', function (): void {
    $event = Event::factory()->create(['access_address' => TA_ADDRESS]);
    $band = User::factory()->create();
    EventCollaborator::factory()->for($event)->acceptedBy($band)->create();

    foreach ([$event->organizer, $band] as $user) {
        $html = (string) $this->actingAs($user)->get(route('events.manage', $event))->assertOk()->getContent();

        expect($html)->toContain('data-access-address')
            ->and($html)->toContain(e(TA_ADDRESS));
    }
});

it('AC-TICKET-16: the ticket email and the reminder carry the address and an "Open in Google Maps" link, in es, en and pt', function (): void {
    ['ticket' => $ticket, 'token' => $token] = taTicketFor(['access_address' => TA_ADDRESS]);

    $copy = [
        'es' => ['Dirección', 'Abrir en Google Maps'],
        'en' => ['Address', 'Open in Google Maps'],
        'pt' => ['Endereço', 'Abrir no Google Maps'],
    ];

    foreach ($copy as $locale => [$label, $open]) {
        foreach ([new TicketIssued($ticket, $token), new EventReminder($ticket)] as $mail) {
            $html = $mail->locale($locale)->render();

            preg_match('/<a\b[^>]*data-maps="google"[^>]*>([^<]*)<\/a>/', $html, $link);

            expect($html)->toContain(e(TA_ADDRESS))
                ->and($html)->toContain($label)
                ->and($link)->not->toBeEmpty()
                ->and($link[0])->toContain('href="'.e(taGoogle()).'"')
                ->and(trim($link[1]))->toBe($open)
                // One link in the mail, not two: Apple Maps is a ticket-page button only.
                ->and($html)->not->toContain('maps.apple.com');
        }
    }
});

it('AC-TICKET-16: the address in the email is escaped, never markup', function (): void {
    ['ticket' => $ticket, 'token' => $token] = taTicketFor(['access_address' => '<b>Sala</b> "uno"']);

    $html = (new TicketIssued($ticket, $token))->locale('en')->render();

    expect($html)->toContain('&lt;b&gt;Sala&lt;/b&gt; &quot;uno&quot;')
        ->and($html)->not->toContain('<b>Sala</b>');
});

it('AC-TICKET-18: without an address no address block, button or map link appears anywhere', function (): void {
    ['event' => $event, 'ticket' => $ticket, 'token' => $token] = taTicketFor(['access_info' => 'Timbre B']);

    $pages = (string) $this->get(route('ticket.show', ['token' => $token]))->getContent()
        .$this->actingAs($event->organizer)->get(route('events.manage', $event))->getContent();
    $mails = (new TicketIssued($ticket, $token))->locale('en')->render()
        .(new EventReminder($ticket))->locale('en')->render();

    foreach ([$pages, $mails] as $html) {
        expect($html)->not->toContain('data-access-address')
            ->and($html)->not->toContain('data-copy="access-address"')
            ->and($html)->not->toContain('data-maps')
            ->and($html)->not->toContain('google.com/maps')
            ->and($html)->not->toContain('maps.apple.com');
    }

    // access_info alone still renders exactly as before.
    expect($pages)->toContain('data-access-info');
});
