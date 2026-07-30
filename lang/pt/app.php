<?php

// Short product strings that are lookups rather than sentences: fragments too
// small to key by their English text (the generator treats a bare word as a
// lang-file key), and counts that need real plural forms.
return [
    'or' => 'ou',
    'tickets' => ':count ingresso|:count ingressos',
    'views' => ':count visita|:count visitas',

    // Carbon format string, translatedFormat(): month names come from the
    // Carbon locale, the ORDER of the parts comes from here.
    'datetime' => 'j M Y, H:i',
];
