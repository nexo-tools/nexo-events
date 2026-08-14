<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventRequest;
use App\Models\Event;
use App\Services\EventCreator;
use App\Services\EventLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventController extends Controller
{
    public function __construct(
        private readonly EventCreator $creator,
        private readonly EventLifecycle $lifecycle,
    ) {}

    public function create(): View
    {
        return view('app.events.create');
    }

    public function store(EventRequest $request): RedirectResponse
    {
        $event = $this->creator->create($request->user(), $request->validated());

        return redirect()->route('events.edit', $event)->with('status', __('Event created.'));
    }

    public function edit(Request $request, Event $event): View
    {
        $this->owned($request, $event);

        return view('app.events.edit', ['event' => $event]);
    }

    public function update(EventRequest $request, Event $event): RedirectResponse
    {
        $this->owned($request, $event);
        $event->update($request->validated());

        return redirect()->route('events.edit', $event)->with('status', __('Event updated.'));
    }

    public function publish(Request $request, Event $event): RedirectResponse
    {
        $this->owned($request, $event);

        if ($this->lifecycle->publish($request->user(), $event) === EventLifecycle::UNVERIFIED) {
            return back()->withErrors([
                'publish' => __('Verify your email to publish events. We sent you the link when you signed up.'),
            ]);
        }

        return back()->with('status', __('Event published.'));
    }

    public function close(Request $request, Event $event): RedirectResponse
    {
        $this->owned($request, $event);
        $this->lifecycle->close($event);

        return back()->with('status', __('Registration closed.'));
    }

    public function cancel(Request $request, Event $event): RedirectResponse
    {
        $this->owned($request, $event);
        $this->lifecycle->cancel($event);

        return back()->with('status', __('Event cancelled.'));
    }

    public function registrations(Request $request, Event $event): View
    {
        $this->owned($request, $event);

        return view('app.events.registrations', [
            'event' => $event,
            'tickets' => $event->tickets()->with('checkin')->latest()->get(),
        ]);
    }

    private function owned(Request $request, Event $event): void
    {
        abort_unless($event->organizer_id === $request->user()->id, 403);
    }
}
