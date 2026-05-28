<?php

namespace App\Mcp\Concerns;

use App\Exceptions\OnboardingRequiredException;
use App\Exceptions\ReauthRequiredException;
use App\Models\EmailAccount;
use App\Models\OauthCredential;
use App\Services\Google\Scopes;
use Google\Service\Exception as GoogleServiceException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Shared helpers for every gmail_* / calendar_* (and future drive_*) tool.
 *
 * `guard()` performs the layered onboarding checks (credentials → accounts →
 * unknown account → optionally a required OAuth scope) and returns the
 * matching structured "error-as-suggestion" payload so Claude can self-recover.
 * The payload's `error` code and `suggestion` always name the exact tool to
 * call next.
 *
 * Was named InteractsWithGoogleApi when the server only spoke Gmail. Renamed and
 * given an optional required-scope parameter once Calendar joined: an account
 * may have granted Gmail but not (yet) Calendar, and the call should surface
 * that cleanly rather than failing as a generic API error.
 */
trait InteractsWithGoogleApi
{
    /**
     * Returns a structured error response if the server isn't in a state to
     * service the tool call, or null to proceed.
     *
     * @param  bool  $requireAccount  whether a valid `account` must be supplied
     * @param  ?string  $requireScope  if set, the account must have granted this scope
     */
    protected function guard(?string $account = null, bool $requireAccount = true, ?string $requireScope = null): ?ResponseFactory
    {
        if (! OauthCredential::configured()) {
            return $this->structured([
                'error' => 'no_oauth_credentials',
                'suggestion' => 'Call gmail_setup_wizard to begin setup',
            ]);
        }

        // The add-account flow is allowed precisely when there are no accounts
        // yet — don't gate it on account existence.
        if (! $requireAccount) {
            return null;
        }

        $emails = EmailAccount::emails();

        if ($emails === []) {
            return $this->structured([
                'error' => 'no_accounts',
                'suggestion' => 'Call gmail_setup_status to see setup state',
            ]);
        }

        if ($account === null || ! in_array($account, $emails, true)) {
            return $this->structured([
                'error' => 'unknown_account',
                'provided' => $account,
                'valid_accounts' => $emails,
                'suggestion' => 'Pass one of valid_accounts as `account`. Call gmail_list_accounts to refresh the list.',
            ]);
        }

        if ($requireScope !== null) {
            $granted = EmailAccount::query()->where('email', $account)->value('scopes') ?? [];
            if (! Scopes::has($granted, $requireScope)) {
                return $this->structured([
                    'error' => 'scope_not_granted',
                    'account' => $account,
                    'missing_scope' => $requireScope,
                    'granted_scopes' => $granted,
                    'suggestion' => "Call gmail_start_oauth_flow with email_hint='{$account}' to re-authorize the "
                        .'account; the consent screen will request the missing scope alongside what is already granted.',
                ]);
            }
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
     * Run a Google API operation, translating service-layer failures into the
     * matching structured suggestion payloads.
     *
     * @param  callable(): ResponseFactory  $operation
     */
    protected function withGoogle(string $account, callable $operation): ResponseFactory
    {
        try {
            return $operation();
        } catch (ReauthRequiredException $e) {
            return $this->reauthNeeded($e->account);
        } catch (OnboardingRequiredException $e) {
            return $this->structured(['error' => $e->reason, 'suggestion' => $e->suggestion]);
        } catch (GoogleServiceException $e) {
            return $this->structured([
                'error' => 'google_api_error',
                'account' => $account,
                'status_code' => $e->getCode(),
                'message' => $e->getMessage(),
                'suggestion' => 'The Google API rejected the request. Check parameters (ids, time ranges, '
                    .'label/event ids) and try again. If this persists, the account may need re-authorization via '
                    ."gmail_start_oauth_flow with email_hint='{$account}'.",
            ]);
        }
    }
}
