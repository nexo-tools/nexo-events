<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterAttendeeRequest;
use App\Models\Event;
use App\Services\EventRegistrar;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PublicEventController extends Controller
{
    public function show(Event $event): View
    {
        abort_unless($event->status->isPublic(), 404);

        return view('public.event.show', ['event' => $event]);
    }

    public function register(RegisterAttendeeRequest $request, Event $event, EventRegistrar $registrar): RedirectResponse
    {
        abort_unless($event->status->isPublic(), 404);

        $outcome = $registrar->register(
            $event,
            $request->string('name')->toString(),
            $request->string('email')->toString(),
        );

        return match ($outcome['result']) {
            EventRegistrar::OK => redirect()->route('ticket.show', ['token' => $outcome['token']]),
            EventRegistrar::DUPLICATE => back()->with('status', __('Ya estás registrado con ese email; revisa tu correo para tu entrada.')),
            EventRegistrar::SOLD_OUT => back()->withErrors(['email' => __('El evento está agotado.')]),
            default => back()->withErrors(['email' => __('El registro para este evento está cerrado.')]),
        };
    }
}
