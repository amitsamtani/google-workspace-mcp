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

#[Name('calendar_add_attendees')]
#[Description(
    'Invite additional attendees to an existing event. Reads the current attendees, appends the new ones (skipping '
    .'duplicates), and patches the event back. EMAILS the new (and existing) attendees by default; set notify='
    .'"external_only" to email only non-Google guests or "none" for silent. Mutating: audited.'
)]
class AddAttendeesTool extends Tool
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
            'attendees' => ['required', 'array', 'min:1'],
            'attendees.*' => ['email'],
            'notify' => ['nullable', 'in:all,none,external_only'],
            'calendar_id' => ['nullable', 'string'],
        ]);

        return $this->withGoogle($account, function () use ($calendar, $audit, $account, $validated): ResponseFactory {
            $event = $audit->around(
                tool: 'calendar_add_attendees',
                account: $account,
                params: [
                    'event_id' => $validated['event_id'],
                    'attendees' => $validated['attendees'],
                    'notify' => $validated['notify'] ?? 'all',
                ],
                threadIds: null,
                operation: fn (): array => $calendar->addAttendees(
                    $account,
                    $validated['event_id'],
                    $validated['attendees'],
                    $validated['calendar_id'] ?? 'primary',
                    $validated['notify'] ?? 'all',
                ),
            );

            return $this->structured(['status' => 'updated', 'event' => $event]);
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
                ->description('Event id to modify.')
                ->required(),
            'attendees' => $schema->array()
                ->description('Email addresses to invite (duplicates are skipped).')
                ->items($schema->string())
                ->min(1)
                ->required(),
            'notify' => $schema->string()
                ->description('Who to email. "all" (default), "external_only", or "none".')
                ->enum(['all', 'none', 'external_only']),
            'calendar_id' => $schema->string()
                ->description('Calendar id (default "primary").'),
        ];
    }
}
