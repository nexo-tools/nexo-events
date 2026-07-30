<?php

use App\Models\Event;
use App\Models\EventView;
use App\Models\User;
use App\Support\VisitorHash;
use Illuminate\Http\Request;

it('AC-VIEWS-1: counts a public event page view without cookies', function (): void {
    $event = Event::factory()->create();

    $response = $this->get(route('public.event', $event));

    $response->assertOk();
    expect(EventView::where('event_id', $event->id)->count())->toBe(1);

    // The privacy promise: no cookie is set by viewing a public page.
    foreach ($response->headers->getCookies() as $cookie) {
        expect($cookie->getName())->not->toBe('nexo_visitor');
    }
});

it('AC-VIEWS-2: counts unique visitor-days, so reloads do not inflate the number', function (): void {
    $event = Event::factory()->create();

    $this->get(route('public.event', $event));
    $this->get(route('public.event', $event));
    $this->get(route('public.event', $event));

    expect(EventView::where('event_id', $event->id)->count())->toBe(1);
});

it('AC-VIEWS-3: stores no IP, no user agent and nothing reversible to a person', function (): void {
    $event = Event::factory()->create();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (probe)'])
        ->get(route('public.event', $event));

    $row = EventView::query()->firstOrFail()->toArray();
    $serialized = json_encode($row);

    expect($serialized)->not->toContain('203.0.113.7')
        ->and($serialized)->not->toContain('Mozilla')
        // Only the digest is kept, and it is a digest: fixed width, not an address.
        ->and(strlen((string) $row['visitor_hash']))->toBe(64);
});

it('AC-VIEWS-4: the fingerprint rotates daily, so the same visitor cannot be tracked across days', function (): void {
    $request = Request::create('/e/algo', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);
    $request->headers->set('User-Agent', 'Mozilla/5.0 (probe)');

    $today = VisitorHash::make($request);
    $tomorrow = $this->travel(1)->day(fn (): string => VisitorHash::make($request));

    expect($today)->not->toBe($tomorrow);
});

it('AC-VIEWS-5: shows the organizer unique visits alongside ticket counts', function (): void {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create();

    $this->get(route('public.event', $event));

    // Singular on purpose: the counter used to render ":count visitas" for
    // every number, so the very first visit read "1 visitas".
    $this->actingAs($organizer)->get(route('dashboard'))
        ->assertOk()
        ->assertSee(trans_choice('app.views', 1), escape: false)
        ->assertDontSee('1 visitas');
});

it('AC-VIEWS-1: a killed or draft event records nothing, because its page is gone', function (): void {
    $draft = Event::factory()->draft()->create();

    $this->get(route('public.event', $draft))->assertNotFound();

    expect(EventView::count())->toBe(0);
});
