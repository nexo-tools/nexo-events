<?php

use App\Enums\TicketStatus;
use App\Mail\TicketIssued;
use App\Models\Event;
use App\Services\EventRegistrar;
use App\Services\TicketCheckin;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

function requestResend(Event $event, string $email = 'ana@example.com'): TestResponse
{
    return test()->post(route('public.resend', $event), [
        'email' => $email,
        'website' => '',
    ]);
}

it('AC-RESEND-1: re-delivers the ticket to the registered address without issuing a second ticket', function (): void {
    Mail::fake();
    $event = Event::factory()->create();
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');

    requestResend($event)->assertRedirect();

    Mail::assertQueued(TicketIssued::class, fn (TicketIssued $mail): bool => $mail->hasTo('ana@example.com')
        && $mail->ticket->is($issued['ticket']));
    expect($event->tickets()->count())->toBe(1);
});

it('AC-RESEND-1: rotates the token, so the resent QR works and the previous one stops (ADR-008)', function (): void {
    Mail::fake();
    $event = Event::factory()->create();
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    $oldToken = (string) $issued['token'];

    requestResend($event);

    $newToken = null;
    Mail::assertQueued(TicketIssued::class, function (TicketIssued $mail) use (&$newToken): bool {
        $newToken = $mail->token;

        return true;
    });

    expect($newToken)->not->toBe($oldToken)
        // The rotation is what the door sees: the new token validates, the old one is unknown.
        ->and($issued['ticket']->fresh()->token_hash)->toBe(hash('sha256', (string) $newToken));

    $checkin = app(TicketCheckin::class);
    expect($checkin->checkInByToken($oldToken)['result'])->toBe(TicketCheckin::UNKNOWN)
        ->and($checkin->checkInByToken((string) $newToken)['result'])->toBe(TicketCheckin::OK);
});

it('AC-RESEND-2: answers identically for a registered and an unregistered address', function (): void {
    Mail::fake();
    $event = Event::factory()->create();
    app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');

    $registered = requestResend($event, 'ana@example.com');
    $unknown = requestResend($event, 'nobody@example.com');

    expect($unknown->getStatusCode())->toBe($registered->getStatusCode())
        ->and(session('status'))->not->toBeNull();
    $registered->assertSessionHas('status', session('status'));

    // Only the address that actually holds a ticket ever receives mail.
    Mail::assertQueuedCount(1);
    Mail::assertQueued(TicketIssued::class, fn (TicketIssued $mail): bool => $mail->hasTo('ana@example.com'));
});

it('AC-RESEND-2: never resends a revoked ticket, and leaves its token untouched', function (): void {
    Mail::fake();
    $event = Event::factory()->create();
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    $issued['ticket']->update(['status' => TicketStatus::Revoked]);
    $hashBefore = $issued['ticket']->fresh()->token_hash;

    requestResend($event)->assertRedirect();

    Mail::assertNothingQueued();
    expect($issued['ticket']->fresh()->token_hash)->toBe($hashBefore);
});

it('AC-RESEND-3: rate-limits resends and queues no mail once the limit is hit', function (): void {
    Mail::fake();
    $event = Event::factory()->create();
    app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');

    for ($i = 0; $i < 3; $i++) {
        requestResend($event)->assertRedirect();
    }
    Mail::assertQueuedCount(3);

    requestResend($event)->assertStatus(429);
    Mail::assertQueuedCount(3); // the blocked request never reached the resender
});

it('AC-RESEND-3: refuses a bot that fills the honeypot', function (): void {
    Mail::fake();
    $event = Event::factory()->create();
    app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');

    $this->post(route('public.resend', $event), [
        'email' => 'ana@example.com',
        'website' => 'http://spam.example',
    ])->assertSessionHasErrors('website');

    Mail::assertNothingQueued();
});
