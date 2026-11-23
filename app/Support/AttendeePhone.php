<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * The optional attendee phone (PLAN 10.13 and 10.16, ADR-003 amendments
 * 2026-11-21 and 2026-11-23).
 *
 * One place for the instance switch, the country list, reading what a person
 * typed and turning a stored number into what the owner sees and taps, so the
 * form, the request, the legal page and the registrations list cannot disagree
 * about any of it. The reading is libphonenumber's — Google's metadata, bundled,
 * no request at runtime: it knows each country's lengths, area codes and how
 * its numbers are grouped, which a regex in this file never could.
 */
final class AttendeePhone
{
    /** Max typed length — the column is varchar(30); E.164 needs at most 16. */
    public const MAX = 30;

    /** An optional leading "+", then digits, spaces, "()", "-" and ".". */
    public const PATTERN = '/^\+?[0-9 ().\-]+$/';

    /** Where the selector starts when NEXO_ATTENDEE_PHONE_COUNTRY is not a listed country. */
    private const FALLBACK_COUNTRY = 'AR';

    public static function enabled(): bool
    {
        return config('nexo.attendee_phone') === 'optional';
    }

    /** The country the selector starts on (ISO 3166-1 alpha-2). */
    public static function defaultCountry(): string
    {
        $country = strtoupper((string) config('nexo.attendee_phone_country'));

        return array_key_exists($country, self::countryNames()) ? $country : self::FALLBACK_COUNTRY;
    }

    /** @return list<string> */
    public static function countryCodes(): array
    {
        return array_keys(self::countryNames());
    }

    /**
     * The selector's options in the visitor's language: the instance's country
     * first, then every other one in alphabetical order, each with its code.
     *
     * @return array<string, string> code => "Argentina (+54)"
     */
    public static function countryOptions(): array
    {
        $util = PhoneNumberUtil::getInstance();
        $options = [];

        foreach (self::countryNames() as $code => $name) {
            $options[$code] = $name.' (+'.$util->getCountryCodeForRegion($code).')';
        }

        $default = self::defaultCountry();

        return [$default => $options[$default]] + $options;
    }

    public static function countryName(string $code): string
    {
        return self::countryNames()[$code] ?? $code;
    }

    /**
     * What the person typed, read as a number of $country — unless it starts
     * with "+", which carries its own country code whatever $country says.
     * Null unless it is a number that country actually hands out (length and
     * area code), so "15 2345-6789" with no area code is not one.
     */
    public static function parse(string $typed, string $country): ?PhoneNumber
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse($typed, $country);
        } catch (NumberParseException) {
            return null;
        }

        return $util->isValidNumber($number) ? $number : null;
    }

    /** E.164 ("+5491123456789") — what is stored since 10.16 — or null if it is not a number. */
    public static function normalize(string $typed, string $country): ?string
    {
        $number = self::parse($typed, $country);

        return $number === null ? null : PhoneNumberUtil::getInstance()->format($number, PhoneNumberFormat::E164);
    }

    /**
     * What the owner sees and taps for a stored number: E.164 since 10.16, or
     * whatever was typed before it, read with the instance's country. One that
     * still does not read as a number is shown and dialled as it was typed.
     *
     * @return array{display: string, tel: string, whatsapp: string|null, country: string|null}
     */
    public static function present(string $stored): array
    {
        $number = self::parse($stored, self::defaultCountry());

        if ($number === null) {
            $plus = str_starts_with(ltrim($stored), '+') ? '+' : '';

            return ['display' => $stored, 'tel' => 'tel:'.$plus.preg_replace('/\D+/', '', $stored), 'whatsapp' => null, 'country' => null];
        }

        $util = PhoneNumberUtil::getInstance();
        $number = self::asArgentineMobile($number);
        $e164 = $util->format($number, PhoneNumberFormat::E164);
        $region = (string) $util->getRegionCodeForNumber($number);

        return [
            'display' => $util->format($number, PhoneNumberFormat::INTERNATIONAL),
            'tel' => 'tel:'.$e164,
            // A plain landline has no WhatsApp; anything that may be a mobile does.
            'whatsapp' => $util->getNumberType($number) === PhoneNumberType::FIXED_LINE ? null : 'https://wa.me/'.substr($e164, 1),
            // The owner's own country goes without saying; any other is named.
            'country' => $region === self::defaultCountry() ? null : self::countryName($region),
        ];
    }

    /**
     * An Argentine mobile written without its 9 (or its 15) reads as a
     * landline: "11 2345-6789". In a field that asks for a WhatsApp it is
     * almost always a mobile, and WhatsApp only finds Argentine mobiles as
     * +54 9 …, so it is shown and linked as one — the owner's call on
     * 2026-11-23. A real landline gets a 9 it does not have: the accepted cost.
     */
    private static function asArgentineMobile(PhoneNumber $number): PhoneNumber
    {
        $util = PhoneNumberUtil::getInstance();

        if ($util->getRegionCodeForNumber($number) !== 'AR' || $util->getNumberType($number) !== PhoneNumberType::FIXED_LINE) {
            return $number;
        }

        $mobile = self::parse('+549'.$util->getNationalSignificantNumber($number), 'AR');

        return $mobile !== null && $util->getNumberType($mobile) === PhoneNumberType::MOBILE ? $mobile : $number;
    }

    /**
     * Code => name in the visitor's language and alphabetical order, from
     * lang/{locale}/countries.php (scripts/generate-countries.php).
     *
     * @return array<string, string>
     */
    private static function countryNames(): array
    {
        /** @var array<string, string>|string $names */
        $names = trans('countries');

        return is_array($names) ? $names : [];
    }
}
