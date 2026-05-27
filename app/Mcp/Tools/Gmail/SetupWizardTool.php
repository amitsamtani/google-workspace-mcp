<?php

namespace App\Mcp\Tools\Gmail;

use App\Mcp\Support\GmailWizard;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('gmail_setup_wizard')]
#[Description(
    'Return the step-by-step Bring-Your-Own-Google-Cloud-Project onboarding walkthrough (create project, enable '
    .'Gmail API, configure consent, create a Desktop OAuth client, paste credentials, connect accounts) plus the '
    .'current_step. Narrate each step to the user conversationally, opening the given URLs. Read the consent step '
    .'carefully: it explains how to avoid Google\'s 7-day refresh-token expiry.'
)]
class SetupWizardTool extends Tool
{
    public function handle(): ResponseFactory
    {
        return Response::structured([
            'steps' => GmailWizard::steps(),
            'current_step' => GmailWizard::currentStep(),
        ]);
    }
}
