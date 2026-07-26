<?php

use App\Enums\EventStatus;
use App\Mail\EventReported;
use App\Models\Event;
use App\Models\EventReport;
use App\Models\User;
use App\Services\EventRegistrar;
use App\Services\TicketCheckin;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

function reportEvent(Event $event, string $reason = 'Es una estafa, pide datos bancarios.', ?string $email = null): TestResponse
{
    return test()->post(route('public.report', $event), array_filter([
        'reason' => $reason,
        'reporter_email' => $email,
        'website' => '',
    ], fn ($v) => $v !== null));
}

it('AC-ABUSE-1: anyone can report a published event without an account', function (): void {
    Mail::fake();
    $event = Event::factory()->create();

    reportEvent($event, 'Suplanta a una marca conocida.', 'quien@example.com')
        ->assertRedirect()
        ->assertSessionHas('status');

    $report = EventReport::query()->firstOrFail();
    expect($report->event_id)->toBe($event->id)
        ->and($report->reason)->toBe('Suplanta a una marca conocida.')
        ->and($report->reporter_email)->toBe('quien@example.com');
    $this->assertGuest();
});

it('AC-ABUSE-3: a report notifies the instance operator, queued', function (): void {
    Mail::fake();
    $event = Event::factory()->create();

    reportEvent($event);

    Mail::assertQueued(EventReported::class, fn (EventReported $mail): bool => $mail->hasTo(config('nexo.support_email')));
    Mail::assertNothingSent(); // never in-request
});

it('AC-ABUSE-2: rate-limits reports and writes nothing once the limit is hit', function (): void {
    Mail::fake();
    $event = Event::factory()->create();

    foreach (range(1, 5) as $ignored) {
        reportEvent($event)->assertRedirect();
    }
    expect(EventReport::count())->toBe(5);

    reportEvent($event)->assertStatus(429);
    expect(EventReport::count())->toBe(5);
});

it('AC-ABUSE-4: answers the same whether the event has prior reports or none', function (): void {
    Mail::fake();
    $quiet = Event::factory()->create();
    $flagged = Event::factory()->create();
    $prior = new EventReport(['reason' => 'previo']);
    $prior->event()->associate($flagged);
    $prior->save();

    $first = reportEvent($quiet);
    $second = reportEvent($flagged);

    expect($second->getStatusCode())->toBe($first->getStatusCode());
    $first->assertSessionHas('status', session('status'));
    $second->assertSessionHas('status', session('status'));
});

it('AC-ABUSE-5: refuses a bot that fills the honeypot and stores nothing', function (): void {
    Mail::fake();
    $event = Event::factory()->create();

    $this->post(route('public.report', $event), [
        'reason' => 'spam spam spam spam',
        'website' => 'http://spam.example',
    ])->assertSessionHasErrors('website');

    expect(EventReport::count())->toBe(0);
    Mail::assertNothingQueued();
});

it('AC-KILL-1: kills an event, recording the reason, the time and the previous status', function (): void {
    $event = Event::factory()->create(); // published

    $this->artisan('events:kill', ['slug' => $event->slug, '--reason' => 'Phishing'])
        ->assertSuccessful();

    $event->refresh();
    expect($event->status)->toBe(EventStatus::Killed)
        ->and($event->kill_reason)->toBe('Phishing')
        ->and($event->status_before_kill)->toBe('published')
        ->and($event->killed_at)->not->toBeNull();
});

it('AC-KILL-2: a killed event disappears publicly, refuses registration and stops its tickets at the door', function (): void {
    $event = Event::factory()->create();
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');

    $this->artisan('events:kill', ['slug' => $event->slug, '--reason' => 'abuse'])->assertSuccessful();

    $this->get(route('public.event', $event))->assertNotFound();
    expect(app(EventRegistrar::class)->register($event->fresh(), 'Beto', 'beto@example.com')['result'])
        ->toBe(EventRegistrar::CLOSED);

    // Tickets already out there stop working — with a reason, not a silent failure.
    $outcome = app(TicketCheckin::class)->checkInByToken((string) $issued['token']);
    expect($outcome['result'])->toBe(TicketCheckin::EVENT_INACTIVE);
});

it('AC-KILL-3: restores the event to the status it had before, not a hardcoded one', function (): void {
    // A killed DRAFT must come back as a draft: restoring to "published" would
    // publish something the organizer never published.
    $draft = Event::factory()->draft()->create();

    $this->artisan('events:kill', ['slug' => $draft->slug, '--reason' => 'mistake'])->assertSuccessful();
    $this->artisan('events:restore', ['slug' => $draft->slug])->assertSuccessful();

    $draft->refresh();
    expect($draft->status)->toBe(EventStatus::Draft)
        ->and($draft->killed_at)->toBeNull()
        ->and($draft->kill_reason)->toBeNull();
});

it('AC-KILL-3: a restored published event works at the door again', function (): void {
    $event = Event::factory()->create();
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');

    $this->artisan('events:kill', ['slug' => $event->slug])->assertSuccessful();
    $this->artisan('events:restore', ['slug' => $event->slug])->assertSuccessful();

    expect(app(TicketCheckin::class)->checkInByToken((string) $issued['token'])['result'])
        ->toBe(TicketCheckin::OK);
});

it('AC-KILL-4: both commands fail loudly on an unknown slug and change nothing', function (): void {
    $this->artisan('events:kill', ['slug' => 'no-existe'])->assertFailed();
    $this->artisan('events:restore', ['slug' => 'no-existe'])->assertFailed();

    // Restore also refuses an event that is not killed.
    $event = Event::factory()->create();
    $this->artisan('events:restore', ['slug' => $event->slug])->assertFailed();
    expect($event->fresh()->status)->toBe(EventStatus::Published);
});

it('AC-KILL-5: re-killing keeps the original reason and previous status', function (): void {
    $event = Event::factory()->create();

    $this->artisan('events:kill', ['slug' => $event->slug, '--reason' => 'original'])->assertSuccessful();
    $this->artisan('events:kill', ['slug' => $event->slug, '--reason' => 'segundo intento'])->assertSuccessful();

    $event->refresh();
    // Overwriting status_before_kill with "killed" would make restore impossible.
    expect($event->kill_reason)->toBe('original')
        ->and($event->status_before_kill)->toBe('published');
});

it('AC-KILL-2: an organizer cannot un-kill their own event through the app', function (): void {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create();
    $this->artisan('events:kill', ['slug' => $event->slug])->assertSuccessful();

    // publish() only moves draft/closed events; killed is not one of them.
    $this->actingAs($organizer)->post(route('events.publish', $event));

    expect($event->fresh()->status)->toBe(EventStatus::Killed);
});
