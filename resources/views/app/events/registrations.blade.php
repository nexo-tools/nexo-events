<x-guest-layout :noindex="true">
    <h1 class="mb-1 text-xl font-bold">{{ __('Registrados') }}</h1>
    <p class="mb-4 text-sm text-slate-500">{{ $event->title }} · {{ $tickets->count() }} {{ __('entradas') }}</p>

    @if (session('checkin'))
        <p class="mb-4 rounded-lg bg-brand-100 px-4 py-3 text-sm text-brand-900" role="status">{{ __('Check-in') }}: {{ session('checkin') }}</p>
    @endif

    <ul class="space-y-2">
        @forelse ($tickets as $ticket)
            <li class="flex items-center justify-between rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                <span>
                    <span class="font-medium">{{ $ticket->attendee_name }}</span>
                    <span class="block text-xs text-slate-500">{{ $ticket->attendee_email }} · {{ $ticket->status->value }}</span>
                </span>
                @if ($ticket->checkin === null && $ticket->status->value === 'valid')
                    <form method="POST" action="{{ route('tickets.checkin', $ticket) }}">@csrf
                        <button class="rounded-lg border border-slate-300 px-3 py-1 text-sm dark:border-slate-600">{{ __('Marcar ingreso') }}</button>
                    </form>
                @else
                    <span class="text-xs text-green-600">{{ __('Ingresó') }}</span>
                @endif
            </li>
        @empty
            <li class="text-sm text-slate-600 dark:text-slate-400">{{ __('Nadie se registró todavía.') }}</li>
        @endforelse
    </ul>

    <a href="{{ route('events.edit', $event) }}" class="mt-4 block text-sm text-slate-500 hover:underline">{{ __('Volver') }}</a>
</x-guest-layout>
