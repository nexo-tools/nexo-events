<x-guest-layout>
    <h1 class="mb-1 text-xl font-bold">{{ __('Tu entrada') }}</h1>
    <p class="mb-4 text-sm text-slate-500">{{ $ticket->event->title }} · {{ $ticket->event->starts_at->format('d/m/Y H:i') }}</p>

    <div class="mb-4 flex justify-center rounded-lg bg-white p-4">
        {!! app(\App\Services\QrSvg::class)->forUrl($token) !!}
    </div>

    <p class="text-center text-sm text-slate-600 dark:text-slate-400">{{ $ticket->attendee_name }}</p>
    <p class="text-center text-xs text-slate-400">{{ __('Mostrá este QR en la puerta.') }}</p>

    @if ($ticket->status->value === 'checked_in')
        <p class="mt-4 rounded-lg bg-green-100 px-4 py-3 text-center text-sm text-green-900">{{ __('Ya ingresaste a este evento.') }}</p>
    @endif
</x-guest-layout>
