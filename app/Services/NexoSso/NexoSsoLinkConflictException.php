<?php

declare(strict_types=1);

namespace App\Services\NexoSso;

/**
 * "Link my account" refused: the Nexo ID already belongs to another account of
 * this tool (SUB_TAKEN), or this account is already joined to a different Nexo
 * ID (ACCOUNT_TAKEN). Nothing changed. (AC-JOIN)
 */
class NexoSsoLinkConflictException extends NexoSsoException
{
    public const SUB_TAKEN = 'sub_taken';

    public const ACCOUNT_TAKEN = 'account_taken';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Account link refused: '.$reason.'.');
    }

    /** What the person reads, in their language. */
    public function userMessage(): string
    {
        return $this->reason === self::SUB_TAKEN
            ? __('That Nexo ID account is already linked to another :app account.', ['app' => config('app.name')])
            : __('This account is already linked to another Nexo ID account.');
    }
}
