<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's verification mail, queued.
 *
 * The framework default sends it inside the request. On shared hosting talking
 * to an external SMTP relay (ADR-002/005) that puts a third party's latency in
 * front of the sign-up response, and a relay timeout would surface as a failed
 * registration for an account that was in fact created. Queuing it makes a slow
 * relay mean "the mail is late", which is the same trade-off the ticket mail
 * makes.
 */
class VerifyEmailQueued extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
