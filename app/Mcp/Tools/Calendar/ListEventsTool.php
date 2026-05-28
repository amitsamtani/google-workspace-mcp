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
#[Name('calendar_list_events')]
#[Description(
    'Check the schedule on an account between two times. Returns events ordered by start time, with recurring '
    .'events expanded into individual instances. Use this to answer "what is on my calendar today/this week". '
    .'Pass page_token to fetch the next page. Reads are not audited.'
)]
class ListEventsTool extends Tool
{
    use InteractsWithGoogleApi;

    public function handle(Request $request, CalendarClient $calendar): ResponseFactory
    {
        $account = (string) $request->get('account');

        if ($guard = $this->guard($account, requireScope: Scopes::CALENDAR_EVENTS)) {
            return $guard;
        }

        $validated = $request->validate([
            'time_min' => ['required', 'string'],
            'time_max' => ['required', 'string'],
            'calendar_id' => ['nullable', 'string'],
            'query' => ['nullable', 'string'],
            'page_token' => ['nullable', 'string'],
            'max_results' => ['nullable', 'integer', 'min:1', 'max:2500'],
        ]);

        return $this->withGoogle($account, fn (): ResponseFactory => $this->structured(
            $calendar->listEvents(
                $account,
                $validated['time_min'],
                $validated['time_max'],
                $validated['calendar_id'] ?? 'primary',
                $validated['query'] ?? null,
                $validated['page_token'] ?? null,
                (int) ($validated['max_results'] ?? 50),
            )
        ));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->string()
                ->description('Email address of the connected account. See gmail_list_accounts.')
                ->required(),
            'time_min' => $schema->string()
                ->description('Lower bound (RFC3339 with timezone offset, e.g. "2026-05-27T00:00:00-07:00"). Filters by event end time.')
                ->required(),
            'time_max' => $schema->string()
                ->description('Upper bound (RFC3339 with timezone offset). Filters by event start time.')
                ->required(),
            'calendar_id' => $schema->string()
                ->description('Calendar id (default "primary"). See calendar_list_calendars.'),
            'query' => $schema->string()
                ->description('Free-text search across event fields.'),
            'page_token' => $schema->string()
                ->description('next_page_token from a previous call.'),
            'max_results' => $schema->integer()
                ->description('Max events to return (1-2500). Default 50.')
                ->min(1)
                ->max(2500),
        ];
    }
}
