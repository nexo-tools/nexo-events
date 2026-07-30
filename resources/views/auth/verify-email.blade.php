<x-guest-layout :noindex="true">
    <h1 class="mb-2 text-xl font-bold">{{ __('Verifica tu email') }}</h1>

    <p class="mb-4 text-sm text-muted">
        {{ __('Te enviamos un enlace a :email. Ábrelo para poder publicar tus eventos; mientras tanto puedes crear y editar borradores.', ['email' => auth()->user()->email]) }}
    </p>

    @if (session('status'))
        <p class="nexo-flash mb-4" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <x-button>{{ __('Reenviar el enlace') }}</x-button>
    </form>

    <p class="mt-6 text-sm">
        <a href="{{ route('dashboard') }}" class="text-link underline">{{ __('Volver al panel') }}</a>
    </p>
</x-guest-layout>
