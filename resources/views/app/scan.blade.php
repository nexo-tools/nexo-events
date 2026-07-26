<x-guest-layout>
    <h1 class="mb-1 text-xl font-bold">{{ __('Check-in en puerta') }}</h1>
    <p class="mb-4 text-sm text-slate-500">{{ $event->title }}</p>

    @if (session('checkin'))
        @php($r = session('checkin'))
        <div @class([
            'mb-4 rounded-lg px-4 py-3 text-sm font-medium',
            'bg-green-100 text-green-900' => $r === 'ok',
            'bg-red-100 text-red-900' => $r !== 'ok',
        ]) role="status">
            @switch($r)
                @case('ok') ✓ {{ __('Ingreso válido') }} @if(session('ticketName')) — {{ session('ticketName') }} @endif @break
                @case('already') ✗ {{ __('Entrada ya usada') }} @break
                @case('revoked') ✗ {{ __('Entrada revocada') }} @break
                @case('event_inactive') ✗ {{ __('Evento cancelado') }} @break
                @default ✗ {{ __('Entrada no válida') }}
            @endswitch
        </div>
    @endif

    {{--
        Camera scanning. Every control here starts hidden and is revealed by
        scanner.js only once it knows a camera API exists — a visitor without
        JavaScript, or on a browser without getUserMedia, never sees a button
        that cannot work, and drops straight to the manual form below.
        Result labels are passed in already translated, so the JS needs no
        translation layer of its own.
    --}}

    <div
        data-scanner
        data-scanner-endpoint="{{ route('events.checkin', $event) }}"
        data-scanner-labels='@json($scannerLabels)'
    >
        <div data-scanner-controls class="hidden">
            <button type="button" data-scanner-start
                class="inline-flex items-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                {{ __('Escanear con la cámara') }}
            </button>
            <button type="button" data-scanner-stop hidden
                class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm dark:border-slate-600">
                {{ __('Apagar la cámara') }}
            </button>

            <video data-scanner-video hidden muted playsinline
                class="mt-3 w-full max-w-sm rounded-lg bg-black"></video>

            <div data-scanner-result hidden role="status" aria-live="polite"></div>
        </div>
    </div>

    {{-- The fallback that always works: broken QR, dead battery, denied camera. --}}
    <form method="POST" action="{{ route('events.checkin', $event) }}" class="mt-6 space-y-3" data-checkin-form>
        @csrf
        <label for="checkin-token" class="block text-sm font-medium">{{ __('Código de la entrada') }}</label>
        <input id="checkin-token" name="token" autocomplete="off" class="block w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800">
        <x-button>{{ __('Validar') }}</x-button>
    </form>

    <a href="{{ route('events.registrations', $event) }}" class="mt-4 block text-sm text-slate-500 hover:underline">{{ __('Ver registrados') }}</a>
</x-guest-layout>
