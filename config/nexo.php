<?php

return [

    // Canonical "powered by" attribution shown in the shared footer. Env-overridable
    // so self-hosters can credit themselves; both are read by x-nexo-footer, which
    // falls back to alvarocdev.com when the label is unset.
    'attribution' => [
        'label' => env('NEXO_ATTRIBUTION_LABEL'),
        'url' => env('NEXO_ATTRIBUTION_URL'),
    ],

    // Help center contact target. A support URL (e.g. a form) wins; otherwise the
    // help page links a mailto: to this address.
    'support_url' => env('NEXO_SUPPORT_URL'),
    'support_email' => env('NEXO_SUPPORT_EMAIL', 'hola@alvarocdev.com'),

];
