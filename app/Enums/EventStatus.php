<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';       // registration closed (manual or sold out), event still on
    case Cancelled = 'cancelled'; // organizer cancelled the event
    case Killed = 'killed';       // admin kill-switch (abuse)

    /** What a human is shown. The backing value is storage, never UI copy. */
    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Borrador'),
            self::Published => __('Publicado'),
            self::Closed => __('Registro cerrado'),
            self::Cancelled => __('Cancelado'),
            self::Killed => __('Bloqueado'),
        };
    }

    /** Can attendees register right now? */
    public function acceptsRegistrations(): bool
    {
        return $this === self::Published;
    }

    /** Is the public page visible (published/closed/cancelled show; draft/killed hide)? */
    public function isPublic(): bool
    {
        return in_array($this, [self::Published, self::Closed, self::Cancelled], true);
    }
}
