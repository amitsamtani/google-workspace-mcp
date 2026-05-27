<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a stored refresh token is rejected by Google (expired/revoked),
 * so the account must be re-authorized. Tools translate this into the
 * reauth_needed error-as-suggestion payload, naming the account and the tool
 * to call (gmail_start_oauth_flow with the matching email_hint).
 */
class ReauthRequiredException extends RuntimeException
{
    public function __construct(
        public readonly string $account,
        string $detail = '',
    ) {
        parent::__construct(
            "Re-authorization required for {$account}".($detail !== '' ? ": {$detail}" : '')
        );
    }
}
