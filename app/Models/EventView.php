<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per visitor-day on a public event page (ADR-007 §6). `visitor_hash`
 * is a daily-rotating digest — not an identifier, not reversible to an IP, and
 * not comparable across days.
 */
class EventView extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
