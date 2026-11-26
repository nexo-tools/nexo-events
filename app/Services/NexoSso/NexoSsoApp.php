<?php

declare(strict_types=1);

namespace App\Services\NexoSso;

use App\Support\NexoChrome;

/**
 * What this tool says about itself on the sso-client's screens and messages:
 * its name as people read it, and where to write when something needs a
 * person to fix it. (v2.2)
 */
final class NexoSsoApp
{
    /** Reserved domains (RFC 2606/6761): a placeholder, never somebody's inbox. */
    private const PLACEHOLDER_DOMAINS = ['example.com', 'example.net', 'example.org', 'localhost'];

    /** …and every name under them (mail.example.com is as reserved as example.com). */
    private const PLACEHOLDER_SUFFIXES = ['.example', '.invalid', '.localhost', '.test', '.example.com', '.example.net', '.example.org'];

    /**
     * The tool's name: the chrome's brand (nexo-ui's NexoChrome::brand(), the
     * ecosystem registry's name) when the app has it, so these screens say the
     * same name as the header; otherwise config('app.name').
     */
    public static function name(): string
    {
        $brand = class_exists(NexoChrome::class) ? NexoChrome::brand() : '';

        return $brand !== '' ? $brand : (string) config('app.name');
    }

    /**
     * The support email the operator configured (config('nexo.support_email'),
     * NEXO_SUPPORT_EMAIL — the one nexo-ops and the help center use), or null
     * when there is none: empty, not an email, or a placeholder such as the
     * hola@example.com some configs ship as default.
     */
    public static function supportEmail(): ?string
    {
        $email = config('nexo.support_email');
        if (! is_string($email) || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $email = trim($email);
        $domain = strtolower(substr($email, (int) strrpos($email, '@') + 1));
        if (in_array($domain, self::PLACEHOLDER_DOMAINS, true)) {
            return null;
        }

        foreach (self::PLACEHOLDER_SUFFIXES as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return null;
            }
        }

        return $email;
    }
}
