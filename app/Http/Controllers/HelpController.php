<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

// Help center. FAQ items are translatable: the list lives in lang/<locale>/help.php
// as `faqs => [['q' => ..., 'a' => ...], ...]`. The contact target is a support
// URL if configured, otherwise a mailto: to the support address.
class HelpController extends Controller
{
    public function __invoke(): View
    {
        return view('help.index', [
            'faqs' => (array) __('help.faqs'),
            'contactUrl' => config('nexo.support_url') ?: 'mailto:'.config('nexo.support_email', ''),
        ]);
    }
}
