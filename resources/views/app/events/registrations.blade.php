<x-app-layout>
    <h1 class="mb-1 text-xl font-bold">{{ __('Registrations') }}</h1>
    <p class="mb-4 text-sm text-muted">{{ $event->title }} · {{ trans_choice('app.tickets', $tickets->count()) }}</p>

    @if (session('checkin'))
        @include('app._checkin-flash', ['result' => session('checkin')])
    @endif

    <ul class="space-y-2">
        @forelse ($tickets as $ticket)
            <li class="flex items-center justify-between gap-3 rounded-lg border border-line p-3">
                <span>
                    <span class="font-medium">{{ $ticket->attendee_name }}</span>
                    <span class="block text-xs text-muted">{{ $ticket->attendee_email }} · {{ $ticket->status->label() }}</span>
                </span>
                @if ($ticket->checkin === null && $ticket->status->value === 'valid')
                    <form method="POST" action="{{ route('tickets.checkin', $ticket) }}">@csrf
                        <x-button variant="secondary" class="nexo-btn--sm" :block="false">{{ __('Check in') }}</x-button>
                    </form>
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

    <a href="{{ route('events.edit', $event) }}" class="mt-4 block text-sm text-muted hover:underline">{{ __('Back') }}</a>
</x-app-layout>
