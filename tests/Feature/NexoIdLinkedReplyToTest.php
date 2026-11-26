<?php

declare(strict_types=1);

use App\Mail\NexoIdLinked;
use App\Models\User;

// «If you did not do this, write to us»: the From is this tool's sending
// mailbox, which nobody may read, so a reply has to reach the support address.
it('AC-MAIL-5: a reply to «Nexo ID was linked» goes to the support email', function (string $support) {
    config(['nexo.support_email' => $support]);

    $mail = new NexoIdLinked(User::factory()->make());

    expect($mail->hasReplyTo($support))->toBeTrue()
        ->and($mail->envelope()->replyTo)->toHaveCount(1);
})->with([
    'this instance' => ['help@alvarocdev.com'],
    'a self-hosted instance' => ['soporte@otra-instancia.org'],
]);

it('AC-MAIL-5: without a real support email the mail sets no Reply-To', function (?string $email) {
    config(['nexo.support_email' => $email]);

    expect((new NexoIdLinked(User::factory()->make()))->envelope()->replyTo)->toBe([]);
})->with([
    'unset' => [null],
    'empty' => [''],
    'placeholder' => ['hola@example.com'],
    'not an email' => ['hola'],
]);
