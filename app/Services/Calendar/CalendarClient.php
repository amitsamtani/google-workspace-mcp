<?php

namespace App\Services\Calendar;

use App\Services\Google\AccountTokenManager;
use Google\Service\Calendar;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\ConferenceSolutionKey;
use Google\Service\Calendar\CreateConferenceRequest;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;

/**
 * Calendar-specific operations layered on top of AccountTokenManager.
 *
 * All auth/refresh/rate-limit/retry concerns live in the manager (shared with
 * GmailClient). This class only owns the Calendar API mechanics: simplifying
 * Event/FreeBusy responses, building EventDateTime / Attendee objects, and
 * the read-modify-write patterns the Calendar API forces on attendee changes
 * and RSVPs.
 *
 * Outward-facing-by-design: by default these methods email attendees on
 * inserts/changes/cancellations (`sendUpdates='all'`). Callers pass `none` to
 * stay silent.
 */
class CalendarClient
{
    public function __construct(
        private readonly AccountTokenManager $tokens,
    ) {}

    // ----- Reads -------------------------------------------------------------

    /**
     * @return list<array{id: string, summary: ?string, primary: bool, access_role: ?string, time_zone: ?string}>
     */
    public function listCalendars(string $account): array
    {
        $service = $this->calendar($account);
        $response = $this->tokens->call($account, 1, fn () => $service->calendarList->listCalendarList());

        $items = [];
        foreach ($response->getItems() ?? [] as $entry) {
            $items[] = [
                'id' => $entry->getId(),
                'summary' => $entry->getSummaryOverride() ?: $entry->getSummary(),
                'primary' => (bool) $entry->getPrimary(),
                'access_role' => $entry->getAccessRole(),
                'time_zone' => $entry->getTimeZone(),
            ];
        }

        return $items;
    }

    /**
     * Check schedule between two RFC3339 instants (offset required). Recurring
     * events are expanded to single instances (singleEvents=true) and ordered
     * by start time.
     *
     * @return array{events: list<array<string, mixed>>, next_page_token: ?string}
     */
    public function listEvents(
        string $account,
        string $timeMin,
        string $timeMax,
        string $calendarId = 'primary',
        ?string $query = null,
        ?string $pageToken = null,
        int $maxResults = 50,
    ): array {
        $service = $this->calendar($account);

        $params = [
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'singleEvents' => true,
            'orderBy' => 'startTime',
            'maxResults' => max(1, min($maxResults, 2500)),
            'showDeleted' => false,
        ];
        if ($query !== null && $query !== '') {
            $params['q'] = $query;
        }
        if ($pageToken !== null && $pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }

        $response = $this->tokens->call($account, 5, fn () => $service->events->listEvents($calendarId, $params));

        $events = [];
        foreach ($response->getItems() ?? [] as $event) {
            $events[] = $this->simplifyEvent($event);
        }

        return [
            'events' => $events,
            'next_page_token' => $response->getNextPageToken() ?: null,
        ];
    }

    /** @return array<string, mixed> */
    public function getEvent(string $account, string $eventId, string $calendarId = 'primary'): array
    {
        $service = $this->calendar($account);
        $event = $this->tokens->call($account, 5, fn () => $service->events->get($calendarId, $eventId));

        return $this->simplifyEvent($event);
    }

    /**
     * Free/busy lookup. Returns busy intervals per calendar id (no event
     * details — that's why freebusy has its own narrow scope).
     *
     * @param  list<string>  $calendarIds  e.g. ['primary'] or ['primary','colleague@example.com']
     * @return array{time_min: string, time_max: string, calendars: array<string, array{busy: list<array{start: string, end: string}>, errors?: list<array<string, mixed>>}>}
     */
    public function findFreeBusy(
        string $account,
        string $timeMin,
        string $timeMax,
        array $calendarIds = ['primary'],
        ?string $timeZone = null,
    ): array {
        $service = $this->calendar($account);

        $request = new FreeBusyRequest;
        $request->setTimeMin($timeMin);
        $request->setTimeMax($timeMax);
        if ($timeZone !== null) {
            $request->setTimeZone($timeZone);
        }
        $request->setItems(array_map(static function (string $id): FreeBusyRequestItem {
            $item = new FreeBusyRequestItem;
            $item->setId($id);

            return $item;
        }, $calendarIds));

        $response = $this->tokens->call($account, 5, fn () => $service->freebusy->query($request));

        $byCalendar = [];
        foreach ($response->getCalendars() ?? [] as $calendarId => $calendar) {
            $busy = [];
            foreach ($calendar->getBusy() ?? [] as $period) {
                $busy[] = ['start' => $period->getStart(), 'end' => $period->getEnd()];
            }
            $byCalendar[$calendarId] = ['busy' => $busy];

            $errors = $calendar->getErrors() ?? [];
            if ($errors !== []) {
                $byCalendar[$calendarId]['errors'] = array_map(
                    static fn ($e): array => ['domain' => $e->getDomain(), 'reason' => $e->getReason()],
                    $errors,
                );
            }
        }

        return ['time_min' => $timeMin, 'time_max' => $timeMax, 'calendars' => $byCalendar];
    }

    // ----- Mutations ---------------------------------------------------------

    /**
     * Create an event. start/end follow the EventDateTime shape:
     *   ['dateTime' => '2026-05-27T14:00:00-07:00', 'timeZone' => 'America/Los_Angeles']
     *   or ['date' => '2026-05-27']  (all-day; cannot mix with dateTime)
     *
     * `attendees` is a list of email strings (display names not v1).
     * `notify` ∈ ['all','external_only','none'] — default 'all' so invites
     * actually reach people.
     * `add_meet` true attaches a Google Meet conference (requires
     * conferenceDataVersion=1).
     *
     * @param  array{dateTime?: string, date?: string, timeZone?: string}  $start
     * @param  array{dateTime?: string, date?: string, timeZone?: string}  $end
     * @param  list<string>  $attendees
     * @param  list<string>  $recurrence  RRULE strings, e.g. ['RRULE:FREQ=WEEKLY;BYDAY=MO']
     * @return array<string, mixed> the simplified created event
     */
    public function createEvent(
        string $account,
        string $summary,
        array $start,
        array $end,
        array $attendees = [],
        ?string $description = null,
        ?string $location = null,
        array $recurrence = [],
        bool $addMeet = false,
        string $notify = 'all',
        string $calendarId = 'primary',
    ): array {
        $service = $this->calendar($account);

        $event = new Event;
        $event->setSummary($summary);
        if ($description !== null) {
            $event->setDescription($description);
        }
        if ($location !== null) {
            $event->setLocation($location);
        }
        $event->setStart($this->buildDateTime($start));
        $event->setEnd($this->buildDateTime($end));
        if ($attendees !== []) {
            $event->setAttendees($this->buildAttendees($attendees));
        }
        if ($recurrence !== []) {
            $event->setRecurrence($recurrence);
        }

        $optParams = ['sendUpdates' => $this->normalizeNotify($notify)];

        if ($addMeet) {
            $request = new CreateConferenceRequest;
            $request->setRequestId('gwmcp-'.bin2hex(random_bytes(8)));
            $solutionKey = new ConferenceSolutionKey;
            $solutionKey->setType('hangoutsMeet');
            $request->setConferenceSolutionKey($solutionKey);

            $conference = new ConferenceData;
            $conference->setCreateRequest($request);
            $event->setConferenceData($conference);

            // Required or conferenceData is silently dropped.
            $optParams['conferenceDataVersion'] = 1;
        }

        $created = $this->tokens->call(
            $account,
            10,
            fn () => $service->events->insert($calendarId, $event, $optParams),
        );

        return $this->simplifyEvent($created);
    }

    /**
     * Move/reschedule an event: patch ONLY start/end. We use patch (not
     * update, which clears omitted fields, and not events.move which changes
     * which calendar owns the event).
     *
     * @param  array{dateTime?: string, date?: string, timeZone?: string}  $start
     * @param  array{dateTime?: string, date?: string, timeZone?: string}  $end
     * @return array<string, mixed>
     */
    public function rescheduleEvent(
        string $account,
        string $eventId,
        array $start,
        array $end,
        string $calendarId = 'primary',
        string $notify = 'all',
    ): array {
        $service = $this->calendar($account);

        $patch = new Event;
        $patch->setStart($this->buildDateTime($start));
        $patch->setEnd($this->buildDateTime($end));

        $updated = $this->tokens->call(
            $account,
            10,
            fn () => $service->events->patch(
                $calendarId,
                $eventId,
                $patch,
                ['sendUpdates' => $this->normalizeNotify($notify)],
            ),
        );

        return $this->simplifyEvent($updated);
    }

    /**
     * Add attendees to an existing event. The Calendar API has no atomic
     * "append attendee" — the attendees array is fully replaced on write — so
     * we read the current attendees, append, and patch. Duplicates by email
     * are skipped.
     *
     * Concurrency note: between get and patch a parallel writer could change
     * attendees and lose updates. v1 accepts that risk for simplicity; the
     * Google client does not expose an If-Match header here.
     *
     * @param  list<string>  $emails
     * @return array<string, mixed>
     */
    public function addAttendees(
        string $account,
        string $eventId,
        array $emails,
        string $calendarId = 'primary',
        string $notify = 'all',
    ): array {
        $service = $this->calendar($account);
        $event = $this->tokens->call($account, 5, fn () => $service->events->get($calendarId, $eventId));

        $existing = $event->getAttendees() ?? [];
        $existingEmails = array_map(static fn (EventAttendee $a): string => strtolower((string) $a->getEmail()), $existing);

        $additions = [];
        foreach ($emails as $email) {
            if (in_array(strtolower($email), $existingEmails, true)) {
                continue;
            }
            $a = new EventAttendee;
            $a->setEmail($email);
            $additions[] = $a;
            $existingEmails[] = strtolower($email);
        }

        if ($additions === []) {
            return $this->simplifyEvent($event);
        }

        $patch = new Event;
        $patch->setAttendees(array_merge($existing, $additions));

        $updated = $this->tokens->call(
            $account,
            10,
            fn () => $service->events->patch(
                $calendarId,
                $eventId,
                $patch,
                ['sendUpdates' => $this->normalizeNotify($notify)],
            ),
        );

        return $this->simplifyEvent($updated);
    }

    /**
     * RSVP to an event (set the "self" attendee's responseStatus). Like
     * addAttendees, this is read-modify-write of the attendees array.
     */
    public function respondToInvite(
        string $account,
        string $eventId,
        string $response,
        string $calendarId = 'primary',
    ): array {
        $valid = ['accepted', 'declined', 'tentative', 'needsAction'];
        if (! in_array($response, $valid, true)) {
            throw new \InvalidArgumentException('response must be one of: '.implode(', ', $valid));
        }

        $service = $this->calendar($account);
        $event = $this->tokens->call($account, 5, fn () => $service->events->get($calendarId, $eventId));

        $attendees = $event->getAttendees() ?? [];
        $foundSelf = false;
        foreach ($attendees as $attendee) {
            if ($attendee->getSelf()) {
                $attendee->setResponseStatus($response);
                $foundSelf = true;
                break;
            }
        }

        if (! $foundSelf) {
            throw new \RuntimeException("This account is not an attendee on event {$eventId}.");
        }

        $patch = new Event;
        $patch->setAttendees($attendees);

        // sendUpdates default 'none' on RSVP — Google sends notifications via
        // its own UI; we don't want to double-notify other guests on a single
        // person's response.
        $updated = $this->tokens->call(
            $account,
            10,
            fn () => $service->events->patch($calendarId, $eventId, $patch, ['sendUpdates' => 'none']),
        );

        return $this->simplifyEvent($updated);
    }

    /**
     * Cancel/delete an event. Permanent — Calendar has no trash. Notifies
     * attendees by default. This is the only destructive op on Calendar.
     */
    public function cancelEvent(
        string $account,
        string $eventId,
        string $calendarId = 'primary',
        string $notify = 'all',
    ): void {
        $service = $this->calendar($account);
        $this->tokens->call(
            $account,
            10,
            fn () => $service->events->delete(
                $calendarId,
                $eventId,
                ['sendUpdates' => $this->normalizeNotify($notify)],
            ),
        );
    }

    // ----- Service + helpers --------------------------------------------------

    private function calendar(string $account): Calendar
    {
        return new Calendar($this->tokens->authenticatedClient($account));
    }

    /**
     * Build an EventDateTime from the user's spec. Accepts either:
     *   ['dateTime' => 'RFC3339', 'timeZone' => 'IANA?'] for timed events
     *   ['date' => 'YYYY-MM-DD']                          for all-day events
     *
     * @param  array<string, string>  $spec
     */
    private function buildDateTime(array $spec): EventDateTime
    {
        $edt = new EventDateTime;

        if (isset($spec['date']) && $spec['date'] !== '') {
            $edt->setDate($spec['date']);

            return $edt;
        }

        if (! isset($spec['dateTime']) || $spec['dateTime'] === '') {
            throw new \InvalidArgumentException('start/end must specify either dateTime (RFC3339 with offset) or date (YYYY-MM-DD).');
        }

        $edt->setDateTime($spec['dateTime']);
        if (! empty($spec['timeZone'])) {
            $edt->setTimeZone($spec['timeZone']);
        }

        return $edt;
    }

    /**
     * @param  list<string>  $emails
     * @return list<EventAttendee>
     */
    private function buildAttendees(array $emails): array
    {
        $attendees = [];
        foreach ($emails as $email) {
            $a = new EventAttendee;
            $a->setEmail($email);
            $attendees[] = $a;
        }

        return $attendees;
    }

    /**
     * Translate the tool-facing notify enum to Google's sendUpdates values.
     */
    private function normalizeNotify(string $notify): string
    {
        return match ($notify) {
            'none', 'silent' => 'none',
            'external_only', 'externalOnly' => 'externalOnly',
            default => 'all',
        };
    }

    /** @return array<string, mixed> */
    private function simplifyEvent(Event $event): array
    {
        $start = $event->getStart();
        $end = $event->getEnd();

        return [
            'id' => $event->getId(),
            'summary' => $event->getSummary(),
            'description' => $event->getDescription(),
            'location' => $event->getLocation(),
            'status' => $event->getStatus(),
            'start' => $this->simplifyDateTime($start),
            'end' => $this->simplifyDateTime($end),
            'attendees' => array_map($this->simplifyAttendee(...), $event->getAttendees() ?? []),
            'organizer' => $event->getOrganizer() === null ? null : [
                'email' => $event->getOrganizer()->getEmail(),
                'display_name' => $event->getOrganizer()->getDisplayName(),
                'self' => (bool) $event->getOrganizer()->getSelf(),
            ],
            'hangout_link' => $event->getHangoutLink(),
            'html_link' => $event->getHtmlLink(),
            'recurring_event_id' => $event->getRecurringEventId(),
            'recurrence' => $event->getRecurrence() ?? [],
            'created' => $event->getCreated(),
            'updated' => $event->getUpdated(),
            'etag' => $event->getEtag(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function simplifyDateTime(?EventDateTime $edt): ?array
    {
        if ($edt === null) {
            return null;
        }

        return array_filter([
            'date_time' => $edt->getDateTime(),
            'date' => $edt->getDate(),
            'time_zone' => $edt->getTimeZone(),
        ], static fn ($v): bool => $v !== null && $v !== '');
    }

    /** @return array<string, mixed> */
    private function simplifyAttendee(EventAttendee $a): array
    {
        return [
            'email' => $a->getEmail(),
            'display_name' => $a->getDisplayName(),
            'response_status' => $a->getResponseStatus(),
            'optional' => (bool) $a->getOptional(),
            'self' => (bool) $a->getSelf(),
            'organizer' => (bool) $a->getOrganizer(),
        ];
    }
}
