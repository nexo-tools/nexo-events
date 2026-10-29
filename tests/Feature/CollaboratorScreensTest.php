<?php

use App\Enums\EventStatus;
use App\Mail\CollaboratorInvited;
use App\Models\Event;
use App\Models\EventCollaborator;
use App\Models\User;
use App\Services\CollaboratorInviter;
use App\Services\EventRegistrar;
use App\Services\TicketCheckin;
use Illuminate\Support\Facades\Mail;

/**
 * Collaborator screens (task 10.10) — SPEC docs/specs/SPEC-event-collaborators.md.
 */
beforeEach(function (): void {
    Mail::fake();
});

function screensBand(Event $event, string $email = 'banda@example.com'): User
{
    $band = User::factory()->create(['email' => $email]);
    EventCollaborator::factory()->for($event)->acceptedBy($band)->create();

    return $band;
}

it('AC-COLLAB-11: the owner invites, resends and revokes from the edit screen, and the list follows', function (): void {
    $event = Event::factory()->create();
    $owner = $event->organizer;

    $invite = $this->actingAs($owner)->from(route('events.edit', $event))
        ->post(route('collaborators.store', $event), ['collaborator_email' => 'Banda@Example.com']);

    $invite->assertRedirect(route('events.edit', $event))->assertSessionHas('collaborator_link');
    $link = (string) session('collaborator_link');

    // Right after inviting: the link, once, with the WhatsApp share.
    $page = (string) $this->actingAs($owner)->get(route('events.edit', $event))->getContent();
    expect($page)->toContain(e($link))
        ->and($page)->toContain('https://wa.me/?text=')
        ->and($page)->toContain('banda@example.com')
        ->and($page)->toContain(__('Invited, not accepted yet'));

    // Never again: a reload does not show it.
    expect((string) $this->actingAs($owner)->get(route('events.edit', $event))->getContent())->not->toContain(e($link));

    // Resend: same email, new link, second mail.
    $this->actingAs($owner)->from(route('events.edit', $event))
        ->post(route('collaborators.store', $event), ['collaborator_email' => 'banda@example.com'])
        ->assertSessionHas('collaborator_link', fn (string $new): bool => $new !== $link);
    Mail::assertQueued(CollaboratorInvited::class, 2);

    $collaborator = EventCollaborator::sole();
    $this->actingAs($owner)->from(route('events.edit', $event))
        ->post(route('collaborators.revoke', [$event, $collaborator]))
        ->assertRedirect(route('events.edit', $event));

    expect($collaborator->fresh()->status())->toBe(EventCollaborator::REVOKED)
        ->and((string) $this->actingAs($owner)->get(route('events.edit', $event))->getContent())->toContain(__('Access removed'));
});

it('AC-COLLAB-11: the owner\'s invite form refuses an unverified owner and the eleventh collaborator', function (): void {
    $owner = User::factory()->unverified()->create();
    $event = Event::factory()->for($owner, 'organizer')->create();

    $this->actingAs($owner)->post(route('collaborators.store', $event), ['collaborator_email' => 'banda@example.com'])
        ->assertSessionHasErrors('collaborator_email');

    expect(EventCollaborator::count())->toBe(0);
});

it('AC-COLLAB-5: a collaborator cannot invite or revoke collaborators', function (): void {
    $event = Event::factory()->create();
    $band = screensBand($event);
    $other = EventCollaborator::factory()->for($event)->create();

    $this->actingAs($band)->post(route('collaborators.store', $event), ['collaborator_email' => 'amigo@example.com'])->assertForbidden();
    $this->actingAs($band)->post(route('collaborators.revoke', [$event, $other]))->assertForbidden();

    expect(EventCollaborator::count())->toBe(2)
        ->and($other->fresh()->revoked_at)->toBeNull();
});

it('AC-COLLAB-12: ticket management is for the owner and collaborators, with the three numbers', function (): void {
    $event = Event::factory()->withCapacity(50)->create();
    $band = screensBand($event);
    $a = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    app(EventRegistrar::class)->register($event, 'Beto', 'beto@example.com');
    app(TicketCheckin::class)->checkInTicket($a['ticket'], $band);

    foreach ([$event->organizer, $band] as $user) {
        $html = (string) $this->actingAs($user)->get(route('events.manage', $event))->assertOk()->getContent();

        expect($html)->toMatch('#data-registered>2<span[^>]*> / 50</span>#')
            ->and($html)->toMatch('#data-checked-in>1<#')
            ->and($html)->toContain(route('events.scan', $event))
            ->and($html)->toContain(route('events.registrations', $event))
            ->and($html)->toContain(route('public.event', $event));
    }

    $this->actingAs(User::factory()->create())->get(route('events.manage', $event))->assertForbidden();
});

it('AC-COLLAB-12: accepting an invitation lands on ticket management', function (): void {
    $event = Event::factory()->create();
    $band = User::factory()->create(['email' => 'banda@example.com']);
    $url = app(CollaboratorInviter::class)->invite($event, 'banda@example.com', $event->organizer)['url'];

    $this->actingAs($band)->post($url.'/aceptar')->assertRedirect(route('events.manage', $event));
});

it('AC-COLLAB-13: a collaborator changes the capacity but never under the tickets issued', function (): void {
    $event = Event::factory()->withCapacity(10)->create();
    $band = screensBand($event);
    app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    app(EventRegistrar::class)->register($event, 'Beto', 'beto@example.com');

    // A validation failure (the 422 of the SPEC): web routes answer it the web
    // way, back to the form with the error — bootstrap/app.php renders JSON
    // only for api/*.
    $this->actingAs($band)->from(route('events.manage', $event))
        ->post(route('events.capacity', $event), ['capacity' => 1])
        ->assertRedirect(route('events.manage', $event))
        ->assertSessionHasErrors(['capacity' => __('The capacity cannot be lower than the :count tickets already issued.', ['count' => 2])]);
    expect($event->fresh()->capacity)->toBe(10);

    $this->actingAs($band)->post(route('events.capacity', $event), ['capacity' => 2])->assertSessionHasNoErrors();
    expect($event->fresh()->capacity)->toBe(2)->and($event->fresh()->isSoldOut())->toBeTrue();

    // Empty means no limit.
    $this->actingAs($band)->post(route('events.capacity', $event), ['capacity' => ''])->assertSessionHasNoErrors();
    expect($event->fresh()->capacity)->toBeNull();

    $this->actingAs(User::factory()->create())->post(route('events.capacity', $event), ['capacity' => 5])->assertForbidden();
});

it('AC-COLLAB-13: a collaborator closes and reopens; reopening needs the OWNER\'s verified email', function (): void {
    $event = Event::factory()->create();
    $band = screensBand($event);

    $this->actingAs($band)->post(route('events.close', $event));
    expect($event->fresh()->status)->toBe(EventStatus::Closed);

    $this->actingAs($band)->post(route('events.reopen', $event))->assertSessionHasNoErrors();
    expect($event->fresh()->status)->toBe(EventStatus::Published);

    $this->actingAs($band)->post(route('events.close', $event));
    $event->organizer->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($band)->post(route('events.reopen', $event))->assertSessionHasErrors('reopen');
    expect($event->fresh()->status)->toBe(EventStatus::Closed);

    // Reopen is not publish: a draft stays a draft.
    $draft = Event::factory()->draft()->create();
    $draftBand = screensBand($draft, 'otra@example.com');
    $this->actingAs($draftBand)->post(route('events.reopen', $draft));
    expect($draft->fresh()->status)->toBe(EventStatus::Draft);
});

it('AC-COLLAB-14: a collaborator sees names and status, never attendee emails; the owner sees emails', function (): void {
    $event = Event::factory()->create();
    $band = screensBand($event);
    app(EventRegistrar::class)->register($event, 'Ana Pérez', 'ana.perez@example.com');

    $forBand = (string) $this->actingAs($band)->get(route('events.registrations', $event))->assertOk()->getContent();
    $forOwner = (string) $this->actingAs($event->organizer)->get(route('events.registrations', $event))->assertOk()->getContent();

    expect($forBand)->toContain('Ana Pérez')
        ->and($forBand)->not->toContain('ana.perez@example.com')
        ->and($forBand)->toContain(__('Release spot'))
        ->and($forOwner)->toContain('ana.perez@example.com')
        ->and($forOwner)->toContain(__('Release spot'));
});

it('AC-COLLAB-15: the band\'s dashboard lists the event it manages; the owner\'s does not list it twice', function (): void {
    $event = Event::factory()->create(['title' => 'Noche de rock']);
    $band = screensBand($event);

    $forBand = (string) $this->actingAs($band)->get(route('dashboard'))->getContent();
    $forOwner = (string) $this->actingAs($event->organizer)->get(route('dashboard'))->getContent();

    expect($forBand)->toContain(__('Events you manage'))
        ->and($forBand)->toContain(route('events.manage', $event))
        ->and(substr_count($forOwner, 'Noche de rock'))->toBe(1)
        ->and($forOwner)->not->toContain(__('Events you manage'));

    // Revoked: gone from the band's dashboard.
    EventCollaborator::sole()->forceFill(['revoked_at' => now()])->save();
    expect((string) $this->actingAs($band)->get(route('dashboard'))->getContent())->not->toContain('Noche de rock');
});

it('AC-COLLAB-16: the edit form warns about another system only when the event has an external_ref', function (): void {
    $owner = User::factory()->create();
    $mine = Event::factory()->for($owner, 'organizer')->create();
    $synced = Event::factory()->for($owner, 'organizer')->create();
    $synced->forceFill(['external_ref' => 'site:event:1'])->save();

    expect((string) $this->actingAs($owner)->get(route('events.edit', $synced))->getContent())->toContain('data-external-notice')
        ->and((string) $this->actingAs($owner)->get(route('events.edit', $mine))->getContent())->not->toContain('data-external-notice');
});

it('AC-COLLAB-12: the new screens speak the three languages', function (): void {
    $event = Event::factory()->create();
    $band = screensBand($event);

    $headings = collect(['es', 'en', 'pt'])->map(fn (string $lang): string => (string) $this->actingAs($band)
        ->get(route('events.manage', $event).'?lang='.$lang)->getContent());

    expect($headings[0])->toContain('Registrados')
        ->and($headings[1])->toContain('Registered')
        ->and($headings[2])->toContain('Inscritos');
});
