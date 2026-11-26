{{-- Standard ecosystem footer: "part of Nexo" -> hub, "powered by" attribution
     (canonical NEXO_ATTRIBUTION_LABEL/URL), and a source link to the GitHub org.
     i18n: nexo.footer.*

     Chrome v2: the attribution is translated. NEXO_ATTRIBUTION_LABEL is still the
     whole phrase (nothing is prepended), but its two shapes in use — «powered by
     X» and «made with X» — go through nexo.footer.powered_by / made_with with X
     untouched, so a Spanish page no longer ends in English. Any other phrase is
     shown verbatim. --}}
@php
    $eco = config('nexo-ecosystem', []);
    // Neutral product default: an instance somebody else deploys must not
    // advertise the upstream author (add-branding-footer, multi-instance rule).
    // The product name comes from the registry, so this file is identical in
    // every app (it used to be edited per copy: 'made with Nexo X').
    $attrLabel = config('nexo.attribution.label') ?: 'made with '.\App\Support\NexoChrome::brand();
    $attrUrl = config('nexo.attribution.url') ?: ($eco['github_org_url'] ?? 'https://github.com/nexo-tools');
    if (preg_match('/^\s*(powered by|made with)\s+(.+)$/i', (string) $attrLabel, $attrParts)) {
        $attrLabel = __('nexo.footer.'.(strtolower($attrParts[1]) === 'powered by' ? 'powered_by' : 'made_with'), ['name' => trim($attrParts[2])]);
    }
@endphp

<footer {{ $attributes->merge(['class' => 'nexo-footer']) }}>
    <span class="nexo-footer__eco">
        <a href="{{ $eco['hub_url'] ?? 'https://nexotools.alvarocdev.com' }}" rel="noopener">
            {{ __('nexo.footer.part_of') }}
        </a>
    </span>

    <span class="nexo-footer__spacer"></span>

    {{-- The label is the whole phrase ("powered by example.com"): prepend
         nothing here, or the footer reads "Made by powered by example.com".
         It is translated above, never prefixed. --}}
    <span>
        <a href="{{ $attrUrl }}" rel="noopener">{{ $attrLabel }}</a>
    </span>

    <a href="{{ $eco['github_org_url'] ?? 'https://github.com/nexo-tools' }}" rel="noopener">
        {{ __('nexo.footer.source') }}
    </a>

    {{-- Help lives here for the same reason the legal pages do: the footer is the
         one piece of chrome that renders on every surface. While the standard
         said only "have a help center", each tool improvised where to link it —
         a header ghost on the landing, an account menu only signed-in users see,
         nothing at all in nexoshort's panel — so half the ecosystem looked like
         it had no help at all. The route name is always `help`. --}}
    <a href="{{ route('help') }}">{{ __('nexo.help.title') }}</a>

    {{-- Legal pages must be reachable from every page (STANDARD.md). Routes
         come from pages/legal/routes-snippet.php. --}}
    <a href="{{ route('legal.privacy') }}">{{ __('nexo.footer.privacy') }}</a>
    <a href="{{ route('legal.terms') }}">{{ __('nexo.footer.terms') }}</a>
</footer>
