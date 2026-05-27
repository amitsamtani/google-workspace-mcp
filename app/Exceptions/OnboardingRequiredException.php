<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by the services layer when a Gmail operation is attempted before
 * setup is complete. Tools catch it and translate it into the structured
 * error-as-suggestion payload Claude acts on (e.g. "no_oauth_credentials").
 */
class OnboardingRequiredException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,      // e.g. no_oauth_credentials
        public readonly string $suggestion,  // which tool to call next
    ) {
        parent::__construct($reason);
    }
}
