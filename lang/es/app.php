<?php

// Short product strings that are lookups rather than sentences: fragments too
// small to key by their Spanish text (the generator treats a bare word as a
// lang-file key), and counts that need real plural forms.
return [
    'or' => 'o',
    'tickets' => ':count entrada|:count entradas',
    'views' => ':count visita|:count visitas',

    // Carbon format string, translatedFormat(): month names come from the
    // Carbon locale, the ORDER of the parts comes from here.
    'datetime' => 'j M Y, H:i',
];
