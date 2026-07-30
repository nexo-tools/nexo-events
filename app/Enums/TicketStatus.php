<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Valid = 'valid';
    case CheckedIn = 'checked_in';
    case Revoked = 'revoked';

    /** What a human is shown. The backing value is storage, never UI copy. */
    public function label(): string
    {
        return match ($this) {
            self::Valid => __('Valid'),
            self::CheckedIn => __('Checked in'),
            self::Revoked => __('Revoked'),
        };
    }
}
