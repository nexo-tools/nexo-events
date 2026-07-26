<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Anonymous, daily-rotating visitor fingerprint (canonical: nexo-links, see
 * CATALOG.md). Derived from the app key, today's date, the IP and the user
 * agent — **none of which are stored**. Because the date is part of the
 * payload, the same visitor cannot be linked across days, which is what keeps
 * the "cookieless, no raw IPs" promise honest (ADR-007 §6).
 */
class VisitorHash
{
    public static function make(Request $request): string
    {
        return hash('sha256', implode('|', [
            (string) config('app.key'),
            now()->toDateString(),
            (string) $request->ip(),
            (string) $request->userAgent(),
        ]));
    }
}
