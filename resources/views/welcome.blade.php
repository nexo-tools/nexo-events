<x-guest-layout>
    {{-- No wordmark here: x-nexo-header already prints "Nexo Events" 40px above,
         so the h1 carries the value proposition instead of repeating the name. --}}
    <h1 class="mb-6 text-2xl font-bold">{{ __('Crea eventos gratis y valida entradas con QR en la puerta.') }}</h1>

    <div class="flex flex-wrap gap-3">
        <a href="{{ route('register') }}" class="nexo-btn nexo-btn--primary">{{ __('Crear cuenta') }}</a>
        <a href="{{ route('login') }}" class="nexo-btn nexo-btn--ghost">{{ __('Inicia sesión') }}</a>
    </div>

    <p class="mt-6 text-sm">
        <a href="{{ route('help') }}" class="text-link hover:underline">{{ __('nexo.help.title') }}</a>
    </p>
</x-guest-layout>
