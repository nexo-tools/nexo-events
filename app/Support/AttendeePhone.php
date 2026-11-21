<?php

namespace App\Support;

/**
 * The optional attendee phone (PLAN 10.13, ADR-003 amendment 2026-11-21).
 *
 * One place for the instance switch and for turning a stored number into the
 * link the owner taps, so the registration form, the request, the legal page
 * and the registrations list cannot disagree about either.
 */
final class AttendeePhone
{
    /** Max stored length — the column is varchar(30). */
    public const MAX = 30;

    /** Digits a number needs before it is worth storing. */
    public const MIN_DIGITS = 6;

    /** An optional leading "+", then digits, spaces, "()", "-" and ".". */
    public const PATTERN = '/^\+?[0-9 ().\-]+$/';

    public static function enabled(): bool
    {
        return config('nexo.attendee_phone') === 'optional';
    }

    /**
     * Where the owner's tap goes. A number written with its country code
     * ("+54 9 11 …") is one WhatsApp can open; anything else is dialled.
     *
     * @return array{href: string, whatsapp: bool}
     */
    public static function link(string $phone): array
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        if (str_starts_with(ltrim($phone), '+')) {
            return ['href' => 'https://wa.me/'.$digits, 'whatsapp' => true];
        }

        return ['href' => 'tel:'.$digits, 'whatsapp' => false];
    }
}
