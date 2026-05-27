<?php

namespace App\Mcp\Tools\Gmail;

use App\Mcp\Concerns\InteractsWithGmail;
use App\Services\AuditLogger;
use App\Services\Gmail\GmailClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('gmail_create_draft')]
#[Description(
    'Create a draft email (it is NOT sent — this server has no send capability). Provide recipients, subject and '
    .'a plaintext body, optionally an HTML body. Pass reply_to_message_id to make it a threaded reply (sets the '
    .'In-Reply-To/References headers and thread). Returns the new draft id. The user reviews and sends it from '
    .'Gmail themselves. Mutating: audited (body content is redacted from the audit log).'
)]
class CreateDraftTool extends Tool
{
    use InteractsWithGmail;

    public function handle(Request $request, GmailClient $gmail, AuditLogger $audit): ResponseFactory
    {
        $account = (string) $request->get('account');

        if ($guard = $this->guard($account)) {
            return $guard;
        }

        $validated = $request->validate([
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['email'],
            'subject' => ['required', 'string'],
            'body' => ['required', 'string'],
            'html_body' => ['nullable', 'string'],
            'reply_to_message_id' => ['nullable', 'string'],
        ]);

        return $this->withGmail($account, function () use ($gmail, $audit, $account, $validated): ResponseFactory {
            $draft = $audit->around(
                tool: 'gmail_create_draft',
                account: $account,
                // body / html_body are redacted by AuditLogger.
                params: [
                    'to' => $validated['to'],
                    'subject' => $validated['subject'],
                    'body' => $validated['body'],
                    'html_body' => $validated['html_body'] ?? null,
                    'reply_to_message_id' => $validated['reply_to_message_id'] ?? null,
                ],
                threadIds: null,
                operation: fn (): array => $gmail->createDraft(
                    $account,
                    $validated['to'],
                    $validated['subject'],
                    $validated['body'],
                    $validated['html_body'] ?? null,
                    $validated['reply_to_message_id'] ?? null,
                ),
            );

            return $this->structured(['status' => 'draft_created', ...$draft]);
        });
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->string()
                ->description('Email address of the connected account to create the draft in. See gmail_list_accounts.')
                ->required(),
            'to' => $schema->array()
                ->description('Recipient email addresses.')
                ->items($schema->string())
                ->min(1)
                ->required(),
            'subject' => $schema->string()
                ->description('Subject line.')
                ->required(),
            'body' => $schema->string()
                ->description('Plaintext body.')
                ->required(),
            'html_body' => $schema->string()
                ->description('Optional HTML body (sent as multipart/alternative alongside the plaintext body).'),
            'reply_to_message_id' => $schema->string()
                ->description('Optional message id to reply to; threads the draft and sets In-Reply-To/References.'),
        ];
    }
}
