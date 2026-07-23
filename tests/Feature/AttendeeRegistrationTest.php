<?php

use App\Models\Event;
use App\Models\Ticket;
use App\Services\EventRegistrar;

it('issues a ticket whose token is stored only as a hash', function () {
    $event = Event::factory()->create();

    $out = app(EventRegistrar::class)->register($event, 'Juan', 'juan@example.com');

    expect($out['result'])->toBe(EventRegistrar::OK)
        ->and($out['token'])->not->toBeNull()
        ->and($out['ticket']->token_hash)->toBe(hash('sha256', $out['token']))
        // the RAW token is never persisted
        ->and(Ticket::where('token_hash', $out['token'])->exists())->toBeFalse();
});

it('is idempotent per email — one ticket per email per event', function () {
    $event = Event::factory()->create();
    $registrar = app(EventRegistrar::class);

    $registrar->register($event, 'Juan', 'juan@example.com');
    $second = $registrar->register($event, 'Juan', 'juan@example.com');

    expect($second['result'])->toBe(EventRegistrar::DUPLICATE)
        ->and($event->tickets()->count())->toBe(1);
});

it('enforces capacity: the last spot resolves to exactly ONE ticket (sold out)', function () {
    $event = Event::factory()->withCapacity(1)->create();
    $registrar = app(EventRegistrar::class);

    $a = $registrar->register($event, 'A', 'a@example.com');
    $b = $registrar->register($event, 'B', 'b@example.com');

    expect($a['result'])->toBe(EventRegistrar::OK)
        ->and($b['result'])->toBe(EventRegistrar::SOLD_OUT)
        ->and($event->tickets()->count())->toBe(1);
});

it('refuses registration unless the event is published', function () {
    $event = Event::factory()->draft()->create();

    expect(app(EventRegistrar::class)->register($event, 'A', 'a@example.com')['result'])
        ->toBe(EventRegistrar::CLOSED);
});

it('the public endpoint registers an attendee and redirects to their ticket', function () {
    $event = Event::factory()->create();

    $this->post(route('public.register', $event), [
        'name' => 'Ana',
        'email' => 'ana@example.com',
    ])->assertRedirect();

    expect($event->tickets()->count())->toBe(1);
});

it('the honeypot silently drops a bot registration', function () {
    $event = Event::factory()->create();

    $this->post(route('public.register', $event), [
        'name' => 'Bot',
        'email' => 'bot@example.com',
        'website' => 'http://spam.test',
    ])->assertSessionHasErrors('website');

    expect($event->tickets()->count())->toBe(0);
});
