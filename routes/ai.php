<?php

use App\Mcp\Servers\GworkspaceServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Servers
|--------------------------------------------------------------------------
|
| The local server is started over stdio by Claude Code via:
|
|     php artisan mcp:start gworkspace
|
| The handle "gworkspace" is the identifier users put in their Claude Code
| MCP config. There is no web/HTTP transport in v1 — this is a local,
| subprocess-spawned server.
|
*/

Mcp::local('gworkspace', GworkspaceServer::class);
