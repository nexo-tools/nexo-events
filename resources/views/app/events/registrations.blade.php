<x-app-layout>
    <h1 class="mb-1 text-xl font-bold">{{ __('Registrados') }}</h1>
    <p class="mb-4 text-sm text-muted">{{ $event->title }} · {{ $tickets->count() }} {{ __('entradas') }}</p>

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
                        <x-button variant="secondary" class="nexo-btn--sm" :block="false">{{ __('Marcar ingreso') }}</x-button>
                    </form>
                @else
                    <span class="text-xs font-medium text-success-subtle-fg">{{ __('Ingresó') }}</span>
                @endif
            </li>
        @empty
            <li class="text-sm text-muted">{{ __('Nadie se registró todavía.') }}</li>
        @endforelse
    </ul>

    <a href="{{ route('events.edit', $event) }}" class="mt-4 block text-sm text-muted hover:underline">{{ __('Volver') }}</a>
</x-app-layout>
