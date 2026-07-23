<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ticket_id
 * @property ?int $checked_by
 * @property Carbon $checked_at
 */
#[Fillable(['ticket_id', 'checked_by', 'checked_at'])]
class Checkin extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
