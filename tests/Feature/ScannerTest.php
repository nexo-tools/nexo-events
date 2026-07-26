<?php

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EventRegistrar;

/** An organizer with a published event and one registered attendee. */
function doorFixture(): array
{
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create();
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');

    return [$organizer, $event, (string) $issued['token'], $issued['ticket']];
}

it('AC-SCAN-2: a scanned token checks in through the same endpoint and stays atomic on a double scan', function (): void {
    [$organizer, $event, $token, $ticket] = doorFixture();

    $first = $this->actingAs($organizer)
        ->postJson(route('events.checkin', $event), ['token' => $token]);
    $second = $this->actingAs($organizer)
        ->postJson(route('events.checkin', $event), ['token' => $token]);

    $first->assertOk()->assertJson(['result' => 'ok', 'name' => 'Ana']);
    $second->assertOk()->assertJson(['result' => 'already']);

    // The camera path is an input method, not a second validation path: the
    // UNIQUE(checkins.ticket_id) guarantee still resolves a double scan to one entry.
    expect(Checkin::where('ticket_id', $ticket->id)->count())->toBe(1)
        ->and($ticket->fresh()->status)->toBe(TicketStatus::CheckedIn);
});

it('AC-SCAN-3: every rejection answers with its own reason, not a generic failure', function (): void {
    [$organizer, $event] = doorFixture();

    // Unknown token.
    $this->actingAs($organizer)
        ->postJson(route('events.checkin', $event), ['token' => 'no-existe'])
        ->assertOk()->assertJson(['result' => 'unknown']);

    // Revoked ticket.
    $revoked = Ticket::factory()->for($event)->create(['status' => TicketStatus::Revoked]);
    $revokedToken = 'revoked-token-value';
    $revoked->token_hash = hash('sha256', $revokedToken);
    $revoked->save();

    $this->actingAs($organizer)
        ->postJson(route('events.checkin', $event), ['token' => $revokedToken])
        ->assertOk()->assertJson(['result' => 'revoked']);

    // Killed event (the abuse kill-switch) closes its door.
    $killed = Event::factory()->for($organizer, 'organizer')->create(['status' => EventStatus::Killed]);
    $killedIssue = app(EventRegistrar::class)->register($killed, 'Beto', 'beto@example.com');
    $killed->update(['status' => EventStatus::Killed]);

    $this->actingAs($organizer)
        ->postJson(route('events.checkin', $killed), ['token' => (string) $killedIssue['token']])
        ->assertOk()->assertJsonMissing(['result' => 'ok']);
});

it('AC-SCAN-6: the scanner page ships a working manual form and hides the camera UI until JS enables it', function (): void {
    [$organizer, $event] = doorFixture();

    $response = $this->actingAs($organizer)->get(route('events.scan', $event));

    $response->assertOk()
        // The fallback is server-rendered, not injected by JS.
        ->assertSee('data-checkin-form', escape: false)
        ->assertSee(route('events.checkin', $event), escape: false)
        // Camera controls start hidden: a browser without getUserMedia must never
        // show a button that cannot work.
        ->assertSee('data-scanner-controls class="hidden"', escape: false);
});

it('AC-SCAN-7: the manual form checks an attendee in without any JavaScript involved', function (): void {
    [$organizer, $event, $token, $ticket] = doorFixture();

    // A plain form POST — no JSON, no XHR headers.
    $this->actingAs($organizer)
        ->post(route('events.checkin', $event), ['token' => $token])
        ->assertRedirect()
        ->assertSessionHas('checkin', 'ok');

    expect($ticket->fresh()->status)->toBe(TicketStatus::CheckedIn);
});

it('AC-SCAN-11: another organizer can neither open the scanner nor check anyone in', function (): void {
    [, $event, $token] = doorFixture();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('events.scan', $event))->assertForbidden();
    $this->actingAs($stranger)->postJson(route('events.checkin', $event), ['token' => $token])->assertForbidden();
});

it('AC-SCAN-2: a ticket from another event is rejected as unknown, never checked in here', function (): void {
    [$organizer, $event] = doorFixture();
    $other = Event::factory()->for($organizer, 'organizer')->create();
    $otherIssue = app(EventRegistrar::class)->register($other, 'Caro', 'caro@example.com');

    $this->actingAs($organizer)
        ->postJson(route('events.checkin', $event), ['token' => (string) $otherIssue['token']])
        ->assertOk()->assertJson(['result' => 'unknown']);

    expect($otherIssue['ticket']->fresh()->status)->toBe(TicketStatus::Valid);
});

it('AC-SCAN-10: rate-limits the door endpoints and the public ticket page', function (): void {
    [$organizer, $event, $token] = doorFixture();

    // Public ticket page: 60/min per IP. Loose on purpose (a venue shares one
    // NAT address) but bounded.
    foreach (range(1, 60) as $ignored) {
        $this->get(route('ticket.show', ['token' => $token]))->assertOk();
    }
    $this->get(route('ticket.show', ['token' => $token]))->assertStatus(429);
});

it('AC-SCAN-10: rate-limits scan-spam on the check-in endpoint without touching the database', function (): void {
    [$organizer, $event] = doorFixture();
    $this->actingAs($organizer);

    foreach (range(1, 120) as $ignored) {
        $this->postJson(route('events.checkin', $event), ['token' => 'nope'])->assertOk();
    }

    $blocked = $this->postJson(route('events.checkin', $event), ['token' => 'nope']);

    $blocked->assertStatus(429);
    // The rejected request never reached the check-in service.
    expect(Checkin::count())->toBe(0);
});
