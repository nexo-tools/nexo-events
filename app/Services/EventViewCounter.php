<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Support\VisitorHash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Counts unique visitor-days on a public event page, cookielessly (ADR-007 §6).
 *
 * Dedupe is delegated to the UNIQUE(event_id, visitor_hash, viewed_on) index via
 * insertOrIgnore: a "have we seen them today?" SELECT followed by an INSERT
 * would race two concurrent tab loads into a double count, and every extra
 * count inflates the one number an organizer actually looks at.
 */
class EventViewCounter
{
    public function record(Event $event, Request $request): void
    {
        DB::table('event_views')->insertOrIgnore([
            'event_id' => $event->getKey(),
            'visitor_hash' => VisitorHash::make($request),
            'viewed_on' => now()->toDateString(),
            'created_at' => now(),
        ]);
    }
}
