<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NexoSso\NexoSsoRevocations;
use Illuminate\Console\Command;

/**
 * Deletes expired back-channel revocations (nexo_sso_revocations). Scheduled
 * daily in routes/console.php; safe to run any time, also with SSO disabled.
 */
class NexoSsoPrune extends Command
{
    protected $signature = 'nexo-sso:prune';

    protected $description = 'Delete expired Nexo ID back-channel logout revocations.';

    public function handle(NexoSsoRevocations $revocations): int
    {
        $deleted = $revocations->prune();

        $this->info("Pruned {$deleted} expired revocation(s).");

        return self::SUCCESS;
    }
}
