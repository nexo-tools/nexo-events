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

    /**
     * What the person reads, in their language. An account joined to another
     * Nexo ID only moves by hand (an operator clears users.nexo_id_sub,
     * AC-LINK-4), so that message says what to do: write to the support email
     * when the tool has one (NexoSsoApp::supportEmail()), else sign in with
     * the Nexo ID it is joined to.
     */
    public function userMessage(): string
    {
        if ($this->reason === self::SUB_TAKEN) {
            return __('That Nexo ID account is already linked to another :app account.', ['app' => NexoSsoApp::name()]);
        }

        $support = NexoSsoApp::supportEmail();

        return $support !== null
            ? __('This account is already linked to another Nexo ID account. Write to us at :email and we will sort it out.', ['email' => $support])
            : __('This account is already linked to another Nexo ID account. Sign in with that Nexo ID account instead.');
    }
}
