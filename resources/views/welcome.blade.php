<x-guest-layout>
    <h1 class="mb-2 text-2xl font-bold">Nexo Events</h1>
    <p class="mb-6 text-sm text-slate-600 dark:text-slate-400">{{ __('Crea eventos gratis y valida entradas con QR en la puerta.') }}</p>

    <div class="flex gap-3">
        <a href="{{ route('register') }}" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">{{ __('Crear cuenta') }}</a>
        <a href="{{ route('login') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-800">{{ __('Inicia sesión') }}</a>
    </div>
</x-guest-layout>
