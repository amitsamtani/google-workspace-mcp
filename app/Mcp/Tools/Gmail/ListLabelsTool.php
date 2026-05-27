<?php

namespace App\Mcp\Tools\Gmail;

use App\Mcp\Concerns\InteractsWithGmail;
use App\Services\Gmail\GmailClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('gmail_list_labels')]
#[Description(
    'List an account\'s labels with their ids (system labels like INBOX/STARRED and user labels). Use the ids '
    .'with gmail_label_thread / gmail_bulk_label_threads. Reads are not audited.'
)]
class ListLabelsTool extends Tool
{
    use InteractsWithGmail;

    public function handle(Request $request, GmailClient $gmail): ResponseFactory
    {
        $account = (string) $request->get('account');

        if ($guard = $this->guard($account)) {
            return $guard;
        }

        return $this->withGmail($account, fn (): ResponseFactory => $this->structured([
            'labels' => $gmail->listLabels($account),
        ]));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->string()
                ->description('Email address of the connected account. See gmail_list_accounts.')
                ->required(),
        ];
    }
}
