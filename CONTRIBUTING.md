# Contributing to google-workspace-mcp

Thanks for your interest! This project exposes Google Workspace to Claude Code
over MCP. v1 is Gmail-only; the architecture is built to grow into `calendar_*`
and `drive_*` tools on the same server.

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

- **`app/Mcp/Servers/GworkspaceServer.php`** — registers all tools; its
  `#[Instructions]` teach Claude the multi-account + error-as-suggestion model.
- **`app/Mcp/Tools/Gmail/*`** — one class per tool. Reads are annotated
  `#[IsReadOnly]`; mutations write to the audit log. All return
  `Response::structured([...])`.
- **`app/Mcp/Concerns/InteractsWithGmail.php`** — the onboarding `guard()` and
  the structured `error`/`suggestion` payloads.
- **`app/Services/Gmail/*`** — `GmailClient` (refresh/retry/HTTP-batch),
  `GoogleClientFactory`, `OauthFlowManager` (two-step loopback flow),
  `TokenBucketLimiter`, `GmailScopes`.
- **`app/Services/AuditLogger.php`** — mutation audit log, with secret/body
  redaction.

## Adding a Gmail tool

1. `php artisan make:mcp-tool Gmail/MyTool` (or copy an existing tool).
2. Set `#[Name('gmail_my_tool')]` and a `#[Description]` that ends by pointing
   Claude at the relevant follow-up tool. Annotate reads with `#[IsReadOnly]`.
3. Take a required `account` and call `$this->guard($account)` first.
4. Do Gmail work through `GmailClient` (never the Google client directly), so
   rate-limiting and retries apply.
5. For mutations, wrap the work in `AuditLogger::around(...)` or call
   `record(...)`, and add any new secret param keys to `AuditLogger::REDACT`.
6. Register the class in `GworkspaceServer::$tools`.

## Guardrails (please preserve)

- **Never** add a scope beyond `gmail.modify`, and never add send or
  permanent-delete/trash capability. Archive = remove the `INBOX` label only.
- **No** "active account" state and **no** fan-out/broadcast tools — every
  mutation must be one explicit, auditable call against one account.
- All persistent state stays in SQLite. Don't read app behaviour from
  user-editable config files.
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
