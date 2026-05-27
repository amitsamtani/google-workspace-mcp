<?php

namespace App\Mcp\Servers;

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
#[Version('0.1.0')]
#[Instructions(<<<'TXT'
This server exposes Google Workspace (Gmail in v1) over MCP, managing multiple
accounts at once. Tools are namespaced gmail_* (calendar_* and drive_* arrive
later on this same server).

MULTI-ACCOUNT: there is no "active account". Every mail tool requires an
explicit `account` (the email address). Call gmail_list_accounts at the start
of cross-account work to learn the canonical set; never assume account values.

ONBOARDING & ERROR-AS-SUGGESTION: tools return structured JSON. When the user
is not set up, you get a recoverable payload with an `error` code and a
`suggestion` naming the next tool to call:
  - no_oauth_credentials -> call gmail_setup_wizard
  - no_accounts          -> call gmail_setup_status
  - reauth_needed        -> call gmail_start_oauth_flow with the given email_hint
  - unknown_account      -> use one of the listed valid_accounts
Follow the suggestion. A brand-new user with nothing configured should be
onboarded entirely through these tools (gmail_setup_wizard walks them through
creating their own Google Cloud project) — they should not need to read docs.

SAFETY: this server has gmail.modify scope only. It can read, label, and
archive mail and create drafts, but it CANNOT send mail or permanently
delete/trash anything. Archiving only removes the INBOX label.
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
    ];

    /** @var array<int, class-string> */
    protected array $resources = [];

    /** @var array<int, class-string> */
    protected array $prompts = [];
}
