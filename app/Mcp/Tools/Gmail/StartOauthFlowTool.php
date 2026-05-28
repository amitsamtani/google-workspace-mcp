<?php

namespace App\Mcp\Tools\Gmail;

use App\Mcp\Concerns\InteractsWithGoogleApi;
use App\Services\Gmail\OauthFlowManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('gmail_start_oauth_flow')]
#[Description(
    'Begin connecting (or re-authorizing) a Google account via the loopback OAuth flow. Probes for a free local '
    .'port, starts a background listener, and returns { consent_url, bound_port, state, expires_in_seconds: 300 }. '
    .'The consent screen requests the full scope bundle (Gmail + Calendar) so one authorization grants both. Use '
    .'this with an email_hint to RE-AUTHORIZE an existing account that needs additional scopes (e.g. on '
    .'scope_not_granted). Tell the user to open consent_url in their browser and authorize; this returns '
    .'immediately. After the user authorizes, call gmail_complete_oauth_flow (optionally with the returned state) '
    .'to confirm. Requires OAuth credentials to be saved first.'
)]
class StartOauthFlowTool extends Tool
{
    use InteractsWithGoogleApi;

    public function handle(Request $request, OauthFlowManager $flow): ResponseFactory
    {
        // Credentials must exist, but no account is required (we are adding one).
        if ($guard = $this->guard(requireAccount: false)) {
            return $guard;
        }

        $emailHint = $request->get('email_hint');
        $result = $flow->start(is_string($emailHint) && $emailHint !== '' ? $emailHint : null);

        return $this->structured([
            ...$result,
            'instructions' => 'Ask the user to open consent_url in a browser and authorize the account. '
                .'Then call gmail_complete_oauth_flow with this state to finish.',
            'suggestion' => 'Call gmail_complete_oauth_flow with state='.$result['state'].' once the user has authorized.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email_hint' => $schema->string()
                ->description('Optional: pre-fill the Google account chooser with this email (e.g. when re-authorizing a known account).'),
        ];
    }
}
