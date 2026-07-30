<x-app-layout>
    <h1 class="mb-1 text-xl font-bold">{{ __('Check-in en puerta') }}</h1>
    <p class="mb-4 text-sm text-muted">{{ $event->title }}</p>

    @if (session('checkin'))
        @include('app._checkin-flash', ['result' => session('checkin')])
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
            <button type="button" data-scanner-start class="nexo-btn nexo-btn--primary">
                {{ __('Escanear con la cámara') }}
            </button>
            <button type="button" data-scanner-stop hidden class="nexo-btn nexo-btn--ghost">
                {{ __('Apagar la cámara') }}
            </button>

            {{-- bg-black, not a surface token: this is the camera viewport, and it
                 stays black in both themes so the video is the only thing lit. --}}
            <video data-scanner-video hidden muted playsinline
                class="mt-3 w-full max-w-sm rounded-lg bg-black"></video>

            <div data-scanner-result hidden role="status" aria-live="polite"></div>
        </div>
    </div>

    {{-- The fallback that always works: broken QR, dead battery, denied camera. --}}
    <form method="POST" action="{{ route('events.checkin', $event) }}" class="mt-6 space-y-3" data-checkin-form>
        @csrf
        <label for="checkin-token" class="block text-sm font-medium">{{ __('Código de la entrada') }}</label>
        <input id="checkin-token" name="token" autocomplete="off"
               class="block w-full rounded-lg border-control bg-surface text-ink focus:border-ring focus:ring-ring">
        <x-button>{{ __('Validar') }}</x-button>
    </form>

    <a href="{{ route('events.registrations', $event) }}" class="mt-4 block text-sm text-muted hover:underline">{{ __('Ver registrados') }}</a>
</x-app-layout>
