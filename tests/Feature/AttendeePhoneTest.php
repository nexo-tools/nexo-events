<?php

use App\Mail\EventReminder;
use App\Mail\TicketIssued;
use App\Models\Event;
use App\Models\EventCollaborator;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EventRegistrar;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * Optional attendee phone (PLAN 10.13) — SPEC docs/specs/SPEC-ticket-info.md,
 * ADR-003 amendment 2026-11-21.
 *
 * Two halves, like every attendee-data test here: where the number shows up
 * (the owner's list, behind the instance switch) and where it never does
 * (collaborators, public pages, emails, the API — the last one is asserted
 * in AC-API-11).
 */
beforeEach(function (): void {
    Mail::fake();
});

function phoneOn(): void
{
    config(['nexo.attendee_phone' => 'optional']);
}

/** @param  array<string, string>  $extra */
function phoneRegister(Event $event, array $extra = []): TestResponse
{
    return test()->post(route('public.register', $event), array_merge([
        'name' => 'Ana Pérez',
        'email' => 'ana@example.com',
    ], $extra));
}

it('AC-TICKET-9: with the switch off (the default) the form has no phone field', function (): void {
    expect(config('nexo.attendee_phone'))->toBe('off');

    $html = (string) $this->get(route('public.event', Event::factory()->create()))->assertOk()->getContent();

    expect($html)->not->toContain('name="phone"')
        ->and($html)->not->toContain('type="tel"');
});

it('AC-TICKET-9: with the switch off a posted phone is ignored, not stored and not validated', function (): void {
    $event = Event::factory()->create();

    phoneRegister($event, ['phone' => 'not a phone at all, and far longer than thirty characters'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $ticket = Ticket::sole();
    expect($ticket->attendee_phone)->toBeNull();
});

it('AC-TICKET-9: anything but "optional" in the env reads as off', function (): void {
    $read = function (string $value): string {
        putenv("NEXO_ATTENDEE_PHONE={$value}");
        $_ENV['NEXO_ATTENDEE_PHONE'] = $_SERVER['NEXO_ATTENDEE_PHONE'] = $value;

        return (require config_path('nexo.php'))['attendee_phone'];
    };

    try {
        foreach (['off', 'OPTIONAL', 'required', 'true', ''] as $value) {
            expect($read($value))->toBe('off', "NEXO_ATTENDEE_PHONE={$value} must read as off.");
        }

        expect($read('optional'))->toBe('optional');
    } finally {
        // Every later test boots a fresh config from the environment.
        putenv('NEXO_ATTENDEE_PHONE');
        unset($_ENV['NEXO_ATTENDEE_PHONE'], $_SERVER['NEXO_ATTENDEE_PHONE']);
    }
});

it('AC-TICKET-10: with the switch on the field follows the email, optional, labelled and described', function (): void {
    phoneOn();

    $html = (string) $this->get(route('public.event', Event::factory()->create()))->assertOk()->getContent();

    preg_match('/<input\b[^>]*name="phone"[^>]*>/', $html, $input);
    $tag = $input[0] ?? '';

    expect($tag)->not->toBe('')
        ->and($tag)->toContain('type="tel"')
        ->and($tag)->toContain('autocomplete="tel"')
        ->and($tag)->toContain('inputmode="tel"')
        ->and($tag)->toContain('id="phone"')
        ->and($tag)->toContain('aria-describedby="phone-hint"')
        ->and(str_contains($tag, 'required'))->toBeFalse('The phone must never be required.')
        ->and($html)->toContain('<label for="phone"')
        ->and($html)->toContain('id="phone-hint"')
        ->and($html)->toContain(e(__('WhatsApp or phone (optional)')))
        ->and($html)->toContain(e(__('Only the organizer sees it, in case they need to reach you.')))
        ->and(strpos($html, 'name="email"') < strpos($html, 'name="phone"'))->toBeTrue('The phone goes after the email.');

    // The copy the owner decided, in the three languages.
    $copy = [
        'es' => ['WhatsApp o teléfono (opcional)', 'Solo lo ve quien organiza, por si necesita avisarte algo.'],
        'en' => ['WhatsApp or phone (optional)', 'Only the organizer sees it, in case they need to reach you.'],
        'pt' => ['WhatsApp ou telefone (opcional)', 'Só quem organiza vê, caso precise falar com você.'],
    ];

    foreach ($copy as $locale => [$label, $hint]) {
        app()->setLocale($locale);
        expect(__('WhatsApp or phone (optional)'))->toBe($label)
            ->and(__('Only the organizer sees it, in case they need to reach you.'))->toBe($hint);
    }
});

it('AC-TICKET-10: registering without a phone still works, and an empty one is stored as null', function (): void {
    phoneOn();
    $event = Event::factory()->create();

    phoneRegister($event)->assertSessionHasNoErrors()->assertRedirect();
    phoneRegister($event, ['email' => 'beto@example.com', 'phone' => '   '])->assertSessionHasNoErrors()->assertRedirect();

    expect(Ticket::pluck('attendee_phone')->all())->toBe([null, null]);
});

it('AC-TICKET-10: the phone is stored trimmed', function (): void {
    phoneOn();
    $event = Event::factory()->create();

    phoneRegister($event, ['phone' => '  +54 9 11 2345-6789  '])->assertSessionHasNoErrors()->assertRedirect();

    expect(Ticket::sole()->attendee_phone)->toBe('+54 9 11 2345-6789');
});

it('AC-TICKET-11: letters, too few digits and too many characters are refused', function (): void {
    phoneOn();
    $event = Event::factory()->create();

    foreach ([
        'letters' => '11 2345 CASA',
        'plus in the middle' => '11+2345678',
        'too short' => '12-345',
        'too long' => str_repeat('1', 31),
    ] as $case => $phone) {
        phoneRegister($event, ['phone' => $phone])->assertSessionHasErrors('phone');
        expect(Ticket::count())->toBe(0, "The {$case} phone must not register anybody.");
    }
});

it('AC-TICKET-11: the usual ways of writing a number are accepted', function (): void {
    phoneOn();
    $event = Event::factory()->create();

    foreach (['+54 9 11 2345-6789', '(011) 4444.5555', '1123456789', '123456', str_repeat('1', 30)] as $i => $phone) {
        phoneRegister($event, ['email' => "persona{$i}@example.com", 'phone' => $phone])->assertSessionHasNoErrors();
    }

    expect(Ticket::count())->toBe(5);
});

it('AC-TICKET-12: the owner sees the phone next to the email — wa.me with a country code, tel: without', function (): void {
    $event = Event::factory()->create();
    $registrar = app(EventRegistrar::class);
    $registrar->register($event, 'Ana Pérez', 'ana@example.com', '+54 9 11 2345-6789');
    $registrar->register($event, 'Beto Gómez', 'beto@example.com', '(011) 4444-5555');
    $registrar->register($event, 'Caro Díaz', 'caro@example.com');

    $html = (string) $this->actingAs($event->organizer)->get(route('events.registrations', $event))->assertOk()->getContent();

    expect($html)->toContain('ana@example.com')
        ->and($html)->toContain('+54 9 11 2345-6789')
        ->and($html)->toContain('href="https://wa.me/5491123456789"')
        ->and($html)->toContain('>WhatsApp</a>')
        ->and($html)->toContain('rel="noopener noreferrer"')
        ->and($html)->toContain('href="tel:01144445555"')
        ->and($html)->toContain('>(011) 4444-5555</a>')
        ->and(substr_count($html, 'data-attendee-phone'))->toBe(2);
});

it('AC-TICKET-12: an accepted collaborator never sees the phone', function (): void {
    $event = Event::factory()->create();
    $band = User::factory()->create();
    EventCollaborator::factory()->for($event)->acceptedBy($band)->create();
    app(EventRegistrar::class)->register($event, 'Ana Pérez', 'ana@example.com', '+54 9 11 2345-6789');

    $html = (string) $this->actingAs($band)->get(route('events.registrations', $event))->assertOk()->getContent();

    expect($html)->toContain('Ana Pérez')
        ->and($html)->not->toContain('2345-6789')
        ->and($html)->not->toContain('wa.me')
        ->and($html)->not->toContain('tel:')
        ->and($html)->not->toContain('data-attendee-phone');
});

it('AC-TICKET-12: the phone is on no public page and in no email', function (): void {
    phoneOn();
    $event = Event::factory()->create();
    $issued = app(EventRegistrar::class)->register($event, 'Ana Pérez', 'ana@example.com', '+54 9 11 2345-6789');

    $pages = (string) $this->get(route('public.event', $event))->getContent()
        .$this->get(route('ticket.show', ['token' => $issued['token']]))->getContent()
        .$this->get(route('sitemap'))->getContent();

    $mails = '';
    foreach (['es', 'en', 'pt'] as $locale) {
        $mails .= (new TicketIssued($issued['ticket'], $issued['token']))->locale($locale)->render()
            .(new EventReminder($issued['ticket']))->locale($locale)->render();
    }

    foreach ([$pages, $mails] as $html) {
        expect($html)->not->toContain('2345-6789')
            ->and($html)->not->toContain('5491123456789');
    }
});

it('AC-TICKET-13: the privacy page describes the phone only while the switch is on, in es, en and pt', function (): void {
    foreach (['es', 'en', 'pt'] as $locale) {
        $section = require lang_path("{$locale}/legal.php");
        $heading = $section['privacy_phone']['h'];

        config(['nexo.attendee_phone' => 'off']);
        $off = (string) $this->withUnencryptedCookie('nexo-lang', $locale)->get(route('legal.privacy'))->assertOk()->getContent();

        config(['nexo.attendee_phone' => 'optional']);
        $on = (string) $this->withUnencryptedCookie('nexo-lang', $locale)->get(route('legal.privacy'))->assertOk()->getContent();

        expect(str_contains($off, e($heading)))->toBeFalse("[{$locale}] the phone section shows with the switch off.")
            ->and(str_contains($on, e($heading)))->toBeTrue("[{$locale}] the phone section is missing with the switch on.")
            ->and(str_contains($on, e($section['privacy_phone']['p'])))->toBeTrue("[{$locale}] the phone paragraph is missing.");

        // Right after what is kept about attendees.
        $attendees = e($section['privacy']['sections'][1]['h']);
        $qr = e($section['privacy']['sections'][2]['h']);
        expect(strpos($on, $attendees) < strpos($on, e($heading)) && strpos($on, e($heading)) < strpos($on, $qr))->toBeTrue("[{$locale}] the phone section is out of place.");
    }
});
