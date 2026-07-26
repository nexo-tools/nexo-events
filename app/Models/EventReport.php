<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An abuse report on a public event (ADR-007 §3). Filed without an account, so
 * the only contact is the optional email the reporter chose to leave.
 */
class EventReport extends Model
{
    public const UPDATED_AT = null; // reports are never edited

    protected $fillable = ['reason', 'reporter_email'];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
