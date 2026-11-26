{{-- Footer of the public CONTENT pages (D8, CONTRATO-F3 §5.4): a Links profile
     (/{username}), an event (/e/{slug}), a business's booking page. Whoever
     opens those is a visitor of that person or business, not a user of Nexo, so
     they get no tool header — only this: language, theme, «Hecho con Nexo X»
     and, for the owner with a session, a discreet «Editar» chip.

       <x-nexo-public-footer :edit-url="$isOwner ? route('links.edit') : null" />

     The page decides who the owner is and passes edit-url only then; the chip
     never renders for anybody else (and never for a guest, even if a URL slips
     through). «Hecho con» links to the tool's own landing from the registry. --}}
@props(['editUrl' => null])
@php
    $tool = \App\Support\NexoChrome::tool();
    $toolUrl = (string) ($tool['url'] ?? url('/'));
    $showEdit = $editUrl !== null && $editUrl !== '' && auth()->check();
@endphp

<footer {{ $attributes->merge(['class' => 'nexo-public-footer']) }} data-nexo-public-footer>
    @if ($showEdit)
        <a href="{{ $editUrl }}" class="nexo-chip" data-nexo-edit-chip aria-label="{{ __('nexo.public_footer.edit_label') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            {{ __('nexo.public_footer.edit') }}
        </a>
    @endif

    <x-nexo-locale-switcher inline aria-label="{{ __('nexo.locale.label') }}" />

    <x-nexo-theme-toggle />

    <a href="{{ $toolUrl }}" class="nexo-public-footer__made" rel="noopener" data-nexo-made-with>
        {{ __('nexo.public_footer.made_with', ['app' => \App\Support\NexoChrome::brand()]) }}
    </a>
</footer>
