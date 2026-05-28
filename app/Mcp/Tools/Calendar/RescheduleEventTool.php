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

#[Name('calendar_reschedule_event')]
#[Description(
    'Move a meeting to a new start/end time. Patches only start/end (other fields are preserved). EMAILS '
    .'ATTENDEES of the change by default (notify="all"); set notify="none" to move silently. Times are RFC3339 '
    .'with a timezone offset. Mutating: audited.'
)]
class RescheduleEventTool extends Tool
{
    use InteractsWithGoogleApi;

    public function handle(Request $request, CalendarClient $calendar, AuditLogger $audit): ResponseFactory
    {
        $account = (string) $request->get('account');

        if ($guard = $this->guard($account, requireScope: Scopes::CALENDAR_EVENTS)) {
            return $guard;
        }

        $validated = $request->validate([
            'event_id' => ['required', 'string'],
            'start' => ['required', 'string'],
            'end' => ['required', 'string'],
            'time_zone' => ['nullable', 'string'],
            'notify' => ['nullable', 'in:all,none,external_only'],
            'calendar_id' => ['nullable', 'string'],
        ]);

        $tz = $validated['time_zone'] ?? null;
        $start = ['dateTime' => $validated['start'], 'timeZone' => $tz];
        $end = ['dateTime' => $validated['end'], 'timeZone' => $tz];

        return $this->withGoogle($account, function () use ($calendar, $audit, $account, $validated, $start, $end): ResponseFactory {
            $event = $audit->around(
                tool: 'calendar_reschedule_event',
                account: $account,
                params: [
                    'event_id' => $validated['event_id'],
                    'start' => $validated['start'],
                    'end' => $validated['end'],
                    'notify' => $validated['notify'] ?? 'all',
                ],
                threadIds: null,
                operation: fn (): array => $calendar->rescheduleEvent(
                    $account,
                    $validated['event_id'],
                    $start,
                    $end,
                    $validated['calendar_id'] ?? 'primary',
                    $validated['notify'] ?? 'all',
                ),
            );

            return $this->structured(['status' => 'rescheduled', 'event' => $event]);
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
            'event_id' => $schema->string()
                ->description('Event id to move.')
                ->required(),
            'start' => $schema->string()
                ->description('New start (RFC3339 with timezone offset).')
                ->required(),
            'end' => $schema->string()
                ->description('New end (RFC3339 with timezone offset).')
                ->required(),
            'time_zone' => $schema->string()
                ->description('IANA timezone (optional).'),
            'notify' => $schema->string()
                ->description('Notify attendees of the change. "all" (default), "external_only", or "none".')
                ->enum(['all', 'none', 'external_only']),
            'calendar_id' => $schema->string()
                ->description('Calendar id (default "primary").'),
        ];
    }
}
