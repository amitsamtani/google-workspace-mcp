<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Calendar\AddAttendeesTool;
use App\Mcp\Tools\Calendar\CancelEventTool;
use App\Mcp\Tools\Calendar\CreateEventTool;
use App\Mcp\Tools\Calendar\FindFreeTimeTool;
use App\Mcp\Tools\Calendar\GetEventTool;
use App\Mcp\Tools\Calendar\ListCalendarsTool;
use App\Mcp\Tools\Calendar\ListEventsTool;
use App\Mcp\Tools\Calendar\RescheduleEventTool;
use App\Mcp\Tools\Calendar\RespondToEventTool;
use App\Mcp\Tools\Gmail\ArchiveThreadTool;
use App\Mcp\Tools\Gmail\BulkArchiveThreadsTool;
use App\Mcp\Tools\Gmail\BulkLabelThreadsTool;
use App\Mcp\Tools\Gmail\BulkUnlabelThreadsTool;
use App\Mcp\Tools\Gmail\CompleteOauthFlowTool;
use App\Mcp\Tools\Gmail\CreateDraftTool;
use App\Mcp\Tools\Gmail\CreateLabelTool;
use App\Mcp\Tools\Gmail\GetThreadTool;
use App\Mcp\Tools\Gmail\LabelThreadTool;
use App\Mcp\Tools\Gmail\ListAccountsTool;
use App\Mcp\Tools\Gmail\ListLabelsTool;
use App\Mcp\Tools\Gmail\RemoveAccountTool;
use App\Mcp\Tools\Gmail\SaveOauthCredentialsTool;
use App\Mcp\Tools\Gmail\SearchThreadsTool;
use App\Mcp\Tools\Gmail\SetupStatusTool;
use App\Mcp\Tools\Gmail\SetupWizardTool;
use App\Mcp\Tools\Gmail\StartOauthFlowTool;
use App\Mcp\Tools\Gmail\UnlabelThreadTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('google-workspace-mcp')]
#[Version('0.2.0')]
#[Instructions(<<<'TXT'
This server exposes Google Workspace over MCP, managing multiple accounts at
once. Tools are namespaced gmail_* (mail) and calendar_* (scheduling); drive_*
will arrive later on this same server.

MULTI-ACCOUNT: there is no "active account". Every mail or calendar tool
requires an explicit `account` (the email address). Call gmail_list_accounts
at the start of cross-account work to learn the canonical set; never assume
account values. There are no fan-out tools — cross-account work is N explicit
calls so every action is individually auditable.

ONBOARDING & ERROR-AS-SUGGESTION: tools return structured JSON. When the user
is not set up, you get a recoverable payload with an `error` code and a
`suggestion` naming the next tool to call:
  - no_oauth_credentials -> call gmail_setup_wizard
  - no_accounts          -> call gmail_setup_status
  - reauth_needed        -> call gmail_start_oauth_flow with the given email_hint
  - unknown_account      -> use one of the listed valid_accounts
  - scope_not_granted    -> the account has not yet authorized this capability.
                            Call gmail_start_oauth_flow with email_hint=<account>
                            to re-authorize and grant the missing scope.
Follow the suggestion. A brand-new user with nothing configured should be
onboarded entirely through these tools (gmail_setup_wizard walks them through
creating their own Google Cloud project) — they should not need to read docs.

SCOPES & RE-CONSENT: one consent grants Gmail + Calendar (+ Drive later).
gmail_start_oauth_flow requests the full bundle. Accounts connected before
Calendar shipped will not yet have Calendar scopes and must be re-authorized
once; the scope_not_granted payload guides this. Each account's actually-
granted scopes are visible via gmail_list_accounts / gmail_setup_status.

SAFETY: Gmail stays archive-only and no-send (gmail.modify scope; cannot send
mail, cannot permanently delete — archive only removes the INBOX label).
Calendar can create, modify, RSVP to, and CANCEL events; calendar_cancel_event
is the only destructive operation in this server's surface and is permanent.

INVITES EMAIL PEOPLE: calendar_create_event, calendar_reschedule_event,
calendar_add_attendees, and calendar_cancel_event email attendees by default
(notify="all"). Pass notify="none" or "external_only" to control this. Tool
descriptions state plainly when they email.
TXT)]
class GworkspaceServer extends Server
{
    /** @var array<int, class-string> */
    protected array $tools = [
        // Setup & admin (no account parameter)
        SetupStatusTool::class,
        SetupWizardTool::class,
        SaveOauthCredentialsTool::class,
        StartOauthFlowTool::class,
        CompleteOauthFlowTool::class,
        RemoveAccountTool::class,
        ListAccountsTool::class,

        // Mail (every tool takes `account`)
        SearchThreadsTool::class,
        GetThreadTool::class,
        ListLabelsTool::class,
        CreateLabelTool::class,
        LabelThreadTool::class,
        UnlabelThreadTool::class,
        ArchiveThreadTool::class,
        BulkArchiveThreadsTool::class,
        BulkLabelThreadsTool::class,
        BulkUnlabelThreadsTool::class,
        CreateDraftTool::class,

        // Calendar (every tool takes `account`; emails attendees by default
        // on attendee-affecting ops — see Instructions)
        ListCalendarsTool::class,
        ListEventsTool::class,
        GetEventTool::class,
        FindFreeTimeTool::class,
        CreateEventTool::class,
        RescheduleEventTool::class,
        AddAttendeesTool::class,
        RespondToEventTool::class,
        CancelEventTool::class,
    ];

    /** @var array<int, class-string> */
    protected array $resources = [];

    /** @var array<int, class-string> */
    protected array $prompts = [];
}
