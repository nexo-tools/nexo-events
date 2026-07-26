<?php

use App\Mail\TicketIssued;
use App\Models\Event;
use App\Services\EventRegistrar;
use App\Services\QrPng;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mime\Email;

/** Registers through the real HTTP endpoint, honeypot field included. */
function registerAttendee(Event $event, string $name = 'Ana', string $email = 'ana@example.com'): TestResponse
{
    return test()->post(route('public.register', $event), [
        'name' => $name,
        'email' => $email,
        'website' => '',
    ]);
}

it('AC-EMAIL-1: queues exactly one ticket email to the attendee on a successful registration', function (): void {
    Mail::fake();
    $event = Event::factory()->create();

    registerAttendee($event)->assertRedirect();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(TicketIssued::class, fn (TicketIssued $mail): bool => $mail->hasTo('ana@example.com'));
});

it('AC-EMAIL-5: queues the mail rather than sending it in-request, so a dead relay cannot break registration', function (): void {
    Mail::fake();
    $event = Event::factory()->create();

    registerAttendee($event);

    // Queued, never sent synchronously: the request returns without touching SMTP.
    Mail::assertQueued(TicketIssued::class);
    Mail::assertNothingSent();
});

/**
 * The message as it actually goes out on the wire.
 *
 * Deliberately NOT Mailable::render(): that is the preview path, and
 * Mailer::render() rewrites every `cid:` reference into a base64 data URI so the
 * HTML can be previewed standalone. Asserting on it would "prove" the exact
 * thing ADR-005 §7 forbids (Gmail strips data URIs) while the real email was
 * fine — or hide it if it ever broke.
 */
function sentTicketEmail(TicketIssued $mailable): Email
{
    Mail::to('ana@example.com')->send($mailable);

    return Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
}

it('AC-EMAIL-2: the sent email carries the event, the attendee, the ticket link and an inline QR image', function (): void {
    $event = Event::factory()->create([
        'title' => 'Feria de Editoriales',
        'venue' => 'Centro Cultural Kirchner',
    ]);
    $issued = app(EventRegistrar::class)->register($event, 'Ana Pérez', 'ana@example.com');

    $email = sentTicketEmail(new TicketIssued($issued['ticket'], $issued['token']));
    $html = (string) $email->getHtmlBody();

    expect($html)->toContain('Feria de Editoriales')
        ->and($html)->toContain('Centro Cultural Kirchner')
        ->and($html)->toContain('Ana Pérez')
        ->and($html)->toContain(route('ticket.show', ['token' => $issued['token']]))
        // Referenced by Content-ID, never as a data: URI (Gmail strips those).
        ->and($html)->toContain('cid:')
        ->and($html)->not->toContain('data:image');

    $inline = collect($email->getAttachments())
        ->filter(fn ($part): bool => str_contains((string) $part->asDebugString(), 'image/png'));

    expect($inline)->toHaveCount(1);
});

it('AC-EMAIL-3: the inline QR is byte-identical to the QR for this ticket token, and the raw token never reaches the log', function (): void {
    $event = Event::factory()->create();
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    $token = (string) $issued['token'];

    $email = sentTicketEmail(new TicketIssued($issued['ticket'], $token));

    $png = collect($email->getAttachments())
        ->first(fn ($part): bool => str_contains((string) $part->asDebugString(), 'image/png'));

    // What a phone scans at the door has to resolve to THIS ticket. Comparing
    // bytes against a freshly rendered QR for the same token proves it end to
    // end: wrong token in, different image out.
    expect($png)->not->toBeNull()
        ->and($png->getBody())->toBe(app(QrPng::class)->forText($token))
        ->and((string) $email->getHtmlBody())->toContain(route('ticket.show', ['token' => $token]));

    // The token is a bearer credential: it must never be written to disk as text.
    $log = storage_path('logs/laravel.log');
    if (file_exists($log)) {
        expect(file_get_contents($log))->not->toContain($token);
    }
});

it('AC-EMAIL-4: a duplicate registration issues no new ticket and queues no second email', function (): void {
    Mail::fake();
    $event = Event::factory()->create();

    registerAttendee($event);
    Mail::assertQueuedCount(1);

    registerAttendee($event); // same email, same event

    Mail::assertQueuedCount(1);
    expect($event->tickets()->count())->toBe(1);
});

it('AC-EMAIL-6: pins the locale of the registration so the queue worker renders the right language', function (): void {
    Mail::fake();
    $event = Event::factory()->create();

    $this->post(route('public.register', $event).'?lang=en', [
        'name' => 'Ann',
        'email' => 'ann@example.com',
        'website' => '',
    ]);

    Mail::assertQueued(TicketIssued::class, fn (TicketIssued $mail): bool => $mail->locale === 'en');
});

it('AC-QUEUE-1: every scheduled command resolves, and the queue is drained by the scheduler', function (): void {
    $schedule = app(Schedule::class);
    $commands = collect($schedule->events())->map(fn ($event): string => (string) $event->command);

    expect($commands)->not->toBeEmpty()
        ->and($commands->filter(fn (string $c): bool => str_contains($c, 'queue:work'))->count())->toBe(1);

    // The previous schedule pointed at nexo:send-reminders, which does not exist
    // and made every schedule:run error. Nothing scheduled may be unresolvable.
    $registered = array_keys(Artisan::all());
    foreach ($commands as $command) {
        preg_match("/artisan'? (\S+)/", $command, $m);
        if (isset($m[1])) {
            expect($registered)->toContain($m[1]);
        }
    }
});
