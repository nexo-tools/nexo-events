<x-guest-layout :noindex="true">
    <h1 class="mb-1 text-xl font-bold">{{ __('Tu entrada') }}</h1>
    <p class="mb-4 text-sm text-muted">{{ $ticket->event->title }} · {{ $ticket->event->starts_at->translatedFormat(__('app.datetime')) }}</p>

    {{-- The QR keeps its white quiet zone in dark mode too (`dark:bg-white`):
         QrSvg paints black modules, and scanners need that contrast — a QR on a
         dark surface is unreadable at the door. --}}
    <div class="mb-4 flex justify-center rounded-lg bg-white p-4 dark:bg-white">
        {!! app(\App\Services\QrSvg::class)->forUrl($token) !!}
    </div>

    <p class="text-center text-sm text-muted">{{ $ticket->attendee_name }}</p>
    <p class="text-center text-xs text-muted">{{ __('Muestra este QR en la puerta.') }}</p>

    @if ($ticket->status->value === 'checked_in')
        <p class="nexo-flash mt-4 justify-center" role="status">{{ __('Ya ingresaste a este evento.') }}</p>
    @endif
</x-guest-layout>
