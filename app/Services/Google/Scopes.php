<?php

namespace App\Services\Google;

/**
 * Single source of truth for every Google API OAuth scope this MCP requests.
 *
 * One bundle is requested at consent time, so a single re-authorization grants
 * an account access to every API family the server exposes (Gmail today,
 * Calendar next, Drive later). The actually-granted scopes — which may be a
 * subset if the user declines some — are read off the token response and
 * stored per-account, so `guard($account, requireScope: ...)` can decide
 * whether an individual tool call is authorized.
 *
 * Least-privilege within each family: gmail.modify (read/label/archive/drafts,
 * no send/delete), calendar.events (event CRUD), plus the dedicated free/busy
 * and calendar-list-read scopes which calendar.events cannot cover.
 */
final class Scopes
{
    /** Gmail: read, label, archive, create drafts. Cannot send or permanently delete. */
    public const GMAIL_MODIFY = 'https://www.googleapis.com/auth/gmail.modify';

    /** Calendar: full event CRUD + attendees + RSVP + cancel + Meet links. */
    public const CALENDAR_EVENTS = 'https://www.googleapis.com/auth/calendar.events';

    /** Calendar: free/busy lookup only — calendar.events cannot do freebusy.query. */
    public const CALENDAR_FREEBUSY = 'https://www.googleapis.com/auth/calendar.freebusy';

    /** Calendar: list the calendars on an account — calendar.events cannot do calendarList.list. */
    public const CALENDAR_CALENDARLIST_READONLY = 'https://www.googleapis.com/auth/calendar.calendarlist.readonly';

    /**
     * The full bundle requested at consent.
     *
     * @return list<string>
     */
    public static function requested(): array
    {
        return [
            self::GMAIL_MODIFY,
            self::CALENDAR_EVENTS,
            self::CALENDAR_FREEBUSY,
            self::CALENDAR_CALENDARLIST_READONLY,
        ];
    }

    /**
     * @param  list<string>  $granted  the scopes actually granted to the account
     */
    public static function has(array $granted, string $scope): bool
    {
        return in_array($scope, $granted, true);
    }

    /**
     * Parse Google's space-delimited `scope` field from a token response into
     * an array, with empty entries stripped.
     *
     * @return list<string>
     */
    public static function fromTokenResponse(?string $scopeField): array
    {
        if ($scopeField === null || $scopeField === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $scopeField), static fn (string $s): bool => $s !== ''));
    }
}
