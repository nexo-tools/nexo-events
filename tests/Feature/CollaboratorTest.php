<?php

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Mail\CollaboratorInvited;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\EventCollaborator;
use App\Models\User;
use App\Services\CollaboratorInviter;
use App\Services\EventRegistrar;
use App\Services\TicketCheckin;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Event collaborators — SPEC docs/specs/SPEC-event-collaborators.md, ADR-011.
 */
beforeEach(function (): void {
    Mail::fake();
});

/** Invites $email to $event as its owner and returns the raw token from the link. */
function collabInvite(Event $event, string $email): array
{
    $outcome = app(CollaboratorInviter::class)->invite($event, $email, $event->organizer);

    $token = $outcome['url'] !== null ? Str::afterLast($outcome['url'], '/') : null;

    return [...$outcome, 'token' => $token];
}

/** A band account that accepted an invitation to $event. */
function collabAccepted(Event $event, string $email = 'banda@example.com'): User
{
    $band = User::factory()->create(['email' => $email]);
    EventCollaborator::factory()->for($event)->acceptedBy($band)->create();

    return $band;
}

it('AC-COLLAB-1: inviting stores a hash, lowercases the email, sets the expiry and queues the mail', function (): void {
    $event = Event::factory()->create(['starts_at' => now()->addWeeks(3)]);

    $outcome = collabInvite($event, '  Banda@Example.COM ');

    expect($outcome['result'])->toBe(CollaboratorInviter::CREATED)
        ->and(strlen((string) $outcome['token']))->toBe(40);

    $row = EventCollaborator::sole();

    expect($row->email)->toBe('banda@example.com')
        ->and($row->invite_token_hash)->toBe(hash('sha256', (string) $outcome['token']))
        ->and($row->invite_token_hash)->not->toBe($outcome['token'])
        // Three weeks away: starts_at + 1 day wins over now + 7 days.
        ->and($row->expires_at->equalTo($event->starts_at->copy()->addDay()))->toBeTrue()
        ->and($row->invited_by)->toBe($event->organizer_id);

    Mail::assertQueued(CollaboratorInvited::class, fn (CollaboratorInvited $mail): bool => $mail->hasTo('banda@example.com')
        && $mail->inviteUrl === $outcome['url']);
});

it('AC-COLLAB-1: an event tomorrow still gets a week to accept', function (): void {
    $event = Event::factory()->create(['starts_at' => now()->addDay()]);

    collabInvite($event, 'banda@example.com');

    expect(EventCollaborator::sole()->expires_at->diffInDays(now(), true))->toBeGreaterThanOrEqual(6.9);
});

it('AC-COLLAB-2: accepting with a verified account of the same email grants access, once', function (): void {
    $event = Event::factory()->create();
    $token = collabInvite($event, 'banda@example.com')['token'];
    $band = User::factory()->create(['email' => 'Banda@example.com']);

    $this->actingAs($band)->get(route('invitations.show', ['token' => $token]))
        ->assertOk()->assertSee($event->title);

    $this->actingAs($band)->post(route('invitations.accept', ['token' => $token]))
        ->assertRedirect(route('events.manage', $event));

    $row = EventCollaborator::sole();
    expect($row->user_id)->toBe($band->id)
        ->and($row->accepted_at)->not->toBeNull()
        ->and($row->invite_token_hash)->toBeNull()
        ->and($event->canBeManagedBy($band))->toBeTrue();

    // Single use: the same link is now nothing.
    $this->actingAs($band)->get(route('invitations.show', ['token' => $token]))->assertNotFound();
    $this->actingAs($band)->post(route('invitations.accept', ['token' => $token]))->assertNotFound();
});

it('AC-COLLAB-3: another email gets 403 and nothing is associated', function (): void {
    $event = Event::factory()->create();
    $token = collabInvite($event, 'banda@example.com')['token'];
    $intruder = User::factory()->create(['email' => 'otro@example.com']);

    $this->actingAs($intruder)->get(route('invitations.show', ['token' => $token]))->assertForbidden();
    $this->actingAs($intruder)->post(route('invitations.accept', ['token' => $token]))->assertForbidden();

    expect(EventCollaborator::sole()->user_id)->toBeNull()
        ->and($event->canBeManagedBy($intruder))->toBeFalse();
});

it('AC-COLLAB-3: an expired invitation answers 410, a revoked or unknown one 404', function (): void {
    $event = Event::factory()->create(['starts_at' => now()->addDay()]);
    $band = User::factory()->create(['email' => 'banda@example.com']);

    $expired = collabInvite($event, 'banda@example.com')['token'];
    $this->travel(8)->days();
    $this->actingAs($band)->get(route('invitations.show', ['token' => $expired]))->assertStatus(410);
    $this->actingAs($band)->post(route('invitations.accept', ['token' => $expired]))->assertStatus(410);
    $this->travelBack();

    $revoked = collabInvite($event, 'banda@example.com')['token'];
    app(CollaboratorInviter::class)->revoke(EventCollaborator::sole());
    $this->actingAs($band)->get(route('invitations.show', ['token' => $revoked]))->assertNotFound();

    $this->actingAs($band)->get(route('invitations.show', ['token' => Str::random(40)]))->assertNotFound();

    expect(EventCollaborator::sole()->user_id)->toBeNull();
});

it('AC-COLLAB-3: an unverified account with the right email is asked to verify first, and associates nothing', function (): void {
    $event = Event::factory()->create();
    $token = collabInvite($event, 'banda@example.com')['token'];
    $band = User::factory()->unverified()->create(['email' => 'banda@example.com']);

    $this->actingAs($band)->get(route('invitations.show', ['token' => $token]))
        ->assertOk()
        ->assertSee(__('Verify your email to accept the invitation. Once verified, you come back here.'))
        ->assertDontSee(route('invitations.accept', ['token' => $token]))
        // The verification link must bring them back to the invitation.
        ->assertSessionHas('url.intended', route('invitations.show', ['token' => $token]));

    $this->actingAs($band)->post(route('invitations.accept', ['token' => $token]))->assertOk();

    expect(EventCollaborator::sole()->accepted_at)->toBeNull()
        ->and($event->canBeManagedBy($band))->toBeFalse();
});

it('AC-COLLAB-4: a signed-out visitor goes to login and, after registering, lands back on the invitation', function (): void {
    $event = Event::factory()->create();
    $token = collabInvite($event, 'banda@example.com')['token'];
    $invitation = route('invitations.show', ['token' => $token]);

    $this->get($invitation)->assertRedirect(route('login'));

    $this->get(route('login'))->assertOk()->assertSee('data-invitation-help', false);

    $this->post(route('register'), [
        'name' => 'La Banda',
        'email' => 'banda@example.com',
        'password' => 'una-clave-larga-123',
        'password_confirmation' => 'una-clave-larga-123',
    ])->assertRedirect($invitation);
});

it('AC-COLLAB-4: signing in from the invitation also lands back on it', function (): void {
    $event = Event::factory()->create();
    $token = collabInvite($event, 'banda@example.com')['token'];
    $invitation = route('invitations.show', ['token' => $token]);
    User::factory()->create(['email' => 'banda@example.com', 'password' => 'una-clave-larga-123']);

    $this->get($invitation)->assertRedirect(route('login'));

    $this->post(route('login'), ['email' => 'banda@example.com', 'password' => 'una-clave-larga-123'])
        ->assertRedirect($invitation);
});

it('AC-COLLAB-5: an accepted collaborator manages the door and nothing else', function (): void {
    $event = Event::factory()->create();
    $band = collabAccepted($event);
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    $other = app(EventRegistrar::class)->register($event, 'Beto', 'beto@example.com');

    $this->actingAs($band)->get(route('events.registrations', $event))->assertOk();
    $this->actingAs($band)->get(route('events.scan', $event))->assertOk();
    $this->actingAs($band)->postJson(route('events.checkin', $event), ['token' => $issued['token']])
        ->assertOk()->assertJsonPath('result', 'ok');
    $this->actingAs($band)->post(route('tickets.checkin', $other['ticket']))->assertRedirect();
    $this->actingAs($band)->post(route('events.close', $event))->assertRedirect();

    expect($event->fresh()->status)->toBe(EventStatus::Closed);

    $this->actingAs($band)->get(route('events.edit', $event))->assertForbidden();
    $this->actingAs($band)->put(route('events.update', $event), ['title' => 'Robado', 'starts_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertForbidden();
    $this->actingAs($band)->post(route('events.publish', $event))->assertForbidden();
    $this->actingAs($band)->post(route('events.cancel', $event))->assertForbidden();

    expect($event->fresh()->title)->not->toBe('Robado')
        ->and($event->fresh()->status)->toBe(EventStatus::Closed);
});

it('AC-COLLAB-6: revoked, never-accepted and long-past collaborators are out; the owner stays in', function (): void {
    $event = Event::factory()->create(['starts_at' => now()->addDay()]);

    $revoked = User::factory()->create();
    EventCollaborator::factory()->for($event)->acceptedBy($revoked)->revoked()->create();

    $pending = User::factory()->create(['email' => 'pendiente@example.com']);
    collabInvite($event, 'pendiente@example.com');

    $band = collabAccepted($event);

    foreach ([$revoked, $pending] as $user) {
        $this->actingAs($user)->get(route('events.registrations', $event))->assertForbidden();
        $this->actingAs($user)->get(route('events.scan', $event))->assertForbidden();
    }

    $this->actingAs($band)->get(route('events.registrations', $event))->assertOk();

    // Seven days after the event the band's access is over; eight is past it.
    $this->travel(9)->days();

    $this->actingAs($band)->get(route('events.registrations', $event))->assertForbidden();
    $this->actingAs($band)->get(route('events.scan', $event))->assertForbidden();
    $this->actingAs($band)->post(route('events.close', $event))->assertForbidden();
    $this->actingAs($event->organizer)->get(route('events.registrations', $event))->assertOk();
});

it('AC-COLLAB-7: someone who is neither owner nor collaborator is still refused everywhere', function (): void {
    $event = Event::factory()->create();
    collabAccepted($event);
    $ticket = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com')['ticket'];
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('events.registrations', $event))->assertForbidden();
    $this->actingAs($stranger)->get(route('events.scan', $event))->assertForbidden();
    $this->actingAs($stranger)->post(route('events.checkin', $event), ['token' => 'x'])->assertForbidden();
    $this->actingAs($stranger)->post(route('tickets.checkin', $ticket))->assertForbidden();
    $this->actingAs($stranger)->post(route('tickets.release', $ticket))->assertForbidden();
    $this->actingAs($stranger)->post(route('events.close', $event))->assertForbidden();
});

it('AC-COLLAB-8: a collaborator\'s check-in is recorded as theirs', function (): void {
    $event = Event::factory()->create();
    $band = collabAccepted($event);
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');

    $this->actingAs($band)->postJson(route('events.checkin', $event), ['token' => $issued['token']])->assertOk();

    expect(Checkin::sole()->checked_by)->toBe($band->id);
});

it('AC-COLLAB-9: the eleventh active invitation is refused', function (): void {
    $event = Event::factory()->create();

    foreach (range(1, CollaboratorInviter::MAX_ACTIVE) as $n) {
        expect(collabInvite($event, "banda{$n}@example.com")['result'])->toBe(CollaboratorInviter::CREATED);
    }

    $eleventh = collabInvite($event, 'banda11@example.com');

    expect($eleventh['result'])->toBe(CollaboratorInviter::TOO_MANY)
        ->and($eleventh['url'])->toBeNull()
        ->and(EventCollaborator::count())->toBe(10);

    Mail::assertQueued(CollaboratorInvited::class, 10);

    // A revoked one frees its slot.
    app(CollaboratorInviter::class)->revoke(EventCollaborator::first());
    expect(collabInvite($event, 'banda11@example.com')['result'])->toBe(CollaboratorInviter::CREATED);
});

it('AC-COLLAB-9: re-inviting a pending email rotates the link and kills the old one', function (): void {
    $event = Event::factory()->create();
    $band = User::factory()->create(['email' => 'banda@example.com']);

    $first = collabInvite($event, 'banda@example.com');
    $second = collabInvite($event, 'banda@example.com');

    expect($second['result'])->toBe(CollaboratorInviter::ROTATED)
        ->and($second['token'])->not->toBe($first['token'])
        ->and(EventCollaborator::count())->toBe(1)
        ->and(EventCollaborator::sole()->invite_token_hash)->toBe(hash('sha256', (string) $second['token']));

    $this->actingAs($band)->get(route('invitations.show', ['token' => $first['token']]))->assertNotFound();
    $this->actingAs($band)->get(route('invitations.show', ['token' => $second['token']]))->assertOk();

    Mail::assertQueued(CollaboratorInvited::class, 2);

    // Accepted: inviting again sends nothing.
    $this->actingAs($band)->post(route('invitations.accept', ['token' => $second['token']]));
    expect(collabInvite($event, 'banda@example.com')['result'])->toBe(CollaboratorInviter::ALREADY_ACCEPTED);
    Mail::assertQueued(CollaboratorInvited::class, 2);
});

it('AC-COLLAB-10: the table is declared, and an event without collaborators behaves as before with the API off', function (): void {
    config(['nexo.api.enabled' => false]);

    expect(Schema::hasTable('event_collaborators'))->toBeTrue()
        ->and(Schema::hasColumns('event_collaborators', [
            'event_id', 'user_id', 'email', 'invite_token_hash', 'invited_by', 'invited_via',
            'invited_at', 'expires_at', 'accepted_at', 'revoked_at',
        ]))->toBeTrue();

    $event = Event::factory()->create();
    $this->actingAs($event->organizer)->get(route('events.registrations', $event))->assertOk();
    $this->actingAs($event->organizer)->get(route('events.scan', $event))->assertOk();
    expect($event->collaborators()->count())->toBe(0);
});

it('AC-COLLAB-17: an owner without a verified email cannot invite anyone', function (): void {
    $owner = User::factory()->unverified()->create();
    $event = Event::factory()->for($owner, 'organizer')->create();

    $outcome = collabInvite($event, 'banda@example.com');

    expect($outcome['result'])->toBe(CollaboratorInviter::UNVERIFIED)
        ->and($outcome['url'])->toBeNull()
        ->and(EventCollaborator::count())->toBe(0);

    Mail::assertNothingQueued();
});

it('AC-COLLAB-18: releasing a spot revokes the ticket, frees capacity and the door rejects it', function (): void {
    $event = Event::factory()->withCapacity(1)->create();
    $band = collabAccepted($event);
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');

    expect($event->fresh()->isSoldOut())->toBeTrue();

    $this->actingAs($band)->post(route('tickets.release', $issued['ticket']))
        ->assertRedirect()->assertSessionHas('status');

    expect($issued['ticket']->fresh()->status)->toBe(TicketStatus::Revoked)
        ->and($event->fresh()->isSoldOut())->toBeFalse();

    $this->actingAs($band)->postJson(route('events.checkin', $event), ['token' => $issued['token']])
        ->assertOk()->assertJsonPath('result', 'revoked');

    // The freed spot can be taken by somebody else.
    expect(app(EventRegistrar::class)->register($event->fresh(), 'Beto', 'beto@example.com')['result'])->toBe(EventRegistrar::OK);
});

it('AC-COLLAB-18: someone already inside cannot be released', function (): void {
    $event = Event::factory()->create();
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    app(TicketCheckin::class)->checkInTicket($issued['ticket'], $event->organizer);

    $this->actingAs($event->organizer)->post(route('tickets.release', $issued['ticket']))
        ->assertRedirect()->assertSessionHasErrors('release');

    expect($issued['ticket']->fresh()->status)->toBe(TicketStatus::CheckedIn);
});

it('AC-COLLAB-19: giving a released spot back makes the ticket valid again — the same QR gets in, and nothing is mailed', function (): void {
    $event = Event::factory()->withCapacity(2)->create();
    $band = collabAccepted($event);
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    $this->actingAs($band)->post(route('tickets.release', $issued['ticket']))->assertRedirect();
    Mail::fake(); // only what giving it back would send

    $this->actingAs($band)->post(route('tickets.restore', $issued['ticket']))
        ->assertRedirect()->assertSessionHas('status');

    expect($issued['ticket']->fresh()->status)->toBe(TicketStatus::Valid);
    $this->actingAs($band)->postJson(route('events.checkin', $event), ['token' => $issued['token']])
        ->assertOk()->assertJsonPath('result', 'ok');
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('AC-COLLAB-19: with no room left the spot stays released, and the list says so instead of offering it', function (): void {
    $event = Event::factory()->withCapacity(1)->create();
    $ana = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    $this->actingAs($event->organizer)->post(route('tickets.release', $ana['ticket']))->assertRedirect();
    expect(app(EventRegistrar::class)->register($event->fresh(), 'Beto', 'beto@example.com')['result'])->toBe(EventRegistrar::OK);

    $this->actingAs($event->organizer)->post(route('tickets.restore', $ana['ticket']))
        ->assertRedirect()->assertSessionHasErrors('restore');

    expect($ana['ticket']->fresh()->status)->toBe(TicketStatus::Revoked);
    $this->actingAs($event->organizer)->get(route('events.registrations', $event))
        ->assertOk()
        ->assertSee('data-no-room', false)
        ->assertDontSee(route('tickets.restore', $ana['ticket']), false);
});

it('AC-COLLAB-19: the list offers «Devolver el lugar» for a released ticket while there is room, and an event without capacity always has room', function (?int $capacity): void {
    $event = $capacity === null ? Event::factory()->create() : Event::factory()->withCapacity($capacity)->create();
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    $this->actingAs($event->organizer)->post(route('tickets.release', $issued['ticket']))->assertRedirect();

    $this->actingAs($event->organizer)->get(route('events.registrations', $event))
        ->assertOk()
        ->assertSee(route('tickets.restore', $issued['ticket']), false);

    $this->actingAs($event->organizer)->post(route('tickets.restore', $issued['ticket']))->assertSessionHasNoErrors();
    expect($issued['ticket']->fresh()->status)->toBe(TicketStatus::Valid);
})->with(['with room' => [5], 'without capacity' => [null]]);

it('AC-COLLAB-19: only the owner or a collaborator gives a spot back', function (): void {
    $event = Event::factory()->create();
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    $this->actingAs($event->organizer)->post(route('tickets.release', $issued['ticket']))->assertRedirect();

    $this->actingAs(User::factory()->create())->post(route('tickets.restore', $issued['ticket']))->assertForbidden();

    expect($issued['ticket']->fresh()->status)->toBe(TicketStatus::Revoked);
});

it('AC-COLLAB-19: a ticket that was not released stays as it is, and a cancelled event gives nothing back', function (): void {
    $event = Event::factory()->create();
    $valid = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    $inside = app(EventRegistrar::class)->register($event, 'Beto', 'beto@example.com');
    app(TicketCheckin::class)->checkInTicket($inside['ticket'], $event->organizer);
    $released = app(EventRegistrar::class)->register($event, 'Caro', 'caro@example.com');
    $this->actingAs($event->organizer)->post(route('tickets.release', $released['ticket']))->assertRedirect();

    $this->actingAs($event->organizer)->post(route('tickets.restore', $valid['ticket']))->assertSessionHasNoErrors();
    $this->actingAs($event->organizer)->post(route('tickets.restore', $inside['ticket']))->assertSessionHasNoErrors();
    expect($valid['ticket']->fresh()->status)->toBe(TicketStatus::Valid)
        ->and($inside['ticket']->fresh()->status)->toBe(TicketStatus::CheckedIn);

    $event->forceFill(['status' => EventStatus::Cancelled->value])->save();
    $this->actingAs($event->organizer)->post(route('tickets.restore', $released['ticket']))
        ->assertRedirect()->assertSessionHasErrors('restore');
    expect($released['ticket']->fresh()->status)->toBe(TicketStatus::Revoked);
    // The list neither offers it nor gives a wrong reason («Sin cupo») on a cancelled event.
    $this->actingAs($event->organizer)->get(route('events.registrations', $event))
        ->assertDontSee(route('tickets.restore', $released['ticket']), false)
        ->assertDontSee('data-no-room', false);
});

it('AC-COLLAB-20: a released ticket reads «Lugar liberado» in the list, and the door says so too', function (): void {
    $event = Event::factory()->create();
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    $this->actingAs($event->organizer)->post(route('tickets.release', $issued['ticket']))->assertRedirect();

    $list = (string) $this->actingAs($event->organizer)->get(route('events.registrations', $event))->assertOk()->getContent();
    preg_match('/<span class="whitespace-nowrap" data-ticket-status>([^<]*)<\/span>/', $list, $status);
    expect(trim($status[1] ?? ''))->toBe('Lugar liberado')
        ->and($list)->not->toContain('Revocada');

    // The camera scanner (its labels come from the controller) and the manual check-in flash.
    $this->actingAs($event->organizer)->get(route('events.scan', $event))
        ->assertOk()->assertSee('Lugar liberado: ya no sirve para entrar');
    $this->actingAs($event->organizer)->postJson(route('events.checkin', $event), ['token' => $issued['token']])
        ->assertOk()->assertJsonPath('result', 'revoked')->assertJsonPath('name', 'Ana');
    $this->actingAs($event->organizer)->withSession(['checkin' => 'revoked'])->get(route('events.registrations', $event))
        ->assertSee('✗ Lugar liberado: ya no sirve para entrar', false);
});

it('AC-COLLAB-21: a released email that registers again is told its spot was released — not «already registered», and no mail', function (): void {
    $event = Event::factory()->create();
    $ana = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    $beto = app(EventRegistrar::class)->register($event, 'Beto', 'beto@example.com');
    $this->actingAs($event->organizer)->post(route('tickets.release', $ana['ticket']))->assertRedirect();
    auth()->logout();
    Mail::fake();

    $this->post(route('public.register', $event), ['name' => 'Ana', 'email' => 'ana@example.com'])
        ->assertRedirect()
        ->assertSessionHas('status', 'La persona organizadora liberó el lugar de este email. Si crees que es un error, habla con quien organiza el evento.');
    expect($ana['ticket']->fresh()->status)->toBe(TicketStatus::Revoked);

    // A ticket that was not released keeps the usual answer.
    $this->post(route('public.register', $event), ['name' => 'Beto', 'email' => 'beto@example.com'])
        ->assertSessionHas('status', 'Ya estás registrado con ese email. Pide que te reenviemos tu entrada si no la encuentras.');
    expect($beto['ticket']->fresh()->status)->toBe(TicketStatus::Valid);
    Mail::assertNothingQueued();
});
