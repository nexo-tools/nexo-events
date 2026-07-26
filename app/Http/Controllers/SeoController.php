<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Http\Response;

/**
 * robots.txt and sitemap.xml as routes, not static files: both have to name the
 * instance's own domain, and this project is multi-instance by design — a
 * committed file would hardcode alvarocdev.com into every self-host.
 */
class SeoController extends Controller
{
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            // Private or per-person surfaces. `/t/` matters most: ticket URLs are
            // bearer credentials, and an indexed one is a ticket anyone can use.
            'Disallow: /app',
            'Disallow: /t/',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            'Disallow: /verify-email',
            'Disallow: /auth/',
            '',
            'Sitemap: '.route('sitemap'),
            '',
        ];

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $urls = [
            ['loc' => route('home'), 'priority' => '1.0'],
            ['loc' => route('help'), 'priority' => '0.5'],
            ['loc' => route('legal.privacy'), 'priority' => '0.3'],
            ['loc' => route('legal.terms'), 'priority' => '0.3'],
        ];

        // Only genuinely public events: drafts, closed, cancelled and killed
        // ones must never be advertised to crawlers.
        Event::query()
            ->where('status', EventStatus::Published->value)
            ->orderByDesc('starts_at')
            ->chunk(200, function ($events) use (&$urls): void {
                foreach ($events as $event) {
                    $urls[] = [
                        'loc' => route('public.event', $event),
                        'lastmod' => $event->updated_at?->toAtomString(),
                        'priority' => '0.8',
                    ];
                }
            });

        $xml = view('sitemap', ['urls' => $urls, 'locales' => ['es', 'en', 'pt']])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
