<?php

use App\Enums\EventStatus;
use App\Mail\EventCancelled;
use App\Models\Event;
use App\Models\EventCollaborator;
use App\Models\User;
use App\Services\EventRegistrar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/Api/ApiTestSupport.php';

/**
 * Drafts that can go, and cancelling that never publishes (PLAN 10.19) —
 * SPEC docs/specs/SPEC-event-panel.md (H-nexoevents-07).
 */
beforeEach(function (): void {
    Mail::fake();
});

it('AC-PANEL-4: the owner deletes a draft, and what hangs from it goes too', function (): void {
    $draft = Event::factory()->draft()->create(['title' => '[prueba] usabilidad']);
    $owner = $draft->organizer;
    EventCollaborator::factory()->for($draft)->create();
    DB::table('event_views')->insert(['event_id' => $draft->id, 'visitor_hash' => str_repeat('a', 64), 'viewed_on' => now()->toDateString()]);

    $this->actingAs($owner)->from(route('events.edit', $draft))
        ->delete(route('events.destroy', $draft))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', __('Draft “:title” deleted.', ['title' => '[prueba] usabilidad']));

    expect(Event::find($draft->id))->toBeNull()
        ->and(EventCollaborator::where('event_id', $draft->id)->count())->toBe(0)
        ->and(DB::table('event_views')->where('event_id', $draft->id)->count())->toBe(0);

    $dashboard = (string) $this->actingAs($owner)->get(route('dashboard'))->getContent();
    expect($dashboard)->not->toContain(route('events.manage', $draft->id));
});

it('AC-PANEL-4: nothing but a draft can be deleted, and only by its owner', function (EventStatus $status): void {
    $event = Event::factory()->create(['status' => $status]);

    $this->actingAs($event->organizer)->from(route('events.edit', $event))
        ->delete(route('events.destroy', $event))
        ->assertRedirect(route('events.edit', $event))
        ->assertSessionHasErrors('delete');

    expect($event->fresh())->not->toBeNull()
        ->and($event->fresh()->status)->toBe($status);
})->with([
    'published' => EventStatus::Published,
    'closed' => EventStatus::Closed,
    'cancelled' => EventStatus::Cancelled,
    'blocked' => EventStatus::Killed,
]);

it('AC-PANEL-4: a collaborator and a stranger get 403, and the draft stays', function (): void {
    $draft = Event::factory()->draft()->create();
    $band = User::factory()->create();
    EventCollaborator::factory()->for($draft)->acceptedBy($band)->create();

    $this->actingAs($band)->delete(route('events.destroy', $draft))->assertForbidden();
    $this->actingAs(User::factory()->create())->delete(route('events.destroy', $draft))->assertForbidden();

    expect($draft->fresh())->not->toBeNull();
});

it('AC-PANEL-5: cancelling a draft from the panel leaves it a hidden draft and mails nobody', function (): void {
    $draft = Event::factory()->draft()->create();

    $this->actingAs($draft->organizer)->from(route('events.edit', $draft))
        ->post(route('events.cancel', $draft))
        ->assertRedirect(route('events.edit', $draft))
        ->assertSessionMissing('status');

    expect($draft->fresh()->status)->toBe(EventStatus::Draft);
    $this->get(route('public.event', $draft))->assertNotFound();
    Mail::assertNothingQueued();
});

it('AC-PANEL-5: cancelling a blocked event does not bring its page back', function (): void {
    $event = Event::factory()->create();
    app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    $event->update(['status' => EventStatus::Killed]);
    Mail::fake(); // forget the ticket mail; only what cancelling sends matters

    $this->actingAs($event->organizer)->post(route('events.cancel', $event));

    expect($event->fresh()->status)->toBe(EventStatus::Killed);
    $this->get(route('public.event', $event))->assertNotFound();
    Mail::assertNotQueued(EventCancelled::class);
});

it('AC-PANEL-5: the API cannot publish a draft by cancelling it either', function (): void {
    config(['nexo.api.enabled' => true]);
    $owner = User::factory()->create();
    $draft = Event::factory()->draft()->for($owner, 'organizer')->create();

    $this->postJson('/api/v1/events/'.$draft->id.'/cancel', [], bearer(apiToken($owner)))
        ->assertOk()
        ->assertJsonPath('status', 'draft');

    expect($draft->fresh()->status)->toBe(EventStatus::Draft);
    $this->get(route('public.event', $draft))->assertNotFound();
});

it('AC-PANEL-5: a published or closed event is still cancelled, with its mails', function (EventStatus $status): void {
    $event = Event::factory()->create();
    app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    $event->update(['status' => $status]);

    $this->actingAs($event->organizer)->post(route('events.cancel', $event))
        ->assertSessionHas('status', __('Event cancelled.'));

    expect($event->fresh()->status)->toBe(EventStatus::Cancelled);
    Mail::assertQueued(EventCancelled::class, 1);
})->with([
    'published' => EventStatus::Published,
    'closed' => EventStatus::Closed,
]);

it('AC-PANEL-5: the edit screen offers the one destructive action that fits the state', function (): void {
    $owner = User::factory()->create();
    $screen = fn (EventStatus $status): string => (string) $this->actingAs($owner)
        ->get(route('events.edit', Event::factory()->for($owner, 'organizer')->create(['status' => $status])))
        ->assertOk()->getContent();

    $draft = $screen(EventStatus::Draft);
    expect($draft)->toContain('data-delete-draft')->and($draft)->toContain(__('Delete draft'))
        ->and($draft)->not->toContain('data-cancel-event');

    foreach ([EventStatus::Published, EventStatus::Closed] as $status) {
        $html = $screen($status);
        expect($html)->toContain('data-cancel-event')->and($html)->not->toContain('data-delete-draft');
    }

    foreach ([EventStatus::Cancelled, EventStatus::Killed] as $status) {
        $html = $screen($status);
        expect($html)->not->toContain('data-cancel-event')->and($html)->not->toContain('data-delete-draft');
    }
});

it('AC-PANEL-10: no view carries an inline event handler, which the CSP would refuse to run', function (): void {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        // Mail views never run script anyway; everything else is served
        // under script-src without 'unsafe-inline'.
        if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'emails'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        if (preg_match('/\son[a-z]+\s*=\s*["\']/i', (string) file_get_contents($file->getPathname()))) {
            $offenders[] = str_replace(resource_path('views').'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});

it('AC-PANEL-10: every form that deletes, cancels, releases or revokes asks first', function (): void {
    $event = Event::factory()->create();
    $owner = $event->organizer;
    app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    EventCollaborator::factory()->for($event)->create();
    $draft = Event::factory()->draft()->for($owner, 'organizer')->create();

    // [screen, the opening tag of each destructive form on it]. The draft's
    // delete shares its URL with the edit form's PUT, hence its own marker.
    $pages = [
        [route('events.edit', $event), '#<form[^>]*action="'.preg_quote(route('events.cancel', $event), '#').'"[^>]*>#'],
        [route('events.edit', $draft), '#<form[^>]*data-delete-draft[^>]*>#'],
        [route('events.registrations', $event), '#<form[^>]*action="[^"]*/liberar"[^>]*>#'],
        [route('events.manage', $event), '#<form[^>]*action="[^"]*/revocar"[^>]*>#'],
    ];

    foreach ($pages as [$page, $pattern]) {
        $html = (string) $this->actingAs($owner)->get($page)->assertOk()->getContent();
        preg_match_all($pattern, $html, $forms);

        expect($forms[0])->not->toBeEmpty()
            ->and(collect($forms[0])->every(fn (string $form): bool => str_contains($form, 'data-confirm="')))->toBeTrue("{$page}: a destructive form without data-confirm");
    }

    // The bundle is what asks: the handler lives in app.js, in the capture
    // phase so it runs before the double-submit guard.
    $js = (string) file_get_contents(resource_path('js/app.js'));
    expect($js)->toContain('dataset.confirm')->and($js)->toContain('window.confirm(');
});
