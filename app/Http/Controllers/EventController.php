<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Http\Requests\EventRequest;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventController extends Controller
{
    public function create(): View
    {
        return view('app.events.create');
    }

    public function store(EventRequest $request): RedirectResponse
    {
        $event = new Event($request->validated());
        $event->slug = Event::uniqueSlugFor($request->string('title')->toString());
        $event->status = EventStatus::Draft;
        $request->user()->events()->save($event);

        return redirect()->route('events.edit', $event)->with('status', __('Evento creado.'));
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

        return redirect()->route('events.edit', $event)->with('status', __('Evento actualizado.'));
    }

    public function publish(Request $request, Event $event): RedirectResponse
    {
        $this->owned($request, $event);

        // Publishing is the one action that puts content under this instance's
        // public domain, so it is the one gated on a verified email — the
        // cheapest effective brake on drive-by spam events (ADR-007 §1).
        if (! $request->user()->hasVerifiedEmail()) {
            return back()->withErrors([
                'publish' => __('Verificá tu email para poder publicar eventos. Te enviamos el enlace al registrarte.'),
            ]);
        }

        if (in_array($event->status, [EventStatus::Draft, EventStatus::Closed], true)) {
            $event->update(['status' => EventStatus::Published]);
        }

        return back()->with('status', __('Evento publicado.'));
    }

    public function close(Request $request, Event $event): RedirectResponse
    {
        $this->owned($request, $event);

        if ($event->status === EventStatus::Published) {
            $event->update(['status' => EventStatus::Closed]);
        }

        return back()->with('status', __('Registro cerrado.'));
    }

    public function cancel(Request $request, Event $event): RedirectResponse
    {
        $this->owned($request, $event);
        $event->update(['status' => EventStatus::Cancelled]);

        return back()->with('status', __('Evento cancelado.'));
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
