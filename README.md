# google-workspace-mcp

**Drive multiple Google Workspace accounts from your terminal, conversationally,
through Claude Code.** A local [MCP](https://modelcontextprotocol.io) server
that gives Claude bulk, multi-account control over **Gmail and Google Calendar**
— search/label/archive thousands of threads, draft replies, schedule meetings
with Meet links, reschedule, RSVP, and find free time across calendars — with
first-run setup handled *inside the chat*. Bring your own Google Cloud project;
your data and credentials never leave your machine.

> v0.2 ships `gmail_*` (27 tools total) and `calendar_*` on one local server.
> `drive_*` is next on the same server.

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

> **"Help me set up Google Workspace."**

Claude walks you through everything — creating your Google Cloud project,
enabling the Gmail + Calendar APIs, configuring consent, creating an OAuth
client, and connecting each account — by calling the server's setup tools.
**You don't need to read the rest of this README**; it's here for reference and
for power users.

Once set up, talk to it naturally:

> "In amit@acme.com, archive every promo email older than 30 days."
> "Label all unread invoices in finance@beta.io as Accounting/2026."
> "Draft a reply to the latest thread from the landlord in me@gmail.com."
> "Schedule a 30-min sync with founder@example.com tomorrow at 2pm PT, add a
> Meet link."
> "When am I free for an hour across all three accounts this Friday?"
> "Move my 3pm to 4pm and tell them."

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
2. **Enable BOTH APIs** in your project:
   - Gmail API → <https://console.cloud.google.com/apis/library/gmail.googleapis.com>
   - Google Calendar API → <https://console.cloud.google.com/apis/library/calendar-json.googleapis.com>
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

**Scopes (least-privilege bundle):**
`gmail.modify` + `calendar.events` + `calendar.freebusy` + `calendar.calendarlist.readonly`.
One consent grants the whole bundle per account; accounts connected before
Calendar shipped can be re-authorized with the same flow (the server returns a
clean `scope_not_granted` payload telling Claude to do it).

**Gmail safety posture (unchanged from v1):** read/label/archive/drafts only;
**cannot send mail**, **cannot delete or trash** anything. "Archive" only
removes the `INBOX` label.

**Calendar safety posture (intentional deltas, called out clearly):**
- **Calendar tools email people** on insert/change/cancel by default
  (`sendUpdates=all`). Each such tool exposes a `notify` param —
  `all` / `external_only` / `none`. Tool descriptions state plainly when they
  email.
- **`calendar_cancel_event` is permanent** — Calendar has no trash. It's the
  one destructive operation on the server and is marked `#[IsDestructive]` +
  audited.

### Tools

**Setup & admin** (no `account`):

| Tool | Purpose |
|------|---------|
| `gmail_setup_status` | Current setup state + `next_step`. |
| `gmail_setup_wizard` | Step-by-step BYOGCP walkthrough. |
| `gmail_save_oauth_credentials` | Store Client ID/Secret (encrypted). |
| `gmail_start_oauth_flow` | Begin loopback OAuth; returns a consent URL. Requests the full Gmail + Calendar scope bundle. |
| `gmail_complete_oauth_flow` | Check/confirm the flow result. |
| `gmail_remove_account` | Revoke at Google + delete locally. |
| `gmail_list_accounts` | Canonical set of connected accounts (with each account's granted scopes). |

**Mail** (every tool requires an explicit `account`):

| Tool | Purpose |
|------|---------|
| `gmail_search_threads` | Search (Gmail query syntax); returns ids + snippets, paged. |
| `gmail_get_thread` | Full thread content (recursive plaintext body extraction). |
| `gmail_list_labels` / `gmail_create_label` | Manage labels. |
| `gmail_label_thread` / `gmail_unlabel_thread` | Single-thread label changes. |
| `gmail_archive_thread` | Remove `INBOX` from one thread. |
| `gmail_bulk_archive_threads` | Archive up to **1000** threads/call, per-thread status. |
| `gmail_bulk_label_threads` / `gmail_bulk_unlabel_threads` | Bulk label changes. |
| `gmail_create_draft` | Create a draft (optionally a threaded reply; RFC 2047 subject encoding). Never sends. |

**Calendar** (every tool requires an explicit `account`):

| Tool | Purpose |
|------|---------|
| `calendar_list_calendars` | Calendars on the account (use ids with the rest; `primary` is always valid). |
| `calendar_list_events` | Check schedule between two times; recurring events expanded. |
| `calendar_get_event` | Full event details (attendees, response statuses, recurrence). |
| `calendar_find_free_time` | Free/busy across one or more calendars (narrow scope, no event details). |
| `calendar_create_event` | Schedule a meeting; attendees, optional Meet link, optional RRULE recurrence; emails by default. |
| `calendar_reschedule_event` | Move via `events.patch` (preserves other fields); emails by default. |
| `calendar_add_attendees` | Invite more people to an existing event (read-modify-write); emails by default. |
| `calendar_respond_to_event` | RSVP (accepted/declined/tentative/needsAction). |
| `calendar_cancel_event` | **Destructive.** `events.delete`; emails attendees by default. |

Bulk Gmail tools batch `threads.modify` over Gmail's HTTP batch endpoint
(100 per round trip) and return a **per-thread status map** — so a 900-thread
archive is a handful of requests, and you see exactly which ids succeeded.

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
                                   ├─ app/Mcp/Servers/GworkspaceServer.php       (tool registry + instructions)
                                   ├─ app/Mcp/Tools/Gmail/*Tool.php              (gmail_* tools)
                                   ├─ app/Mcp/Tools/Calendar/*Tool.php           (calendar_* tools)
                                   ├─ app/Mcp/Concerns/InteractsWithGoogleApi.php (scope-aware guard + error-as-suggestion)
                                   ├─ app/Services/Google/Scopes.php             (single scope registry)
                                   ├─ app/Services/Google/AccountTokenManager.php (token cache / refresh / retry / rate-limit — shared)
                                   ├─ app/Services/Gmail/GmailClient.php          (Gmail mechanics + HTTP batch)
                                   ├─ app/Services/Calendar/CalendarClient.php    (Calendar mechanics + EventDateTime/attendee helpers)
                                   ├─ app/Services/Gmail/OauthFlowManager.php     (two-step loopback OAuth; requests the bundle)
                                   └─ app/Services/AuditLogger.php               (mutation audit + redaction)

                                   SQLite (~/.google-workspace-mcp/data.sqlite)
                                   ├─ oauth_credentials  (encrypted client id/secret)
                                   ├─ email_accounts     (encrypted refresh tokens, actually-granted scopes per account)
                                   └─ mcp_audit_log      (every mutation, gmail and calendar)
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

Every mutating call — Gmail label/unlabel/archive/draft + Calendar
create/reschedule/add-attendees/RSVP/cancel + setup mutations — is recorded;
reads are not. Secrets and message bodies are redacted. Query it:

```bash
php artisan gworkspace:audit
php artisan gworkspace:audit --account=amit@acme.com --tool=gmail_bulk_archive_threads
php artisan gworkspace:audit --since="2026-05-01"
```

Other shell helpers:

```bash
php artisan gworkspace:save-credentials      # hidden secret prompt — keeps the secret out of any transcript
php artisan gworkspace:list-accounts
php artisan gworkspace:remove-account <email>
```

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

- `drive_*` — search, share, and organize files (on the same server, same
  scope bundle / re-consent model).
- Optional support for multiple Google Cloud projects (the schema already
  reserves this).
- Pest test suite for the pure logic (guards, wizard state, audit redaction,
  EventDateTime building, MIME).

Same server, same multi-account + audit + error-as-suggestion model.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Please preserve the guardrails: Gmail
stays archive-only / no-send; Calendar tools always expose a `notify`
parameter (default `all` so invites actually reach people, but Claude can be
told to stay silent); no fan-out broadcast tools; all state in SQLite.

## License

[MIT](LICENSE) © 2026 amitsamtani
