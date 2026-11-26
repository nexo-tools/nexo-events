<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Services\EventLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The event's summary (SPEC-event-panel; "Ticket management" in ADR-011): the
 * screen every event opens on, for the owner and for a collaborator. Capacity
 * and the open/closed state are the two settings a collaborator may change;
 * content stays with the owner, and so do the collaborators themselves.
 */
class EventManagementController extends Controller
{
    public function __construct(private readonly EventLifecycle $lifecycle) {}

    public function show(Request $request, Event $event): View
    {
        $this->managed($request, $event);

        $isOwner = $event->organizer_id === $request->user()->id;

        return view('app.events.manage', [
            'event' => $event,
            'issued' => $event->issuedTicketsCount(),
            'checkedIn' => $event->checkedInCount(),
            'isOwner' => $isOwner,
            // The collaborator list is the owner's (ADR-011): a collaborator's
            // summary never loads it, not just never shows it.
            'collaborators' => $isOwner ? $event->collaborators()->latest('invited_at')->get() : collect(),
        ]);
    }

    public function capacity(Request $request, Event $event): RedirectResponse
    {
        $this->managed($request, $event);

        $issued = $event->issuedTicketsCount();

        $validated = $request->validate([
            'capacity' => [
                'nullable', 'integer', 'min:1', 'max:1000000',
                // Lowering it under the tickets already out would not un-issue
                // anybody's ticket — it would only make the counters lie.
                function (string $attribute, mixed $value, \Closure $fail) use ($issued): void {
                    if ($value !== null && (int) $value < $issued) {
                        $fail(__('The capacity cannot be lower than the :count tickets already issued.', ['count' => $issued]));
                    }
                },
            ],
        ]);

        $event->update(['capacity' => $validated['capacity'] ?? null]);

        return back()->with('status', __('Capacity updated.'));
    }

    /**
     * Reopening is publishing from `closed`, with the OWNER's verified email as
     * the gate (ADR-007 §1): the collaborator is acting on the owner's event.
     * Publishing a draft stays owner-only (EventController::publish).
     */
    public function reopen(Request $request, Event $event): RedirectResponse
    {
        $this->managed($request, $event);

        if ($event->status !== EventStatus::Closed) {
            return back();
        }

        if ($this->lifecycle->publish($event->organizer, $event) === EventLifecycle::UNVERIFIED) {
            return back()->withErrors([
                'reopen' => __('The owner of this event has to verify their email before registration can reopen.'),
            ]);
        }

        return back()->with('status', __('Registration reopened.'));
    }

    private function managed(Request $request, Event $event): void
    {
        abort_unless($event->canBeManagedBy($request->user()), 403);
    }
}
