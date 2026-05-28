<?php

namespace App\Services\Gmail;

use App\Exceptions\OnboardingRequiredException;
use App\Models\OauthCredential;
use App\Services\Google\Scopes;
use Google\Client as GoogleClient;

/**
 * Builds configured Google API clients from the encrypted OAuth credentials
 * in SQLite. Centralises the offline/consent settings so the auth flow and
 * the per-account API clients stay consistent.
 */
class GoogleClientFactory
{
    /**
     * @param  list<string>  $scopes
     *
     * @throws OnboardingRequiredException when no OAuth client is stored yet
     */
    public function make(array $scopes = [], ?string $redirectUri = null): GoogleClient
    {
        $credential = OauthCredential::current();

        if ($credential === null) {
            throw new OnboardingRequiredException(
                'no_oauth_credentials',
                'Call gmail_setup_wizard to begin setup',
            );
        }

        $client = new GoogleClient;
        $client->setApplicationName('google-workspace-mcp');
        $client->setClientId($credential->client_id);
        $client->setClientSecret($credential->client_secret);
        // Fall back to the full bundle so a freshly-built client can act on
        // any granted scope. Callers that need to limit (e.g. revoke) pass an
        // explicit scope list.
        $client->setScopes($scopes !== [] ? $scopes : Scopes::requested());

        // access_type=offline + prompt=consent guarantee a refresh_token is
        // issued on first authorization (Desktop client, loopback redirect).
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        if ($redirectUri !== null) {
            $client->setRedirectUri($redirectUri);
        }

        return $client;
    }
}
