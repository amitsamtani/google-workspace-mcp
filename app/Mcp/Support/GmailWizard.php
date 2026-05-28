<?php

namespace App\Mcp\Support;

use App\Models\EmailAccount;
use App\Models\OauthCredential;

/**
 * Source of truth for the in-Claude onboarding walkthrough and the
 * setup-state machine. Both gmail_setup_status and gmail_setup_wizard read
 * from here so the narrated steps and the computed next_step never diverge.
 *
 * GCP console URLs are current as of 2026 (Google's "Google Auth Platform"
 * reorganisation). Google moves this UI periodically; the wizard tells Claude
 * to adapt by description if a link 404s.
 */
class GmailWizard
{
    /**
     * @return list<array{id: string, title: string, instructions: string, url?: string}>
     */
    public static function steps(): array
    {
        return [
            [
                'id' => 'create_gcp_project',
                'title' => 'Create a Google Cloud project',
                'instructions' => 'Open the link and create a new project (any name, e.g. "gmail-mcp"). '
                    .'This is your own project — you are the data controller. Select it once created.',
                'url' => 'https://console.cloud.google.com/projectcreate',
            ],
            [
                'id' => 'enable_apis',
                'title' => 'Enable the Gmail and Calendar APIs',
                'instructions' => 'With your project selected, enable BOTH APIs (one at a time): open the Gmail API '
                    .'page and click "Enable", then open the Google Calendar API page and click "Enable". The '
                    .'consent screen later will request scopes for both — if either API is not enabled in this '
                    .'project, the matching tools will fail with "API has not been used in project X before or is '
                    .'disabled". '
                    .'(Calendar API library: https://console.cloud.google.com/apis/library/calendar-json.googleapis.com)',
                'url' => 'https://console.cloud.google.com/apis/library/gmail.googleapis.com',
            ],
            [
                'id' => 'configure_oauth_consent',
                'title' => 'Configure the Google Auth Platform (consent / audience)',
                'instructions' => 'Open the Google Auth Platform "Get started" page. On a new project you must '
                    .'complete this once before you can create an OAuth client: fill in an App name, a User '
                    .'support email, and a Developer contact email. For "Audience", if all the accounts you will '
                    .'add belong to ONE Google Workspace organization, choose "Internal"; otherwise choose '
                    .'"External". '
                    .'IMPORTANT for External apps: afterwards open the "Audience" page and set the publishing '
                    .'status to "In production" (you can ignore the "unverified app" warning for your own use). '
                    .'Do NOT leave an External app in "Testing" — Google expires its refresh tokens after 7 days, '
                    .'which would force you to re-authorize every account weekly. '
                    .'(Audience page: https://console.cloud.google.com/auth/audience)',
                'url' => 'https://console.cloud.google.com/auth/overview',
            ],
            [
                'id' => 'create_oauth_client',
                'title' => 'Create an OAuth client (Desktop app)',
                'instructions' => 'Open the Clients page, click "Create client", and choose application type '
                    .'"Desktop app". Desktop is required: it allows the loopback (127.0.0.1) redirect this MCP '
                    .'uses and treats the client secret as non-confidential. Do NOT pick "Web application".',
                'url' => 'https://console.cloud.google.com/auth/clients',
            ],
            [
                'id' => 'paste_credentials',
                'title' => 'Paste the Client ID and Secret',
                'instructions' => 'Copy the Client ID (ends with .apps.googleusercontent.com) and Client Secret '
                    .'from the client you just created, and give them to me. I will store them encrypted via '
                    .'gmail_save_oauth_credentials. NOTE: pasting these into chat means they appear in the '
                    .'transcript — avoid sharing the transcript, and rotate the secret in the console if it leaks.',
            ],
            [
                'id' => 'add_account',
                'title' => 'Connect a Google account',
                'instructions' => 'I will call gmail_start_oauth_flow to open a browser consent URL. The consent '
                    .'screen will request Gmail (read/label/archive/draft) AND Calendar (events, free/busy, '
                    .'calendar list) access in one step — approve them together. Authorize the account you want to '
                    .'manage, then I will confirm it was added with gmail_complete_oauth_flow. Repeat for each '
                    .'additional account. (Accounts connected before Calendar shipped can be re-authorized via the '
                    .'same flow with email_hint=<their-email> to gain the Calendar scopes.)',
            ],
        ];
    }

    public static function currentStep(): string
    {
        if (! OauthCredential::configured()) {
            return 'create_gcp_project';
        }

        if (EmailAccount::query()->doesntExist()) {
            return 'add_account';
        }

        return 'ready';
    }

    /**
     * The structured next_step + human instructions used by gmail_setup_status.
     *
     * @return array{next_step: string, instructions: string}
     */
    public static function nextStep(): array
    {
        return match (self::currentStep()) {
            'create_gcp_project' => [
                'next_step' => 'create_gcp_project',
                'instructions' => 'No OAuth credentials yet. Call gmail_setup_wizard and walk the user through '
                    .'creating a Google Cloud project, enabling the Gmail API, configuring the consent screen, '
                    .'creating a Desktop OAuth client, and pasting the Client ID + Secret.',
            ],
            'add_account' => [
                'next_step' => 'add_account',
                'instructions' => 'OAuth credentials are saved but no accounts are connected. Call '
                    .'gmail_start_oauth_flow to begin connecting the first Google account.',
            ],
            default => [
                'next_step' => 'ready',
                'instructions' => 'Setup is complete. Use gmail_list_accounts to see connected accounts and the '
                    .'gmail_* tools to work with mail. Every mail tool requires an explicit `account`.',
            ],
        };
    }
}
