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

    <ul class="space-y-2">
        @forelse ($tickets as $ticket)
            <li class="flex items-center justify-between gap-3 rounded-lg border border-line p-3">
                <span class="min-w-0">
                    <span class="font-medium">{{ $ticket->attendee_name }}</span>
                    {{-- Same rule as the phone line below (10.16, 10.22): pieces
                         that wrap whole with a gap, no printed separator — a long
                         email used to push "· Valid" alone onto the next line, and
                         an email wider than the column broke the page width. --}}
                    <span class="flex flex-wrap gap-x-2 text-xs text-muted" data-ticket-meta>
                        @if ($showEmails)
                            <span class="min-w-0 wrap-anywhere" data-ticket-email>{{ $ticket->attendee_email }}</span>
                        @endif
                        <span class="whitespace-nowrap" data-ticket-status>{{ $ticket->status->label() }}</span>
                    </span>
                    {{-- PLAN 10.13: the optional phone sits with the email, so
                         it rides the same owner-only condition. Collaborators
                         never get it. 10.16: formatted the way its country
                         writes it, the number dials and WhatsApp opens the chat. --}}
                    @if ($showEmails && $ticket->attendee_phone)
                        {{-- Pieces that wrap whole (never mid-number) and no
                             separator left dangling at a line end — the
                             column is narrow next to the door buttons. --}}
                        @php($phone = \App\Support\AttendeePhone::present($ticket->attendee_phone))
                        <span class="flex flex-wrap gap-x-2 text-xs text-muted" data-attendee-phone>
                            <a href="{{ $phone['tel'] }}" class="whitespace-nowrap text-link underline">{{ $phone['display'] }}</a>
                            @if ($phone['country'])
                                <span class="whitespace-nowrap">({{ $phone['country'] }})</span>
                            @endif
                            @if ($phone['whatsapp'])
                                <a href="{{ $phone['whatsapp'] }}" target="_blank" rel="noopener noreferrer" class="whitespace-nowrap text-link underline">WhatsApp</a>
                            @endif
                        </span>
                    @endif
                </span>
                @if ($ticket->checkin === null && $ticket->status->value === 'valid')
                    <span class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('tickets.checkin', $ticket) }}">@csrf
                            <x-button variant="secondary" class="nexo-btn--sm" :block="false">{{ __('Check in') }}</x-button>
                        </form>
                        {{-- ADR-011 §6: the reservation that was never paid. --}}
                        <form method="POST" action="{{ route('tickets.release', $ticket) }}"
                              data-confirm="{{ __('Release the spot of :name? Their ticket stops working at the door.', ['name' => $ticket->attendee_name]) }}">@csrf
                            <x-button variant="secondary" class="nexo-btn--sm" :block="false">{{ __('Release spot') }}</x-button>
                        </form>
                    </span>
                @elseif ($ticket->status->value === 'revoked')
                    {{-- «Devolver el lugar» (AC-COLLAB-19): its status already says «Lugar liberado». --}}
                    @if ($canRestore)
                        <form method="POST" action="{{ route('tickets.restore', $ticket) }}"
                              data-confirm="{{ __('Give :name their spot back? Their ticket gets in at the door again.', ['name' => $ticket->attendee_name]) }}">@csrf
                            <x-button variant="secondary" class="nexo-btn--sm" :block="false">{{ __('Give spot back') }}</x-button>
                        </form>
                    @else
                        <span class="text-xs font-medium text-muted" data-no-room>{{ __('No room to give it back') }}</span>
                    @endif
                @else
                    <span class="text-xs font-medium text-success-subtle-fg">{{ __('Checked in') }}</span>
                @endif
            </li>
        @empty
            <li class="rounded-lg border border-dashed border-line p-6 text-center">
                <p class="text-sm font-medium">{{ __('No one has registered yet.') }}</p>
                <p class="mt-1 text-sm text-muted">{{ __('Share the event\'s public page so people can start registering.') }}</p>
                <p class="mt-2 text-sm">
                    <a href="{{ route('public.event', $event) }}" class="break-all text-link hover:underline">{{ route('public.event', $event) }}</a>
                </p>
            </li>
        @endforelse
    </ul>

</x-app-layout>
