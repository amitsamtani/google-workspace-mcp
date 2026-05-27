<?php

namespace App\Services\Gmail;

/**
 * OAuth scopes requested by this MCP (v1).
 *
 * Deliberately gmail.modify ONLY. modify grants read + label + archive AND
 * draft creation (users.drafts.create) but CANNOT send mail. gmail.compose is
 * intentionally NOT requested because it would additionally grant send. This
 * server therefore has no ability to send or to permanently delete mail.
 */
final class GmailScopes
{
    /** Read, label, archive, and create drafts. Cannot send or permanently delete. */
    public const MODIFY = 'https://www.googleapis.com/auth/gmail.modify';

    /** @return list<string> */
    public static function default(): array
    {
        return [self::MODIFY];
    }
}
