<?php

namespace App\Mcp\Tools\Gmail;

use App\Mcp\Concerns\InteractsWithGoogleApi;
use App\Models\OauthCredential;
use App\Services\AuditLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Name('gmail_save_oauth_credentials')]
#[Description(
    'Store the user\'s Google Cloud OAuth Client ID and Secret (encrypted at rest in SQLite). These come from the '
    .'"Desktop app" OAuth client the user created in their own Google Cloud project. Validates that the Client ID '
    .'ends with .apps.googleusercontent.com and the secret is non-empty. '
    .'PRIVACY: the user is pasting these into the chat, so they will appear in the transcript. Advise the user not '
    .'to share the transcript, and to rotate the Client Secret in the Cloud console if the transcript ever leaks. '
    .'(A Desktop client secret is lower-sensitivity than a server secret, but should still not be shared casually.)'
)]
class SaveOauthCredentialsTool extends Tool
{
    use InteractsWithGoogleApi;

    public function handle(Request $request, AuditLogger $audit): ResponseFactory
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string'],
            'client_secret' => ['required', 'string'],
        ]);

        $clientId = trim($validated['client_id']);
        $clientSecret = trim($validated['client_secret']);

        if (! str_ends_with($clientId, '.apps.googleusercontent.com')) {
            return $this->structured([
                'error' => 'invalid_client_id',
                'suggestion' => 'The Client ID must end with .apps.googleusercontent.com. Copy it from the '
                    .'Desktop OAuth client in the user\'s Google Cloud project (gmail_setup_wizard step '
                    .'create_oauth_client).',
            ]);
        }

        if ($clientSecret === '') {
            return $this->structured([
                'error' => 'invalid_client_secret',
                'suggestion' => 'The Client Secret cannot be empty. Copy it from the same Desktop OAuth client.',
            ]);
        }

        // v1 expects a single credential row; replace any existing one.
        OauthCredential::query()->delete();
        OauthCredential::create([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        // Audited; AuditLogger redacts both client_id and client_secret.
        $audit->record(
            tool: 'gmail_save_oauth_credentials',
            account: null,
            params: ['client_id' => $clientId, 'client_secret' => $clientSecret],
        );

        return $this->structured([
            'status' => 'saved',
            'next_step' => 'add_account',
            'suggestion' => 'Call gmail_start_oauth_flow to connect the first Google account.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->string()
                ->description('OAuth Client ID, ending in .apps.googleusercontent.com')
                ->required(),
            'client_secret' => $schema->string()
                ->description('OAuth Client Secret for the Desktop client')
                ->required(),
        ];
    }
}
