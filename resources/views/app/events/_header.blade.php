{{-- The top of every screen of one event (SPEC-event-panel, AC-PANEL-2):
     the way back to the list, what event this is, and the event navigation
     Summary · Registrations · Door · Edit. One partial so the four screens
     cannot drift apart. Edit is the owner's (content, publishing, cancelling,
     deleting a draft); a collaborator gets the other three.
     Expects $event and $current: summary | registrations | door | edit. --}}
@php($tabs = array_filter([
    'summary' => [route('events.manage', $event), __('Summary')],
    'registrations' => [route('events.registrations', $event), __('Registrations')],
    'door' => [route('events.scan', $event), __('Door')],
    'edit' => $event->organizer_id === auth()->user()?->id ? [route('events.edit', $event), __('Edit')] : null,
]))

<a href="{{ route('dashboard') }}" class="mb-3 inline-block text-sm text-muted hover:underline" data-back-to-dashboard>← {{ __('Your events') }}</a>

<h1 class="text-xl font-bold">{{ $event->title }}</h1>
{{-- Pieces that wrap whole, with a gap and no printed separator: a "·" left
     at the end or the start of a line reads as a typo on a phone. --}}
<p class="mt-1 flex flex-wrap gap-x-3 text-sm text-muted" data-event-meta>
    <span class="whitespace-nowrap">{{ $event->starts_at->translatedFormat(__('app.datetime')) }}</span>
    @if ($event->venue)
        <span>{{ $event->venue }}</span>
    @endif
    <span class="whitespace-nowrap font-medium">{{ $event->status->label() }}</span>
</p>

{{-- Bleeds to the card's edges and shares the width out on a phone, so the
     four Spanish tabs fit at 320 px; scrolls sideways rather than wrapping if
     a language ever needs more room. --}}
<nav aria-label="{{ __('Event') }}" class="-mx-6 mt-4 mb-6 border-b border-line sm:-mx-8" data-event-nav>
    <ul class="flex overflow-x-auto px-2 sm:px-6">
        @foreach ($tabs as $key => [$url, $label])
            <li class="flex-auto shrink-0 sm:flex-none">
                <a href="{{ $url }}"
                   @if ($key === $current) aria-current="page" @endif
                   @class([
                       'flex min-h-11 items-center justify-center whitespace-nowrap border-b-2 px-1.5 text-[0.8125rem] font-medium sm:px-3 sm:text-sm',
                       'border-primary text-ink' => $key === $current,
                       'border-transparent text-muted hover:text-ink' => $key !== $current,
                   ])>{{ $label }}</a>
            </li>
        @endforeach
    </ul>
</nav>
