# Contributing to google-workspace-mcp

Thanks for your interest! This project exposes Google Workspace to Claude Code
over MCP. v0.2 covers `gmail_*` and `calendar_*` on one local server; the
architecture is built to grow into `drive_*` on the same server with the same
scope-bundle / re-consent model.

## Development setup

```bash
git clone https://github.com/amitsamtani/google-workspace-mcp.git
cd google-workspace-mcp
composer install
cp .env.example .env
php artisan key:generate
touch data.sqlite        # or set DB_DATABASE in .env
php artisan migrate
```

Run the server over stdio the way Claude Code does:

```bash
php artisan mcp:start gworkspace
```

You can drive it by piping JSON-RPC, or use the MCP inspector:

```bash
php artisan mcp:inspector gworkspace
```

## Architecture at a glance

- **`app/Mcp/Servers/GworkspaceServer.php`** — registers every tool; its
  `#[Instructions]` teach Claude the multi-account + scope-bundle +
  error-as-suggestion model.
- **`app/Mcp/Tools/{Gmail,Calendar}/*`** — one class per tool. Reads are
  annotated `#[IsReadOnly]`; the lone destructive op (`calendar_cancel_event`)
  is `#[IsDestructive]`; mutations write to the audit log. All return
  `Response::structured([...])`.
- **`app/Mcp/Concerns/InteractsWithGoogleApi.php`** — shared trait. `guard()`
  performs the layered onboarding + scope checks (returns
  `no_oauth_credentials`/`no_accounts`/`unknown_account`/`scope_not_granted`
  with a `suggestion`); `withGoogle()` translates service-layer exceptions
  into structured payloads.
- **`app/Services/Google/`** — `Scopes` (single scope registry / bundle) +
  `AccountTokenManager` (per-account access-token cache, refresh-with-rotation,
  rate-limited retry; shared by every API client).
- **`app/Services/Gmail/*`** — `GmailClient` (Gmail mechanics + HTTP batch),
  `OauthFlowManager` (two-step loopback OAuth, requests the bundle),
  `GoogleClientFactory`, `TokenBucketLimiter`.
- **`app/Services/Calendar/CalendarClient.php`** — Calendar mechanics:
  EventDateTime build/parse, attendee read-modify-write, Meet conferenceData.
- **`app/Services/AuditLogger.php`** — mutation audit log, with secret/body
  redaction.
- **`app/Console/Commands/`** — `gworkspace:save-credentials` (hidden secret
  prompt), `audit`, `list-accounts`, `remove-account`, and the hidden internal
  `oauth-listen` (the detached loopback callback listener).

## Adding a tool

1. `php artisan make:mcp-tool {Gmail,Calendar}/MyTool` (or copy an existing tool).
2. Set `#[Name('gmail_my_tool')]` / `#[Name('calendar_my_tool')]` and a
   `#[Description]` that ends by pointing Claude at the relevant follow-up
   tool. Annotate reads with `#[IsReadOnly]`; permanent-effect ops with
   `#[IsDestructive]`.
3. Take a required `account` and call `$this->guard($account, requireScope: <scope>)`
   first — pass the scope your tool actually needs so the account-without-this-scope
   case surfaces as `scope_not_granted`.
4. Do API work through the matching client service (`GmailClient` /
   `CalendarClient`) — never the Google client directly — so rate-limiting,
   retries, and the shared token cache apply.
5. For mutations, wrap the work in `AuditLogger::around(...)` or call
   `record(...)`, and add any new secret/PII param keys to `AuditLogger::REDACT`.
6. Register the class in `GworkspaceServer::$tools`.

## Guardrails (please preserve)

- **Gmail stays archive-only and no-send.** Don't broaden Gmail's scope
  beyond `gmail.modify` and don't add send / permanent-delete / trash. Archive
  means remove the `INBOX` label only.
- **Calendar exposes a `notify` parameter on every attendee-affecting op**
  (`all` / `external_only` / `none`) so the user can stay silent when they
  want to. Default `all` (an invite no one receives is useless), but tool
  descriptions must say plainly that they email people.
- **No** "active account" state and **no** fan-out/broadcast tools — every
  mutation is one explicit, auditable call against one account.
- All persistent state stays in SQLite. Don't read app behaviour from
  user-editable config files (the auto-generated `.env` is the only exception,
  and it's only there because Laravel needs `APP_KEY`).
- Tool descriptions must not embed account values (discovery is via
  `gmail_list_accounts` so adding/removing accounts needs no restart).
- Nothing may write to **stdout** except the MCP protocol — logs go to a file.

## Code style

Run Pint before opening a PR:

```bash
./vendor/bin/pint
```

## Pull requests

Keep PRs focused. Describe the user-facing behaviour and include a sample
JSON-RPC `tools/call` showing the new/changed tool's response. By contributing
you agree your work is licensed under the MIT License.
