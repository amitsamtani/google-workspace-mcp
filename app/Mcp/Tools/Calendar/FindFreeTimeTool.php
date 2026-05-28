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
#[Name('calendar_find_free_time')]
#[Description(
    'Free/busy lookup across one or more calendars. Returns ONLY busy intervals (no event details — by design; that '
    .'is what the narrow calendar.freebusy scope authorizes). To find a meeting slot across multiple of the user\'s '
    .'accounts, call this once per account and intersect the gaps yourself. For one account, pass several '
    .'calendar_ids (primary + colleagues you have access to) in one call.'
)]
class FindFreeTimeTool extends Tool
{
    use InteractsWithGoogleApi;

    public function handle(Request $request, CalendarClient $calendar): ResponseFactory
    {
        $account = (string) $request->get('account');

        if ($guard = $this->guard($account, requireScope: Scopes::CALENDAR_FREEBUSY)) {
            return $guard;
        }

        $validated = $request->validate([
            'time_min' => ['required', 'string'],
            'time_max' => ['required', 'string'],
            'calendar_ids' => ['nullable', 'array'],
            'calendar_ids.*' => ['string'],
            'time_zone' => ['nullable', 'string'],
        ]);

        $calendarIds = $validated['calendar_ids'] ?? ['primary'];

        return $this->withGoogle($account, fn (): ResponseFactory => $this->structured(
            $calendar->findFreeBusy(
                $account,
                $validated['time_min'],
                $validated['time_max'],
                $calendarIds,
                $validated['time_zone'] ?? null,
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
                ->description('Email address of the connected account.')
                ->required(),
            'time_min' => $schema->string()
                ->description('Lower bound (RFC3339 with timezone offset).')
                ->required(),
            'time_max' => $schema->string()
                ->description('Upper bound (RFC3339 with timezone offset).')
                ->required(),
            'calendar_ids' => $schema->array()
                ->description('Calendars to query (default ["primary"]). Each is a calendar id or an email address.')
                ->items($schema->string()),
            'time_zone' => $schema->string()
                ->description('IANA timezone for the response (e.g. "America/Los_Angeles"). Defaults to UTC.'),
        ];
    }
}
