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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Name('calendar_cancel_event')]
#[Description(
    'Cancel/delete an event. PERMANENT — Calendar has no trash. EMAILS attendees of the cancellation by default '
    .'(notify="all"); set notify="none" for silent removal. This is the only destructive operation in the calendar '
    .'tool surface. Mutating: audited.'
)]
class CancelEventTool extends Tool
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
            'notify' => ['nullable', 'in:all,none,external_only'],
            'calendar_id' => ['nullable', 'string'],
        ]);

        return $this->withGoogle($account, function () use ($calendar, $audit, $account, $validated): ResponseFactory {
            $audit->around(
                tool: 'calendar_cancel_event',
                account: $account,
                params: [
                    'event_id' => $validated['event_id'],
                    'notify' => $validated['notify'] ?? 'all',
                ],
                threadIds: null,
                operation: fn () => $calendar->cancelEvent(
                    $account,
                    $validated['event_id'],
                    $validated['calendar_id'] ?? 'primary',
                    $validated['notify'] ?? 'all',
                ),
            );

            return $this->structured(['status' => 'cancelled', 'event_id' => $validated['event_id']]);
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
                ->description('Event id to cancel/delete.')
                ->required(),
            'notify' => $schema->string()
                ->description('Notify attendees of the cancellation. "all" (default), "external_only", or "none".')
                ->enum(['all', 'none', 'external_only']),
            'calendar_id' => $schema->string()
                ->description('Calendar id (default "primary").'),
        ];
    }
}
