<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Notifications\VerifyEmailQueued;
use App\Services\NexoSso\NexoSsoUserResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

it('AC-VERIFY-1: signing up queues a verification email and leaves the organizer unverified', function (): void {
    Notification::fake();

    $this->post(route('register'), [
        'name' => 'Org',
        'email' => 'org@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect(route('dashboard'));

    $user = User::where('email', 'org@example.com')->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeFalse();
    // Queued, not sent in-request: a slow relay must not be able to fail a sign-up.
    Notification::assertSentTo($user, VerifyEmailQueued::class);
    expect(new VerifyEmailQueued)->toBeInstanceOf(ShouldQueue::class);
});

it('AC-VERIFY-2: an unverified organizer cannot publish, and the event keeps its status', function (): void {
    $organizer = User::factory()->unverified()->create();
    $event = Event::factory()->draft()->for($organizer, 'organizer')->create();

    $this->actingAs($organizer)
        ->post(route('events.publish', $event))
        ->assertSessionHasErrors('publish');

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});

it('AC-VERIFY-3: verifying the email lets that same organizer publish', function (): void {
    $organizer = User::factory()->unverified()->create();
    $event = Event::factory()->draft()->for($organizer, 'organizer')->create();

    $verifyUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $organizer->getKey(),
        'hash' => sha1((string) $organizer->getEmailForVerification()),
    ]);

    $this->actingAs($organizer)->get($verifyUrl)->assertRedirect(route('dashboard'));
    expect($organizer->fresh()->hasVerifiedEmail())->toBeTrue();

    $this->actingAs($organizer->fresh())
        ->post(route('events.publish', $event))
        ->assertSessionHasNoErrors();

    expect($event->fresh()->status)->toBe(EventStatus::Published);
});

it('AC-VERIFY-4: an organizer whose provider asserts email_verified is never asked to verify again', function (): void {
    // An account that signed up locally and never confirmed, later linked to a
    // Nexo ID whose email IS verified: the provider's assertion counts.
    $existing = User::factory()->unverified()->create(['email' => 'org@example.com']);

    app(NexoSsoUserResolver::class)->resolve([
        'sub' => 'nexoid-sub-123',
        'email' => 'org@example.com',
        'email_verified' => true,
        'name' => 'Org',
    ]);

    expect($existing->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('AC-VERIFY-4: a first-time SSO organizer with a verified email lands verified', function (): void {
    $user = app(NexoSsoUserResolver::class)->resolve([
        'sub' => 'nexoid-sub-456',
        'email' => 'nuevo@example.com',
        'email_verified' => true,
        'name' => 'Nuevo',
    ]);

    expect($user->hasVerifiedEmail())->toBeTrue();
});

it('AC-VERIFY-5: verification gates publishing only — create, edit, close and cancel stay available', function (): void {
    $organizer = User::factory()->unverified()->create();
    $this->actingAs($organizer);

    // Create a draft.
    $this->post(route('events.store'), [
        'title' => 'Charla abierta',
        'description' => 'Una charla',
        'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
        'venue' => 'Sala 1',
    ])->assertSessionHasNoErrors();

    $event = Event::where('title', 'Charla abierta')->firstOrFail();

    // Edit it.
    $this->put(route('events.update', $event), [
        'title' => 'Charla abierta (editada)',
        'description' => 'Una charla',
        'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
        'venue' => 'Sala 2',
    ])->assertSessionHasNoErrors();

    // Reach the dashboard and the registered list.
    $this->get(route('dashboard'))->assertOk();
    $this->get(route('events.registrations', $event))->assertOk();

    // Cancel it.
    $this->post(route('events.cancel', $event))->assertSessionHasNoErrors();
    expect($event->fresh()->status)->toBe(EventStatus::Cancelled);
});
