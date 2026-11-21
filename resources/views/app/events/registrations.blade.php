<x-app-layout>
    <h1 class="mb-1 text-xl font-bold">{{ __('Registrations') }}</h1>
    <p class="mb-4 text-sm text-muted">{{ $event->title }} · {{ trans_choice('app.tickets', $tickets->count()) }}</p>

    @if (session('checkin'))
        @include('app._checkin-flash', ['result' => session('checkin')])
    @endif

    @if (session('status'))
        <p class="nexo-flash mb-4" role="status">{{ session('status') }}</p>
    @endif
    @error('release')
        <p class="nexo-flash nexo-flash--danger mb-4" role="alert">{{ $message }}</p>
    @enderror

    <ul class="space-y-2">
        @forelse ($tickets as $ticket)
            <li class="flex items-center justify-between gap-3 rounded-lg border border-line p-3">
                <span>
                    <span class="font-medium">{{ $ticket->attendee_name }}</span>
                    <span class="block text-xs text-muted">@if ($showEmails){{ $ticket->attendee_email }} · @endif{{ $ticket->status->label() }}</span>
                    {{-- PLAN 10.13: the optional phone sits with the email, so
                         it rides the same owner-only condition. Collaborators
                         never get it. --}}
                    @if ($showEmails && $ticket->attendee_phone)
                        @php($phoneLink = \App\Support\AttendeePhone::link($ticket->attendee_phone))
                        <span class="block text-xs text-muted" data-attendee-phone>
                            @if ($phoneLink['whatsapp'])
                                {{ $ticket->attendee_phone }} ·
                                <a href="{{ $phoneLink['href'] }}" target="_blank" rel="noopener noreferrer" class="text-link underline">WhatsApp</a>
                            @else
                                <a href="{{ $phoneLink['href'] }}" class="text-link underline">{{ $ticket->attendee_phone }}</a>
                            @endif
                        </span>
                    @endif
                </span>
                @if ($ticket->checkin === null && $ticket->status->value === 'valid')
                    <span class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('tickets.checkin', $ticket) }}">@csrf
                            <x-button variant="secondary" class="nexo-btn--sm" :block="false">{{ __('Check in') }}</x-button>
                        </form>
                        {{-- ADR-011 §6: the reservation that was never paid. --}}
                        <form method="POST" action="{{ route('tickets.release', $ticket) }}"
                              onsubmit="return confirm(@js(__('Release the spot of :name? Their ticket stops working at the door.', ['name' => $ticket->attendee_name])))">@csrf
                            <x-button variant="secondary" class="nexo-btn--sm" :block="false">{{ __('Release spot') }}</x-button>
                        </form>
                    </span>
                @elseif ($ticket->status->value === 'revoked')
                    <span class="text-xs font-medium text-muted">{{ __('Spot released') }}</span>
                @else
                    <span class="text-xs font-medium text-success-subtle-fg">{{ __('Checked in') }}</span>
                @endif
            </li>
        @empty
            <li class="rounded-lg border border-dashed border-line p-6 text-center">
                <p class="text-sm font-medium">{{ __('No one has registered yet.') }}</p>
                <p class="mt-1 text-sm text-muted">{{ __('Share the event\'s public page so people can start registering.') }}</p>
                <p class="mt-2 text-sm">
                    <a href="{{ route('public.event', $event) }}" class="break-all text-link hover:underline">{{ route('public.event', $event) }}</a>
                </p>
            </li>
        @endforelse
    </ul>

    <a href="{{ $showEmails ? route('events.edit', $event) : route('events.manage', $event) }}" class="mt-4 block text-sm text-muted hover:underline">{{ __('Back') }}</a>
</x-app-layout>
