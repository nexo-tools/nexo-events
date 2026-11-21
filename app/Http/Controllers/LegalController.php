<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AttendeePhone;
use Illuminate\View\View;

/**
 * Privacy and terms. Content lives in lang/{es,en,pt}/legal.php — the same
 * pattern the help centre uses, because whole paragraphs do not belong in the
 * string-by-string translation map.
 */
class LegalController extends Controller
{
    public function privacy(): View
    {
        return $this->page('privacy');
    }

    public function terms(): View
    {
        return $this->page('terms');
    }

    private function page(string $key): View
    {
        /** @var array{title: string, intro: string, sections: array<int, array{h: string, p: string}>} $content */
        $content = __("legal.{$key}");

        // PLAN 10.13: the optional attendee phone is described only on an
        // instance that collects it, right after what is kept about attendees
        // (the second section). With the switch off the page is unchanged.
        if ($key === 'privacy' && AttendeePhone::enabled()) {
            /** @var array{h: string, p: string} $phone */
            $phone = __('legal.privacy_phone');
            array_splice($content['sections'], 2, 0, [$phone]);
        }

        return view('legal.show', [
            'title' => $content['title'],
            'description' => $content['intro'],
            'content' => $content,
            'updated' => __('legal.updated'),
            'operator' => config('nexo.legal.operator'),
            'contact' => config('nexo.legal.contact'),
        ]);
    }
}
