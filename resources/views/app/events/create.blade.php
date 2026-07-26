<x-guest-layout :noindex="true">
    <h1 class="mb-4 text-xl font-bold">{{ __('Nuevo evento') }}</h1>

    <form method="POST" action="{{ route('events.store') }}" class="space-y-4">
        @csrf
        @include('app.events._fields', ['event' => null])
        <x-button>{{ __('Crear evento') }}</x-button>
    </form>

    <a href="{{ route('dashboard') }}" class="mt-4 block text-sm text-slate-500 hover:underline">{{ __('Volver') }}</a>
</x-guest-layout>
