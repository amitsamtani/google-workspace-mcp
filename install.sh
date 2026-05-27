#!/usr/bin/env bash
#
# google-workspace-mcp installer (macOS / Linux / WSL).
#
#   curl -sSL https://raw.githubusercontent.com/amitsamtani/google-workspace-mcp/main/install.sh | bash
#
# Installs the MCP server into ~/.google-workspace-mcp, creates its SQLite
# database, and prints (or, with your confirmation, writes) the Claude Code
# MCP config. Re-running it upgrades in place WITHOUT touching your data or
# encryption key.

set -euo pipefail

REPO_URL="${GWMCP_REPO_URL:-https://github.com/amitsamtani/google-workspace-mcp.git}"
INSTALL_DIR="${GWMCP_HOME:-$HOME/.google-workspace-mcp}"
DB_PATH="$INSTALL_DIR/data.sqlite"
SERVER_HANDLE="gworkspace"

bold() { printf '\033[1m%s\033[0m\n' "$1"; }
ok()   { printf '\033[32m✓\033[0m %s\n' "$1"; }
warn() { printf '\033[33m!\033[0m %s\n' "$1"; }
die()  { printf '\033[31m✗ %s\033[0m\n' "$1" >&2; exit 1; }

# ----------------------------------------------------------------------------
# 1. Prerequisites
# ----------------------------------------------------------------------------
bold "Checking prerequisites…"

command -v php >/dev/null 2>&1 || die "PHP not found. Install PHP 8.3+ (e.g. 'brew install php')."
php -r 'exit(version_compare(PHP_VERSION, "8.3.0", "<") ? 1 : 0);' \
  || die "PHP $(php -r 'echo PHP_VERSION;') is too old. This needs PHP 8.3+."
ok "PHP $(php -r 'echo PHP_VERSION;')"

command -v composer >/dev/null 2>&1 || die "Composer not found. See https://getcomposer.org/download/"
ok "Composer $(composer --version 2>/dev/null | awk '{print $3}')"

command -v sqlite3 >/dev/null 2>&1 || die "sqlite3 not found. Install it (e.g. 'brew install sqlite')."
ok "sqlite3 $(sqlite3 --version | awk '{print $1}')"

command -v git >/dev/null 2>&1 || die "git not found."
ok "git $(git --version | awk '{print $3}')"

# ----------------------------------------------------------------------------
# 2. Fetch / update the code (your data dir is never wiped)
# ----------------------------------------------------------------------------
if [ -d "$INSTALL_DIR/.git" ]; then
  bold "Upgrading existing install at $INSTALL_DIR…"
  git -C "$INSTALL_DIR" pull --ff-only || die "git pull failed. Resolve manually in $INSTALL_DIR."
  UPGRADE=1
else
  [ -e "$INSTALL_DIR" ] && die "$INSTALL_DIR exists but is not a git checkout. Move it aside and re-run."
  bold "Cloning into $INSTALL_DIR…"
  git clone --depth 1 "$REPO_URL" "$INSTALL_DIR"
  UPGRADE=0
fi

cd "$INSTALL_DIR"

bold "Installing PHP dependencies…"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
ok "Dependencies installed"

# ----------------------------------------------------------------------------
# 3. Environment + encryption key (generated ONCE; never regenerated)
# ----------------------------------------------------------------------------
if [ ! -f .env ]; then
  cp .env.example .env
  # Pin the DB to an absolute path so the server works regardless of cwd.
  grep -v '^DB_DATABASE=' .env > .env.tmp && mv .env.tmp .env
  printf 'DB_DATABASE=%s\n' "$DB_PATH" >> .env
  php artisan key:generate --force --no-interaction >/dev/null
  ok "Created .env and generated APP_KEY (your encryption key — backed by this file)"
else
  warn "Existing .env kept (APP_KEY and DB path preserved). Encryption key NOT regenerated."
fi

# ----------------------------------------------------------------------------
# 4. Database (survives upgrades — never recreated if present)
# ----------------------------------------------------------------------------
touch "$DB_PATH"
php artisan migrate --force --no-interaction >/dev/null
ok "SQLite database ready at $DB_PATH"

php artisan config:clear --no-interaction >/dev/null 2>&1 || true

# ----------------------------------------------------------------------------
# 5. Claude Code MCP config
# ----------------------------------------------------------------------------
ARTISAN_PATH="$INSTALL_DIR/artisan"
PHP_BIN="$(command -v php)"

SNIPPET=$(cat <<JSON
{
  "mcpServers": {
    "$SERVER_HANDLE": {
      "command": "$PHP_BIN",
      "args": ["$ARTISAN_PATH", "mcp:start", "$SERVER_HANDLE"]
    }
  }
}
JSON
)

echo
bold "Add this MCP server to Claude Code:"
echo "$SNIPPET"
echo

if command -v claude >/dev/null 2>&1; then
  printf "Register it now with the Claude CLI? [y/N] "
  read -r REPLY </dev/tty || REPLY="n"
  if [ "$REPLY" = "y" ] || [ "$REPLY" = "Y" ]; then
    claude mcp add --scope user "$SERVER_HANDLE" -- "$PHP_BIN" "$ARTISAN_PATH" mcp:start "$SERVER_HANDLE" \
      && ok "Registered '$SERVER_HANDLE' with Claude Code (user scope)." \
      || warn "Could not auto-register. Add the snippet above manually."
  else
    warn "Skipped auto-registration. Paste the snippet into your Claude Code MCP config."
  fi
else
  warn "Claude CLI not found on PATH. Paste the snippet above into your Claude Code MCP config,"
  warn "or run:  claude mcp add --scope user $SERVER_HANDLE -- $PHP_BIN $ARTISAN_PATH mcp:start $SERVER_HANDLE"
fi

echo
if [ "$UPGRADE" = "1" ]; then
  bold "Upgrade complete."
else
  bold "Install complete."
fi
echo "Next: run 'claude' in your terminal and say: \"Help me set up Gmail.\""
echo "Claude will walk you through creating your Google Cloud project and connecting accounts."
