<x-guest-layout :noindex="true">
    <h1 class="mb-2 text-xl font-bold">{{ __('Verificá tu email') }}</h1>

    <p class="mb-4 text-sm text-slate-600 dark:text-slate-400">
        {{ __('Te enviamos un enlace a :email. Abrilo para poder publicar tus eventos; mientras tanto podés crear y editar borradores.', ['email' => auth()->user()->email]) }}
    </p>

    @if (session('status'))
        <p class="mb-4 rounded-lg bg-brand-100 px-4 py-3 text-sm text-brand-900" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <x-button>{{ __('Reenviar el enlace') }}</x-button>
    </form>

    <p class="mt-6 text-sm">
        <a href="{{ route('dashboard') }}" class="text-brand-700 underline dark:text-brand-400">{{ __('Volver al panel') }}</a>
    </p>
</x-guest-layout>
