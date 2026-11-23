<?php

use App\Mail\EventReminder;
use App\Mail\TicketIssued;
use App\Models\Event;
use App\Models\EventCollaborator;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EventRegistrar;
use App\Support\AttendeePhone;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use libphonenumber\PhoneNumberUtil;

/**
 * Optional attendee phone (PLAN 10.13, country and format since 10.16) — SPEC
 * docs/specs/SPEC-ticket-info.md, ADR-003 amendments 2026-11-21 and 2026-11-23.
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
        ->and($html)->not->toContain('name="phone_country"')
        ->and($html)->not->toContain('type="tel"');
});

it('AC-TICKET-9: with the switch off a posted phone is ignored, not stored and not validated', function (): void {
    $event = Event::factory()->create();

    phoneRegister($event, ['phone' => 'not a phone at all, and far longer than thirty characters', 'phone_country' => 'nowhere'])
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

it('AC-TICKET-10: the phone is stored trimmed, in international form (E.164)', function (): void {
    phoneOn();
    $event = Event::factory()->create();

    phoneRegister($event, ['phone' => '  +54 9 11 2345-6789  ', 'phone_country' => 'AR'])->assertSessionHasNoErrors()->assertRedirect();

    expect(Ticket::sole()->attendee_phone)->toBe('+5491123456789');
});

it('AC-TICKET-11: letters, a misplaced +, too many characters and numbers no country hands out are refused', function (): void {
    phoneOn();
    $event = Event::factory()->create();

    foreach ([
        'letters' => '11 2345 CASA',
        'plus in the middle' => '11+2345678',
        'too short' => '12-345',
        'too long' => str_repeat('1', 31),
        'no area code' => '15 2345-6789',
        'local number only' => '4444-5555',
        'thirty ones' => str_repeat('1', 30),
        'a + code with a number too short for it' => '+54 11 2345',
    ] as $case => $phone) {
        phoneRegister($event, ['phone' => $phone, 'phone_country' => 'AR'])->assertSessionHasErrors('phone');
        expect(Ticket::count())->toBe(0, "The {$case} phone must not register anybody.");
    }
});

it('AC-TICKET-11: the usual ways of writing an Argentine number are accepted', function (): void {
    phoneOn();
    $event = Event::factory()->create();

    $typed = [
        '+54 9 11 2345-6789' => '+5491123456789',
        '011 15 2345-6789' => '+5491123456789',
        '11 15 2345 6789' => '+5491123456789',
        '5491123456789' => '+5491123456789',
        '1123456789' => '+541123456789',
        '(011) 4444.5555' => '+541144445555',
        '0351 15 555-1234' => '+5493515551234',
    ];

    // (string): PHP turns the all-digit keys into integers; a form posts text.
    foreach (array_keys($typed) as $i => $phone) {
        phoneRegister($event, ['email' => "persona{$i}@example.com", 'phone' => (string) $phone, 'phone_country' => 'AR'])->assertSessionHasNoErrors();
    }

    expect(Ticket::orderBy('id')->pluck('attendee_phone')->all())->toBe(array_values($typed));
});

it('AC-TICKET-12: the owner sees the phone next to the email, formatted, dialling and opening WhatsApp', function (): void {
    $event = Event::factory()->create();
    $registrar = app(EventRegistrar::class);
    $registrar->register($event, 'Ana Pérez', 'ana@example.com', '+5491123456789');
    $registrar->register($event, 'Caro Díaz', 'caro@example.com');

    $html = (string) $this->actingAs($event->organizer)->get(route('events.registrations', $event))->assertOk()->getContent();

    expect($html)->toContain('ana@example.com')
        ->and($html)->toContain('href="tel:+5491123456789" class="whitespace-nowrap text-link underline">+54 9 11 2345-6789</a>')
        ->and($html)->toContain('href="https://wa.me/5491123456789" target="_blank" rel="noopener noreferrer"')
        ->and($html)->toContain('>WhatsApp</a>')
        ->and(substr_count($html, 'data-attendee-phone'))->toBe(1);
});

it('AC-TICKET-12: an accepted collaborator never sees the phone', function (): void {
    $event = Event::factory()->create();
    $band = User::factory()->create();
    EventCollaborator::factory()->for($event)->acceptedBy($band)->create();
    app(EventRegistrar::class)->register($event, 'Ana Pérez', 'ana@example.com', '+54 9 11 2345-6789');

    $html = (string) $this->actingAs($band)->get(route('events.registrations', $event))->assertOk()->getContent();

    expect($html)->toContain('Ana Pérez')
        ->and($html)->not->toContain('2345-6789')
        ->and($html)->not->toContain('5491123456789')
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

/*
 * PLAN 10.16 — the country and the format (ADR-003 amendment 2026-11-23).
 */

/**
 * Every option of the country selector, in order.
 *
 * @return list<array{0: string, 1: string, 2: bool}> code, label, selected
 */
function phoneCountryOptions(string $html): array
{
    preg_match('/<select\b[^>]*name="phone_country"[^>]*>(.*?)<\/select>/s', $html, $select);
    preg_match_all('/<option value="([A-Z]{2})"\s*(selected)?\s*>([^<]*)<\/option>/', $select[1] ?? '', $options, PREG_SET_ORDER);

    return array_map(
        fn (array $option): array => [$option[1], html_entity_decode($option[3], ENT_QUOTES), $option[2] !== ''],
        $options,
    );
}

function ownerRegistrations(Event $event): string
{
    return (string) test()->actingAs($event->organizer)->get(route('events.registrations', $event))->assertOk()->getContent();
}

it('AC-TICKET-24: the country selector starts on the instance\'s country, then lists every country alphabetically with its code', function (): void {
    phoneOn();
    $event = Event::factory()->create();

    $html = (string) $this->get(route('public.event', $event))->assertOk()->getContent();
    $options = phoneCountryOptions($html);
    $codes = array_column($options, 0);

    expect($options[0])->toBe(['AR', 'Argentina (+54)', true])
        ->and(array_filter(array_column($options, 2)))->toHaveCount(1)
        ->and($codes)->toHaveCount(count(AttendeePhone::countryCodes()))
        ->and(array_unique($codes))->toHaveCount(count($codes))
        // The rest in the visitor's alphabetical order: Alemania follows Albania in Spanish.
        ->and(array_slice($options, 1, 3))->toBe([['AF', 'Afganistán (+93)', false], ['AL', 'Albania (+355)', false], ['DE', 'Alemania (+49)', false]])
        ->and($html)->toContain('<label for="phone_country" class="sr-only">'.e(__('Phone country')).'</label>')
        ->and($html)->toContain('<optgroup label="'.e(__('All countries')).'">')
        ->and(strpos($html, 'name="email"') < strpos($html, 'name="phone_country"'))->toBeTrue('The country goes after the email.')
        ->and(strpos($html, 'name="phone_country"') < strpos($html, 'name="phone"'))->toBeTrue('The country goes before the number.');

    // In Portuguese: the names and the order are Portuguese.
    $pt = phoneCountryOptions((string) $this->withUnencryptedCookie('nexo-lang', 'pt')->get(route('public.event', $event))->getContent());
    expect(array_slice($pt, 0, 4))->toBe([['AR', 'Argentina (+54)', true], ['AF', 'Afeganistão (+93)', false], ['ZA', 'África do Sul (+27)', false], ['AL', 'Albânia (+355)', false]])
        ->and($pt)->toContain(['BR', 'Brasil (+55)', false]);
});

it('AC-TICKET-24: NEXO_ATTENDEE_PHONE_COUNTRY picks where the selector starts, and an unknown code falls back to Argentina', function (): void {
    phoneOn();
    $event = Event::factory()->create();

    config(['nexo.attendee_phone_country' => 'UY']);
    $options = phoneCountryOptions((string) $this->get(route('public.event', $event))->getContent());
    expect($options[0])->toBe(['UY', 'Uruguay (+598)', true])
        ->and(array_filter($options, fn (array $option): bool => $option[0] === 'AR'))->toHaveCount(1);

    config(['nexo.attendee_phone_country' => 'ZZ']);
    expect(phoneCountryOptions((string) $this->get(route('public.event', $event))->getContent())[0])->toBe(['AR', 'Argentina (+54)', true]);

    $read = function (?string $value): string {
        if ($value === null) {
            putenv('NEXO_ATTENDEE_PHONE_COUNTRY');
            unset($_ENV['NEXO_ATTENDEE_PHONE_COUNTRY'], $_SERVER['NEXO_ATTENDEE_PHONE_COUNTRY']);
        } else {
            putenv("NEXO_ATTENDEE_PHONE_COUNTRY={$value}");
            $_ENV['NEXO_ATTENDEE_PHONE_COUNTRY'] = $_SERVER['NEXO_ATTENDEE_PHONE_COUNTRY'] = $value;
        }

        return (require config_path('nexo.php'))['attendee_phone_country'];
    };

    try {
        expect($read(null))->toBe('AR')
            ->and($read('uy'))->toBe('UY');
    } finally {
        $read(null);
    }
});

it('AC-TICKET-24: a number is read as one of the chosen country, unless it starts with + and brings its own', function (): void {
    phoneOn();
    $event = Event::factory()->create();

    phoneRegister($event, ['email' => 'joao@example.com', 'phone' => '21 99876-5432', 'phone_country' => 'BR'])->assertSessionHasNoErrors();
    phoneRegister($event, ['email' => 'lia@example.com', 'phone' => '+55 21 99876-5432', 'phone_country' => 'AR'])->assertSessionHasNoErrors();
    // No selector in the post (a form cached from before 10.16): the instance's country.
    phoneRegister($event, ['email' => 'beto@example.com', 'phone' => '11 2345-6789'])->assertSessionHasNoErrors();

    expect(Ticket::orderBy('id')->pluck('attendee_phone')->all())->toBe(['+5521998765432', '+5521998765432', '+541123456789']);

    // The same digits are no Argentine number, and a country nobody lists is refused.
    phoneRegister($event, ['email' => 'otro@example.com', 'phone' => '21 99876-5432', 'phone_country' => 'AR'])
        ->assertSessionHasErrors(['phone' => __('That doesn\'t look like a valid number for :country. Check the area code, or choose another country.', ['country' => 'Argentina'])]);
    phoneRegister($event, ['email' => 'otro@example.com', 'phone' => '+54 11 2345', 'phone_country' => 'AR'])
        ->assertSessionHasErrors(['phone' => __('That doesn\'t look like a valid phone number. Check the country code and the area code.')]);
    phoneRegister($event, ['email' => 'otro@example.com', 'phone' => '11 2345-6789', 'phone_country' => 'XX'])
        ->assertSessionHasErrors('phone_country');

    expect(Ticket::count())->toBe(3);
});

it('AC-TICKET-24: after a refused number the form keeps the chosen country and what was typed, and says why', function (): void {
    phoneOn();
    $event = Event::factory()->create();

    $html = (string) $this->from(route('public.event', $event))->followingRedirects()
        ->post(route('public.register', $event), ['name' => 'João', 'email' => 'joao@example.com', 'phone' => '21 1234', 'phone_country' => 'BR'])
        ->getContent();

    preg_match('/<input\b[^>]*name="phone"[^>]*>/', $html, $input);
    $selected = array_values(array_filter(phoneCountryOptions($html), fn (array $option): bool => $option[2]));

    expect($selected)->toBe([['BR', 'Brasil (+55)', true]])
        ->and($input[0] ?? '')->toContain('value="21 1234"')
        ->and($input[0] ?? '')->toContain('aria-invalid="true"')
        ->and($input[0] ?? '')->toContain('aria-describedby="phone-hint phone-error"')
        ->and($html)->toContain('id="phone-error"')
        ->and($html)->toContain(e(__('That doesn\'t look like a valid number for :country. Check the area code, or choose another country.', ['country' => 'Brasil'])))
        ->and(Ticket::count())->toBe(0);
});

it('AC-TICKET-25: an Argentine number without its 9 is shown and linked as a mobile, other countries are named, and a landline gets no WhatsApp', function (): void {
    $event = Event::factory()->create();
    $registrar = app(EventRegistrar::class);
    $registrar->register($event, 'Beto Gómez', 'beto@example.com', '+541123456789');      // typed "11 2345-6789"
    $registrar->register($event, 'João Silva', 'joao@example.com', '+5521998765432');     // a Brazilian mobile
    $registrar->register($event, 'Lucía Ruiz', 'lucia@example.com', '+34912345678');      // a landline in Madrid
    $registrar->register($event, 'Kim Lee', 'kim@example.com', '+14155552671');           // US: landline or mobile

    $html = ownerRegistrations($event);

    expect($html)->toContain('href="tel:+5491123456789" class="whitespace-nowrap text-link underline">+54 9 11 2345-6789</a>')
        ->and($html)->toContain('href="https://wa.me/5491123456789"')
        ->and($html)->not->toContain('wa.me/541123456789')
        ->and($html)->not->toContain('(Argentina)')
        ->and($html)->toContain('>+55 21 99876-5432</a>')
        ->and($html)->toContain('<span class="whitespace-nowrap">(Brasil)</span>')
        ->and($html)->toContain('href="https://wa.me/5521998765432"')
        ->and($html)->toContain('href="tel:+34912345678" class="whitespace-nowrap text-link underline">+34 912 34 56 78</a>')
        ->and($html)->toContain('<span class="whitespace-nowrap">(España)</span>')
        ->and($html)->not->toContain('wa.me/34912345678')
        ->and($html)->toContain('>+1 415-555-2671</a>')
        ->and($html)->toContain('<span class="whitespace-nowrap">(Estados Unidos)</span>')
        ->and($html)->toContain('href="https://wa.me/14155552671"');
});

it('AC-TICKET-26: numbers stored as typed before 10.16 are shown formatted, and one that still is not a number as it was typed', function (): void {
    $event = Event::factory()->create();
    $registrar = app(EventRegistrar::class);
    $registrar->register($event, 'Ana Pérez', 'ana@example.com', '+54 9 11 2345-6789');
    $registrar->register($event, 'Beto Gómez', 'beto@example.com', '(011) 4444-5555');
    $registrar->register($event, 'Caro Díaz', 'caro@example.com', '11 15 3333-2222');
    $registrar->register($event, 'Dani Paz', 'dani@example.com', '123456');
    $registrar->register($event, 'Eli Sosa', 'eli@example.com', '+999 123 456');

    $html = ownerRegistrations($event);

    expect($html)->toContain('href="tel:+5491123456789" class="whitespace-nowrap text-link underline">+54 9 11 2345-6789</a>')
        ->and($html)->toContain('href="https://wa.me/5491123456789"')
        ->and($html)->toContain('href="tel:+5491144445555" class="whitespace-nowrap text-link underline">+54 9 11 4444-5555</a>')
        ->and($html)->toContain('href="tel:+5491133332222" class="whitespace-nowrap text-link underline">+54 9 11 3333-2222</a>')
        ->and($html)->toContain('href="tel:123456" class="whitespace-nowrap text-link underline">123456</a>')
        ->and($html)->not->toContain('wa.me/123456')
        ->and($html)->toContain('href="tel:+999123456" class="whitespace-nowrap text-link underline">+999 123 456</a>')
        ->and($html)->not->toContain('wa.me/999')
        ->and(substr_count($html, 'data-attendee-phone'))->toBe(5);
});

it('AC-TICKET-27: the country lists name the same countries in en, es and pt, every one of them a country libphonenumber reads', function (): void {
    $supported = PhoneNumberUtil::getInstance()->getSupportedRegions();
    $lists = [];

    foreach (['en', 'es', 'pt'] as $locale) {
        $lists[$locale] = require lang_path("{$locale}/countries.php");
    }

    $codes = array_keys($lists['en']);
    sort($codes);

    foreach ($lists as $locale => $list) {
        $keys = array_keys($list);
        sort($keys);

        expect($keys)->toBe($codes, "[{$locale}] names other countries than en.")
            ->and(array_values(array_diff($keys, $supported)))->toBe([], "[{$locale}] lists a country libphonenumber cannot read.")
            ->and(array_filter($list, fn (string $name, string $code): bool => $name === '' || $name === $code, ARRAY_FILTER_USE_BOTH))
            ->toBe([], "[{$locale}] has a country without a name.");
    }

    expect($codes)->toContain('AR', 'BR', 'UY', 'ES', 'US')
        ->and([$lists['en']['BR'], $lists['es']['BR'], $lists['pt']['BR']])->toBe(['Brazil', 'Brasil', 'Brasil'])
        ->and([$lists['en']['DE'], $lists['es']['DE'], $lists['pt']['DE']])->toBe(['Germany', 'Alemania', 'Alemanha']);
});
