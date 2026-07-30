<x-app-layout>
    <h1 class="mb-4 text-xl font-bold">{{ __('New event') }}</h1>

    <form method="POST" action="{{ route('events.store') }}" class="space-y-4">
        @csrf
        @include('app.events._fields', ['event' => null])
        <x-button>{{ __('Create event') }}</x-button>
    </form>

    <a href="{{ route('dashboard') }}" class="mt-4 block text-sm text-muted hover:underline">{{ __('Back') }}</a>
</x-app-layout>
