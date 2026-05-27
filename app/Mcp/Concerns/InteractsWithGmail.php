<?php

namespace App\Mcp\Concerns;

use App\Exceptions\OnboardingRequiredException;
use App\Exceptions\ReauthRequiredException;
use App\Models\EmailAccount;
use App\Models\OauthCredential;
use Google\Service\Exception as GoogleServiceException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Shared helpers for gmail_* tools: the onboarding "guard" and the structured
 * error-as-suggestion payloads. Every recoverable state is returned as a
 * structured JSON response (readable text + structuredContent) whose `error`
 * code and `suggestion` tell Claude exactly which tool to call next, so
 * first-run discovery is automatic.
 */
trait InteractsWithGmail
{
    /**
     * Returns a structured error response if the server is not in a state to
     * service a mail tool call, or null if it is good to proceed.
     *
     * @param  bool  $requireAccount  whether a valid `account` must be supplied
     */
    protected function guard(?string $account = null, bool $requireAccount = true): ?ResponseFactory
    {
        if (! OauthCredential::configured()) {
            return $this->structured([
                'error' => 'no_oauth_credentials',
                'suggestion' => 'Call gmail_setup_wizard to begin setup',
            ]);
        }

        // Account checks only apply to tools that operate on an existing
        // account. The add-account flow (requireAccount: false) is allowed
        // precisely when there are no accounts yet.
        if (! $requireAccount) {
            return null;
        }

        $accounts = EmailAccount::emails();

        if ($accounts === []) {
            return $this->structured([
                'error' => 'no_accounts',
                'suggestion' => 'Call gmail_setup_status to see setup state',
            ]);
        }

        if ($account === null || ! in_array($account, $accounts, true)) {
            return $this->structured([
                'error' => 'unknown_account',
                'provided' => $account,
                'valid_accounts' => $accounts,
                'suggestion' => 'Pass one of valid_accounts as `account`. Call gmail_list_accounts to refresh the list.',
            ]);
        }

        return null;
    }

    /**
     * The reauth_needed payload, naming the account and the exact tool call to
     * fix it.
     */
    protected function reauthNeeded(string $account): ResponseFactory
    {
        return $this->structured([
            'error' => 'reauth_needed',
            'account' => $account,
            'suggestion' => "Call gmail_start_oauth_flow with email_hint='{$account}'",
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function structured(array $data): ResponseFactory
    {
        return Response::structured($data);
    }

    /**
     * Run a Gmail operation, translating service-layer failures into the
     * matching structured suggestion payloads.
     *
     * @param  callable(): ResponseFactory  $operation
     */
    protected function withGmail(string $account, callable $operation): ResponseFactory
    {
        try {
            return $operation();
        } catch (ReauthRequiredException $e) {
            return $this->reauthNeeded($e->account);
        } catch (OnboardingRequiredException $e) {
            return $this->structured(['error' => $e->reason, 'suggestion' => $e->suggestion]);
        } catch (GoogleServiceException $e) {
            return $this->structured([
                'error' => 'gmail_api_error',
                'account' => $account,
                'status_code' => $e->getCode(),
                'message' => $e->getMessage(),
                'suggestion' => 'The Gmail API rejected the request. Check the parameters (e.g. label_ids or '
                    .'thread_id) and try again. If this persists, the account may need re-authorization via '
                    ."gmail_start_oauth_flow with email_hint='{$account}'.",
            ]);
        }
    }
}
