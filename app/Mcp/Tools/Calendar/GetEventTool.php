<?php

namespace App\Mcp\Tools\Calendar;

use App\Mcp\Concerns\InteractsWithGoogleApi;
use App\Services\Calendar\CalendarClient;
use App\Services\Google\Scopes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('calendar_get_event')]
#[Description(
    'Fetch full details for one event (summary, start/end, attendees with response statuses, location, Meet link, '
    .'recurrence). Use after calendar_list_events when you need full attendee info.'
)]
class GetEventTool extends Tool
{
    use InteractsWithGoogleApi;

    public function handle(Request $request, CalendarClient $calendar): ResponseFactory
    {
        $account = (string) $request->get('account');

        if ($guard = $this->guard($account, requireScope: Scopes::CALENDAR_EVENTS)) {
            return $guard;
        }

        $validated = $request->validate([
            'event_id' => ['required', 'string'],
            'calendar_id' => ['nullable', 'string'],
        ]);

        return $this->withGoogle($account, fn (): ResponseFactory => $this->structured(
            $calendar->getEvent($account, $validated['event_id'], $validated['calendar_id'] ?? 'primary')
        ));
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
                ->description('Event id from calendar_list_events.')
                ->required(),
            'calendar_id' => $schema->string()
                ->description('Calendar id (default "primary").'),
        ];
    }
}
