<?php

namespace App\Mcp\Tools\Gmail;

use App\Mcp\Concerns\InteractsWithGmail;
use App\Services\Gmail\OauthFlowManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('gmail_complete_oauth_flow')]
#[Description(
    'Check the result of a gmail_start_oauth_flow. Pass the state returned by start (or omit to use the most '
    .'recent flow). Returns one of: { status: "pending" } (user has not finished authorizing yet — wait and call '
    .'again), { status: "added", account } (success), or an error with status "failed"/"timeout"/"expired". '
    .'Poll this every few seconds while the user completes the browser consent.'
)]
class CompleteOauthFlowTool extends Tool
{
    use InteractsWithGmail;

    public function handle(Request $request, OauthFlowManager $flow): ResponseFactory
    {
        $state = $request->get('state');
        $status = $flow->status(is_string($state) && $state !== '' ? $state : null);

        return match ($status['status'] ?? 'unknown') {
            'completed' => $this->structured([
                'status' => 'added',
                'account' => $status['account'] ?? null,
                'suggestion' => 'Account connected. Call gmail_list_accounts to confirm, or gmail_start_oauth_flow '
                    .'again to add another account.',
            ]),
            'pending' => $this->structured([
                'status' => 'pending',
                'suggestion' => 'The user has not finished authorizing in the browser yet. Ask them to complete '
                    .'consent at the URL from gmail_start_oauth_flow, then call gmail_complete_oauth_flow again.',
            ]),
            'failed' => $this->structured([
                'error' => 'oauth_failed',
                'message' => $status['message'] ?? 'Authorization failed.',
                'suggestion' => 'Call gmail_start_oauth_flow to try again.',
            ]),
            'timeout' => $this->structured([
                'error' => 'oauth_timeout',
                'message' => 'The authorization window (5 minutes) expired before the user finished.',
                'suggestion' => 'Call gmail_start_oauth_flow to get a fresh consent URL.',
            ]),
            default => $this->structured([
                'error' => 'no_pending_flow',
                'message' => $status['message'] ?? 'No matching OAuth flow was found.',
                'suggestion' => 'Call gmail_start_oauth_flow to begin connecting an account.',
            ]),
        };
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'state' => $schema->string()
                ->description('The state token returned by gmail_start_oauth_flow. Omit to use the most recent flow.'),
        ];
    }
}
