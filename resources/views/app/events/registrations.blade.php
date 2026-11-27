<x-app-layout :title="__('Registrations').' · '.$event->title">
    @include('app.events._header', ['event' => $event, 'current' => 'registrations'])

    <p class="mb-4 text-sm text-muted" data-registrations-count>{{ trans_choice('app.tickets', $tickets->count()) }}</p>

    @if (session('checkin'))
        @include('app._checkin-flash', ['result' => session('checkin')])
    @endif

    @if (session('status'))
        <p class="nexo-flash mb-4" role="status">{{ session('status') }}</p>
    @endif
    @error('release')
        <p class="nexo-flash nexo-flash--danger mb-4" role="alert">{{ $message }}</p>
    @enderror
    @error('restore')
        <p class="nexo-flash nexo-flash--danger mb-4" role="alert">{{ $message }}</p>
    @enderror
    @error('guest')
        <p class="nexo-flash nexo-flash--danger mb-4" role="alert">{{ $message }}</p>
    @enderror

    {{-- Two sections in one screen (Alvaro, 2026-11-27; AC-PANEL-11): «Invitados» on top — few, and
         what the door looks for first — then «General». Without guests the list stays as before. --}}
    @if ($tickets->isEmpty())
        <ul class="space-y-2">
            <li class="rounded-lg border border-dashed border-line p-6 text-center">
                <p class="text-sm font-medium">{{ __('No one has registered yet.') }}</p>
                <p class="mt-1 text-sm text-muted">{{ __('Share the event\'s public page so people can start registering.') }}</p>
                <p class="mt-2 text-sm">
                    <a href="{{ route('public.event', $event) }}" class="break-all text-link hover:underline">{{ route('public.event', $event) }}</a>
                </p>
            </li>
        </ul>
    @elseif ($guestTickets->isEmpty())
        <ul class="space-y-2" data-section="general">
            @foreach ($generalTickets as $ticket)
                @include('app.events._registration-row')
            @endforeach
        </ul>
    @else
        <section class="mb-6" aria-labelledby="guests-heading" data-section="guests">
            <h2 id="guests-heading" class="mb-2 text-base font-semibold">{{ __('Guests') }} <span class="font-normal text-muted">({{ $guestTickets->count() }})</span></h2>
            <ul class="space-y-2">
                @foreach ($guestTickets as $ticket)
                    @include('app.events._registration-row')
                @endforeach
            </ul>
        </section>
        @if ($generalTickets->isNotEmpty())
            <section aria-labelledby="general-heading" data-section="general">
                <h2 id="general-heading" class="mb-2 text-base font-semibold">{{ __('General') }} <span class="font-normal text-muted">({{ $generalTickets->count() }})</span></h2>
                <ul class="space-y-2">
                    @foreach ($generalTickets as $ticket)
                        @include('app.events._registration-row')
                    @endforeach
                </ul>
            </section>
        @endif
    @endif

</x-app-layout>
