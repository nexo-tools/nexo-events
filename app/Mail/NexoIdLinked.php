<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Nexo ID was linked to your account" — sent the first time an existing local
 * account is claimed through single sign-on (family rule C5): by its verified
 * email, or by «Link my account» from a session opened with its password.
 *
 * The tool where the link happens sends it, never nexo-id: this is the app that
 * knows which account was just connected. Linking needs a provider-verified
 * email (AC-LINK-2) or the account's own password (AC-JOIN), so this mail is
 * not an authorization step — it is the notice that lets the owner notice
 * something they did not do.
 */
class NexoIdLinked extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $how  NexoSsoUserResolver::BY_EMAIL (Nexo ID vouched for
     *                       the email) or ::EXPLICIT (someone signed in here
     *                       with the password asked for it, maybe with another
     *                       email) — the "if you did not do this" line differs.
     */
    public function __construct(public User $user, public string $how = 'email') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Nexo ID was linked to your account'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.nexo-id-linked');
    }
}
