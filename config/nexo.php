<?php

return [

    'attribution_url' => env('NEXO_ATTRIBUTION_URL'),
    'attribution_text' => env('NEXO_ATTRIBUTION_TEXT'),

    // Help center contact target. A support URL (e.g. a form) wins; otherwise the
    // help page links a mailto: to this address.
    'support_url' => env('NEXO_SUPPORT_URL'),
    'support_email' => env('NEXO_SUPPORT_EMAIL', 'hola@alvarocdev.com'),

];
