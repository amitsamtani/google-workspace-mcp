# google-workspace-mcp

**Drive multiple Gmail accounts from your terminal, conversationally, through
Claude Code.** A local [MCP](https://modelcontextprotocol.io) server that gives
Claude bulk, multi-account control over Gmail — search, label, archive
thousands of threads at once, and draft replies — with first-run setup handled
*inside the chat*. Bring your own Google Cloud project; your data and
credentials never leave your machine.

> v1 is Gmail-only. The server is namespaced `gmail_*` so `calendar_*` and
> `drive_*` can slot in later without disruption.

---

## Install (30 seconds)

```bash
curl -sSL https://raw.githubusercontent.com/amitsamtani/google-workspace-mcp/main/install.sh | bash
```

The installer checks for PHP 8.3+, Composer, and sqlite3; installs the server
into `~/.google-workspace-mcp`; creates its SQLite database; and prints the
Claude Code MCP config (offering to register it for you via the `claude` CLI).
Re-running it upgrades in place without touching your data or encryption key.

Requirements: **macOS / Linux / WSL**, **PHP 8.3+**, **Composer**, **sqlite3**.

## Then just ask Claude

This is the whole onboarding. Open a terminal and run:

```bash
claude
```

Then say:

> **"Help me set up Gmail."**

Claude walks you through everything — creating your Google Cloud project,
enabling the Gmail API, configuring consent, creating an OAuth client, and
connecting each account — by calling the server's setup tools. **You don't need
to read the rest of this README**; it's here for reference and for power users.

Once set up, talk to it naturally:

> "In amit@acme.com, archive every promo email older than 30 days."
> "Label all unread invoices in finance@beta.io as Accounting/2026."
> "Draft a reply to the latest thread from the landlord in me@gmail.com."

---

## How the MCP config looks

If you skipped auto-registration, add this to your Claude Code MCP config (or
run `claude mcp add --scope user gworkspace -- php ~/.google-workspace-mcp/artisan mcp:start gworkspace`):

```json
{
  "mcpServers": {
    "gworkspace": {
      "command": "php",
      "args": ["/Users/you/.google-workspace-mcp/artisan", "mcp:start", "gworkspace"]
    }
  }
}
```

Use the absolute path the installer printed for your machine.

---

## Bring Your Own Google Cloud Project (BYOGCP)

This server has **no shared backend and no hosted credentials**. Each user
creates their own Google Cloud project and OAuth client, so:

- **You are the data controller.** Your mail, tokens, and credentials stay on
  your machine, encrypted in a local SQLite database.
- **No Google verification dance to depend on** — it's your own project for your
  own use.

Claude sets this up for you conversationally (the in-chat wizard). The manual
steps are below if you'd rather do it yourself.

### ⚠️ Privacy note: you paste credentials into the chat

To save your OAuth Client ID and Secret, you paste them into the Claude chat,
which means **they appear in the chat transcript**.

- Don't share or publish the transcript.
- If a transcript leaks, **rotate the Client Secret** in the Google Cloud
  console (Clients → your client → reset secret).
- A *Desktop* OAuth client secret is lower-sensitivity than a server secret —
  Google's own docs treat it as distributed-in-client and non-confidential —
  but still don't share it casually.

Your credentials are stored **encrypted** (Laravel's `encrypted` cast, keyed by
a local `APP_KEY` the installer generates once) and are **never** written to the
audit log in plaintext.

---

## Manual Google Cloud setup (skip if you use the in-chat wizard)

1. **Create a project** → <https://console.cloud.google.com/projectcreate>
2. **Enable the Gmail API** → <https://console.cloud.google.com/apis/library/gmail.googleapis.com>
3. **Configure the audience / consent** → <https://console.cloud.google.com/auth/audience>
   - If **all** your accounts are in **one** Google Workspace org, choose user
     type **Internal**.
   - Otherwise choose **External** and set the publishing status to **In
     production** (click through the "unverified app" warning — it's your own
     app). **Do not leave an External app in "Testing"**: Google expires its
     refresh tokens after **7 days**, which would force you to re-authorize
     every account weekly.
4. **Create an OAuth client** → <https://console.cloud.google.com/auth/clients>
   - Application type must be **Desktop app** (enables the `127.0.0.1` loopback
     redirect this server uses; **not** "Web application").
5. **Copy the Client ID and Secret** and give them to Claude (or call
   `gmail_save_oauth_credentials`).
6. **Connect accounts**: Claude calls `gmail_start_oauth_flow`, you authorize in
   the browser, and `gmail_complete_oauth_flow` confirms. Repeat per account.

> Google occasionally reorganizes this console area (currently "Google Auth
> Platform"). If a link 404s, navigate by the page name; the steps are stable.

---

## What it can (and can't) do

**Scope: `gmail.modify` only.** The server can read, label, and archive mail and
create drafts. It **cannot send mail** and it **cannot delete or trash**
anything — "archive" only removes the `INBOX` label. There is deliberately no
path to permanent deletion.

### Tools

**Setup & admin** (no `account`):

| Tool | Purpose |
|------|---------|
| `gmail_setup_status` | Current setup state + `next_step`. |
| `gmail_setup_wizard` | Step-by-step BYOGCP walkthrough. |
| `gmail_save_oauth_credentials` | Store Client ID/Secret (encrypted). |
| `gmail_start_oauth_flow` | Begin loopback OAuth; returns a consent URL. |
| `gmail_complete_oauth_flow` | Check/confirm the flow result. |
| `gmail_remove_account` | Revoke at Google + delete locally. |
| `gmail_list_accounts` | Canonical set of connected accounts. |

**Mail** (every tool requires an explicit `account`):

| Tool | Purpose |
|------|---------|
| `gmail_search_threads` | Search (Gmail query syntax); returns ids + snippets, paged. |
| `gmail_get_thread` | Full thread content. |
| `gmail_list_labels` / `gmail_create_label` | Manage labels. |
| `gmail_label_thread` / `gmail_unlabel_thread` | Single-thread label changes. |
| `gmail_archive_thread` | Remove `INBOX` from one thread. |
| `gmail_bulk_archive_threads` | Archive up to **1000** threads/call, per-thread status. |
| `gmail_bulk_label_threads` / `gmail_bulk_unlabel_threads` | Bulk label changes. |
| `gmail_create_draft` | Create a draft (optionally a threaded reply). Never sends. |

Bulk tools batch `threads.modify` over Gmail's HTTP batch endpoint (100 per
round trip) and return a **per-thread status map** — so a 900-thread archive is
a handful of requests, and you see exactly which ids succeeded.

---

## Multi-account model

- The server manages **N** accounts (designed for 3+). There is **no "active
  account"** — every mail tool takes an explicit `account` (the email address).
- Call `gmail_list_accounts` to discover valid accounts. Account values are
  never baked into tool descriptions, so adding/removing accounts needs no
  restart.
- **No fan-out/broadcast tools.** Cross-account work is N explicit calls, so
  every action is individually auditable.

## Architecture

```
Claude Code ──stdio JSON-RPC──> php artisan mcp:start gworkspace
                                   │
                                   ├─ app/Mcp/Servers/GworkspaceServer.php   (tool registry + instructions)
                                   ├─ app/Mcp/Tools/Gmail/*Tool.php          (one class per gmail_* tool)
                                   ├─ app/Mcp/Concerns/InteractsWithGmail.php (guard + error-as-suggestion)
                                   ├─ app/Services/Gmail/GmailClient.php      (refresh / retry / HTTP-batch)
                                   ├─ app/Services/Gmail/OauthFlowManager.php (two-step loopback OAuth)
                                   └─ app/Services/AuditLogger.php           (mutation audit + redaction)

                                   SQLite (~/.google-workspace-mcp/data.sqlite)
                                   ├─ oauth_credentials  (encrypted client id/secret)
                                   ├─ email_accounts     (encrypted refresh tokens, scopes)
                                   └─ mcp_audit_log       (every mutation)
```

Design choices worth knowing:

- **Error-as-suggestion.** When you're not set up, tools return structured JSON
  like `{ "error": "no_oauth_credentials", "suggestion": "Call gmail_setup_wizard…" }`,
  so Claude self-onboards instead of failing.
- **Two-step OAuth.** `gmail_start_oauth_flow` probes a free loopback port,
  spawns a detached listener, and returns immediately; `gmail_complete_oauth_flow`
  reports the result. This avoids blocking the stdio loop for the 5-minute
  consent window.
- **All state in SQLite**, encrypted where sensitive. Behaviour never depends on
  a user-edited config file. The database and `APP_KEY` survive upgrades.
- **stdout is sacred** — it's the JSON-RPC channel. All logging goes to a file.

## Audit log (mutations only)

Every mutating call (label/unlabel/archive/draft + setup mutations) is recorded;
reads are not. Secrets and message bodies are redacted. Query it:

```bash
php artisan gworkspace:audit
php artisan gworkspace:audit --account=amit@acme.com --tool=gmail_bulk_archive_threads
php artisan gworkspace:audit --since="2026-05-01"
```

Other shell helpers: `php artisan gworkspace:list-accounts`,
`php artisan gworkspace:remove-account <email>`.

## Reliability

- Per-account token-bucket rate limiter (Gmail's 250 units/sec/user).
- Exponential backoff with jitter on 429/5xx (max 3 retries).
- Access tokens cached in memory and refreshed ~60s before expiry; refresh
  failures surface as a `reauth_needed` suggestion naming the account.

## Manual install (power users)

```bash
git clone https://github.com/amitsamtani/google-workspace-mcp.git ~/.google-workspace-mcp
cd ~/.google-workspace-mcp
composer install --no-dev --optimize-autoloader
cp .env.example .env
# point DB at an absolute path, then:
echo "DB_DATABASE=$HOME/.google-workspace-mcp/data.sqlite" >> .env
php artisan key:generate          # ONCE — never regenerate, or stored secrets are lost
touch ~/.google-workspace-mcp/data.sqlite
php artisan migrate
claude mcp add --scope user gworkspace -- php ~/.google-workspace-mcp/artisan mcp:start gworkspace
```

## Roadmap

- `calendar_*` — list/create/update events across accounts.
- `drive_*` — search, share, and organize files.
- Optional support for multiple Google Cloud projects (the schema already
  reserves this).

Same server, same multi-account + audit + error-as-suggestion model.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Please preserve the guardrails
(gmail.modify only, no send, no delete, no fan-out, all state in SQLite).

## License

[MIT](LICENSE) © 2026 amitsamtani
