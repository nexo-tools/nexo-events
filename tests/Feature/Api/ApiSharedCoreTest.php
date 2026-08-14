<?php

use App\Enums\EventStatus;
use App\Mail\EventCancelled;
use App\Models\Event;
use App\Models\User;
use App\Services\EventCreator;
use App\Services\EventLifecycle;
use App\Services\EventRegistrar;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/ApiTestSupport.php';

/**
 * AC-API-12 — the two entry points run the same code, which is the claim the
 * rest of the SPEC leans on when it says "web-panel semantics".
 *
 * Two ways of checking, because they fail differently: that the web controller
 * really goes through the services (a future edit could quietly inline the
 * logic back and every other test would stay green), and that the observable
 * behaviour matches on the rules that matter — the publish gate and the
 * cancellation mails.
 */
test('AC-API-12: the web panel creates events through the shared service', function (): void {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->draft()->create();

    $this->mock(EventCreator::class)
        ->shouldReceive('create')
        ->once()
        ->andReturn($event);

    $this->actingAs($organizer)->post(route('events.store'), [
        'title' => 'Desde el panel',
        'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
    ])->assertRedirect(route('events.edit', $event));
});

test('AC-API-12: the web panel transitions events through the shared service', function (): void {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create();

    $lifecycle = $this->mock(EventLifecycle::class);
    $lifecycle->shouldReceive('publish')->once()->andReturn(EventLifecycle::OK);
    $lifecycle->shouldReceive('close')->once()->andReturn(EventLifecycle::OK);
    $lifecycle->shouldReceive('cancel')->once()->andReturn(EventLifecycle::OK);

    $this->actingAs($organizer)->post(route('events.publish', $event))->assertRedirect();
    $this->actingAs($organizer)->post(route('events.close', $event))->assertRedirect();
    $this->actingAs($organizer)->post(route('events.cancel', $event))->assertRedirect();
});

test('AC-API-12: the publish gate refuses the same account on both surfaces', function (): void {
    config(['nexo.api.enabled' => true]);

    $organizer = User::factory()->unverified()->create();
    $event = Event::factory()->for($organizer, 'organizer')->draft()->create();

    // The panel says so with an error on the page...
    $this->actingAs($organizer)->post(route('events.publish', $event))->assertSessionHasErrors('publish');
    expect($event->refresh()->status)->toBe(EventStatus::Draft);

    // ...and the API with a 422 the other side can act on. Same rule, one gate.
    $this->postJson("/api/v1/events/{$event->id}/publish", [], bearer(apiToken($organizer)))
        ->assertStatus(422)
        ->assertJsonPath('code', 'email_unverified');

    expect($event->refresh()->status)->toBe(EventStatus::Draft);
});

test('AC-API-12: cancelling mails the same people whichever surface cancels', function (): void {
    config(['nexo.api.enabled' => true]);
    Mail::fake();

    $organizer = User::factory()->create();

    $viaPanel = Event::factory()->for($organizer, 'organizer')->create();
    $viaApi = Event::factory()->for($organizer, 'organizer')->create();

    foreach ([$viaPanel, $viaApi] as $event) {
        app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    }

    $this->actingAs($organizer)->post(route('events.cancel', $viaPanel))->assertRedirect();
    Mail::assertQueued(EventCancelled::class, 1);

    $this->postJson("/api/v1/events/{$viaApi->id}/cancel", [], bearer(apiToken($organizer)))->assertOk();
    Mail::assertQueued(EventCancelled::class, 2);

    expect($viaPanel->refresh()->status)->toBe(EventStatus::Cancelled)
        ->and($viaApi->refresh()->status)->toBe(EventStatus::Cancelled);
});
