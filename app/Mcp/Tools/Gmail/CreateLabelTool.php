<?php

namespace App\Mcp\Tools\Gmail;

use App\Mcp\Concerns\InteractsWithGoogleApi;
use App\Services\AuditLogger;
use App\Services\Gmail\GmailClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('gmail_create_label')]
#[Description(
    'Create a new user label on an account. Optionally set its colour (background hex, e.g. "#16a766"). Returns '
    .'the new label id. Mutating: audited.'
)]
class CreateLabelTool extends Tool
{
    use InteractsWithGoogleApi;

    public function handle(Request $request, GmailClient $gmail, AuditLogger $audit): ResponseFactory
    {
        $account = (string) $request->get('account');

        if ($guard = $this->guard($account)) {
            return $guard;
        }

        $validated = $request->validate([
            'name' => ['required', 'string'],
            'color' => ['nullable', 'string'],
            'text_color' => ['nullable', 'string'],
        ]);

        return $this->withGoogle($account, function () use ($gmail, $audit, $account, $validated): ResponseFactory {
            $label = $audit->around(
                tool: 'gmail_create_label',
                account: $account,
                params: $validated,
                threadIds: null,
                operation: fn (): array => $gmail->createLabel(
                    $account,
                    $validated['name'],
                    $validated['color'] ?? null,
                    $validated['text_color'] ?? null,
                ),
            );

            return $this->structured(['status' => 'created', 'label' => $label]);
        });
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
            'name' => $schema->string()
                ->description('Label name. Use "/" for nesting, e.g. "Clients/Acme".')
                ->required(),
            'color' => $schema->string()
                ->description('Optional background colour as a hex string from Gmail\'s allowed palette, e.g. "#16a766".'),
            'text_color' => $schema->string()
                ->description('Optional text colour hex (defaults to white). Only used when color is set.'),
        ];
    }
}
