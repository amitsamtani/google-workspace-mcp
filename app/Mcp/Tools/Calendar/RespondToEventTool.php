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

#[Name('calendar_respond_to_event')]
#[Description(
    'RSVP to an event you are invited to: set your response to accepted, declined, tentative, or needsAction. '
    .'Implementation patches the event\'s self-attendee responseStatus. Does NOT email the other guests (Google '
    .'handles RSVP notifications natively). Errors if the account is not an attendee on the event. Mutating: audited.'
)]
class RespondToEventTool extends Tool
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
            'response' => ['required', 'in:accepted,declined,tentative,needsAction'],
            'calendar_id' => ['nullable', 'string'],
        ]);

        return $this->withGoogle($account, function () use ($calendar, $audit, $account, $validated): ResponseFactory {
            $event = $audit->around(
                tool: 'calendar_respond_to_event',
                account: $account,
                params: ['event_id' => $validated['event_id'], 'response' => $validated['response']],
                threadIds: null,
                operation: fn (): array => $calendar->respondToInvite(
                    $account,
                    $validated['event_id'],
                    $validated['response'],
                    $validated['calendar_id'] ?? 'primary',
                ),
            );

            return $this->structured(['status' => 'responded', 'response' => $validated['response'], 'event' => $event]);
        });
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->string()
                ->description('Email address of the connected account (must be an attendee on the event).')
                ->required(),
            'event_id' => $schema->string()
                ->description('Event id to RSVP to.')
                ->required(),
            'response' => $schema->string()
                ->description('Your RSVP.')
                ->enum(['accepted', 'declined', 'tentative', 'needsAction'])
                ->required(),
            'calendar_id' => $schema->string()
                ->description('Calendar id (default "primary").'),
        ];
    }
}
