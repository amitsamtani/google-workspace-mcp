<?php

namespace App\Mcp\Tools\Calendar;

use App\Mcp\Concerns\InteractsWithGoogleApi;
use App\Services\AuditLogger;
use App\Services\Calendar\CalendarClient;
use App\Services\Google\Scopes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('calendar_create_event')]
#[Description(
    'Schedule a new event. EMAILS ATTENDEES by default (sendUpdates=all) — set notify="none" for a silent draft. '
    .'Pass add_meet=true to attach a Google Meet link. Pass recurrence (RRULE strings, e.g. '
    .'["RRULE:FREQ=WEEKLY;BYDAY=MO"]) for a recurring event (a time_zone is then strongly recommended). '
    .'Times are RFC3339 with a timezone offset. Mutating: audited.'
)]
class CreateEventTool extends Tool
{
    use InteractsWithGoogleApi;

    public function handle(Request $request, CalendarClient $calendar, AuditLogger $audit): ResponseFactory
    {
        $account = (string) $request->get('account');

        if ($guard = $this->guard($account, requireScope: Scopes::CALENDAR_EVENTS)) {
            return $guard;
        }

        $validated = $request->validate([
            'summary' => ['required', 'string'],
            'start' => ['required', 'string'],
            'end' => ['required', 'string'],
            'time_zone' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string'],
            'attendees' => ['nullable', 'array'],
            'attendees.*' => ['email'],
            'add_meet' => ['nullable', 'boolean'],
            'recurrence' => ['nullable', 'array'],
            'recurrence.*' => ['string'],
            'notify' => ['nullable', 'in:all,none,external_only'],
            'calendar_id' => ['nullable', 'string'],
        ]);

        $tz = $validated['time_zone'] ?? null;
        $start = ['dateTime' => $validated['start'], 'timeZone' => $tz];
        $end = ['dateTime' => $validated['end'], 'timeZone' => $tz];

        return $this->withGoogle($account, function () use ($calendar, $audit, $account, $validated, $start, $end): ResponseFactory {
            $event = $audit->around(
                tool: 'calendar_create_event',
                account: $account,
                params: [
                    'summary' => $validated['summary'],
                    'start' => $validated['start'],
                    'end' => $validated['end'],
                    'attendees' => $validated['attendees'] ?? [],
                    'add_meet' => $validated['add_meet'] ?? false,
                    'notify' => $validated['notify'] ?? 'all',
                    'recurrence' => $validated['recurrence'] ?? [],
                ],
                threadIds: null,
                operation: fn (): array => $calendar->createEvent(
                    $account,
                    $validated['summary'],
                    $start,
                    $end,
                    $validated['attendees'] ?? [],
                    $validated['description'] ?? null,
                    $validated['location'] ?? null,
                    $validated['recurrence'] ?? [],
                    (bool) ($validated['add_meet'] ?? false),
                    $validated['notify'] ?? 'all',
                    $validated['calendar_id'] ?? 'primary',
                ),
            );

            return $this->structured(['status' => 'created', 'event' => $event]);
        });
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->string()
                ->description('Email address of the connected account.')
                ->required(),
            'summary' => $schema->string()
                ->description('Event title.')
                ->required(),
            'start' => $schema->string()
                ->description('Start (RFC3339 with timezone offset, e.g. "2026-05-27T14:00:00-07:00").')
                ->required(),
            'end' => $schema->string()
                ->description('End (RFC3339 with timezone offset).')
                ->required(),
            'time_zone' => $schema->string()
                ->description('IANA timezone (e.g. "America/Los_Angeles"). Strongly recommended for recurring events.'),
            'description' => $schema->string()
                ->description('Event description / agenda.'),
            'location' => $schema->string()
                ->description('Physical location (free-form).'),
            'attendees' => $schema->array()
                ->description('Email addresses to invite.')
                ->items($schema->string()),
            'add_meet' => $schema->boolean()
                ->description('Attach a Google Meet link to the event (default false).'),
            'recurrence' => $schema->array()
                ->description('RRULE strings for recurring events (e.g. ["RRULE:FREQ=WEEKLY;BYDAY=MO"]).')
                ->items($schema->string()),
            'notify' => $schema->string()
                ->description('Who to email about the new event. "all" (default), "external_only", or "none".')
                ->enum(['all', 'none', 'external_only']),
            'calendar_id' => $schema->string()
                ->description('Calendar id (default "primary").'),
        ];
    }
}
