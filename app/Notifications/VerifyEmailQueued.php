<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Verification mail: queued, and rendered from this project's own template.
 *
 * Queued because the framework default sends it inside the request, which on
 * shared hosting talking to an external relay (ADR-002/005) would put a third
 * party's latency in front of the sign-up response and turn a relay timeout
 * into a failed registration for an account that was in fact created.
 *
 * Own view because the inherited MailMessage builds its wrapper from the
 * framework's English translations ("Verify Email Address", "Regards"), which
 * this project's i18n cannot reach: Spanish is the source language and the
 * generator translates outward from it. Caught against Mailpit — before this,
 * a Spanish-first product sent "Verify your email address".
 *
 * Note it stays a MailMessage carrying a custom `view`, rather than returning a
 * Mailable: the parent's signature promises a MailMessage, and a Mailable
 * returned here would also have had to address itself (the notification channel
 * does not add the recipient for one, which fails with "An email must have a
 * To header").
 */
class VerifyEmailQueued extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    /** @param  mixed  $notifiable */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Verify your email'))
            ->view('emails.verify-email', ['url' => $this->verificationUrl($notifiable)]);
    }
}
