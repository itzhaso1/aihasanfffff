<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Inbox;

use PHPUnit\Framework\TestCase;

class HasimInboxArchitectureTest extends TestCase
{
    public function test_inbox_adapter_uses_canonical_runtime_components_only(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../../app/AI/Inbox/HasimInboxRuntime.php');

        foreach ([
            'AiRuntime',
            'ConstitutionLoader',
            'LibraryTrainingProvider',
            'AiContextProvider',
            'HasimToolRunner',
            'HasimModelGateway',
            'HasimResponseGuard',
            'HasimEvaluator',
            'ConversationEmployeeBinder',
            'DatabaseEmployeeResolver',
        ] as $component) {
            $this->assertStringContainsString($component, $source, $component);
        }

        foreach ([
            'AiProviderManager',
            'GoogleAiStudioProvider',
            'OpenAiProvider',
            'InboxAgentOrchestrator',
            'InboxAgentLoop',
            'App\\Services\\Finance',
            'App\\Services\\Pos',
            'App\\Services\\Cashier',
            'مشعل',
            'طارق',
            'محمد',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden);
        }
    }

    public function test_aiservice_has_a_single_hasim_inbox_path(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../../app/Services/AI/AIService.php');

        $this->assertStringContainsString('HasimInboxRuntime', $source);
        $this->assertStringNotContainsString('hasim_runtime.enabled', $source);
        $this->assertStringNotContainsString('AI_HASIM_RUNTIME', $source);
        $this->assertStringNotContainsString('inbox_agent.enabled', $source);
        $this->assertStringNotContainsString('InboxAgentOrchestrator', $source);
        $this->assertStringNotContainsString('InboxAgentLoop', $source);
        $this->assertStringNotContainsString('AiProviderManager', $source);
        $this->assertStringNotContainsString('GoogleAiStudioProvider', $source);
        $this->assertStringNotContainsString('OpenAiProvider', $source);
        $this->assertStringNotContainsString('finalizeLegacyGeneration', $source);
        $this->assertStringNotContainsString('InboxAgentContextBuilder', $source);
        $this->assertStringNotContainsString('ChatAiPromptBuilder', $source);
        $this->assertStringNotContainsString('App\\AI\\Employees', $source);
        $this->assertDoesNotMatchRegularExpression('/مشعل|طارق/', $source);
    }

    public function test_legacy_inbox_orchestrator_files_are_gone(): void
    {
        $root = __DIR__.'/../../../../';
        foreach ([
            'app/Services/AI/Inbox/InboxAgentOrchestrator.php',
            'app/Services/AI/Inbox/InboxAgentLoop.php',
            'app/Services/AI/Inbox/InboxAgentResult.php',
            'app/Services/AI/Inbox/InboxAgentRun.php',
            'app/Services/AI/ChatAiPromptBuilder.php',
        ] as $relative) {
            $this->assertFileDoesNotExist($root.$relative, $relative);
        }
    }

    public function test_legacy_inbox_execution_classes_are_not_autoloadable(): void
    {
        foreach ([
            'App\\Services\\AI\\Inbox\\InboxAgentOrchestrator',
            'App\\Services\\AI\\Inbox\\InboxAgentLoop',
            'App\\Services\\AI\\Inbox\\InboxAgentResult',
            'App\\Services\\AI\\Inbox\\InboxAgentRun',
            'App\\Services\\AI\\ChatAiPromptBuilder',
        ] as $class) {
            $this->assertFalse(class_exists($class, false), $class);
        }
    }

    public function test_process_ai_response_is_unchanged_as_the_delivery_boundary(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../../app/Jobs/ProcessAIResponse.php');

        $this->assertStringContainsString('AIService', $source);
        $this->assertStringContainsString('ChannelOutboundService', $source);
        $this->assertStringNotContainsString('HasimInboxRuntime', $source);
        $this->assertStringNotContainsString('AiProviderManager', $source);
        $this->assertStringNotContainsString('HasimModelGateway', $source);
        $this->assertStringNotContainsString('InboxAgentOrchestrator', $source);
    }

    public function test_migration_flag_is_removed_from_config(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../../config/ai.php');

        $this->assertStringNotContainsString('hasim_runtime', $source);
        $this->assertStringNotContainsString('AI_HASIM_RUNTIME_ENABLED', $source);
        $this->assertStringNotContainsString('AI_INBOX_AGENT_ENABLED', $source);
        $this->assertStringNotContainsString('fallback_enabled', $source);
        $this->assertStringContainsString('tools_enabled', $source);
    }

    public function test_phpunit_does_not_override_a_hasim_migration_flag(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../../phpunit.xml');

        $this->assertStringNotContainsString('AI_HASIM_RUNTIME', $source);
        $this->assertStringNotContainsString('AI_INBOX_AGENT_ENABLED', $source);
        $this->assertStringNotContainsString('hasim_runtime', $source);
    }
}
