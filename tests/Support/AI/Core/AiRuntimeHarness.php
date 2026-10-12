<?php

declare(strict_types=1);

namespace Tests\Support\AI\Core;

use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\AiRuntime;
use App\AI\Core\AiRuntimeLimits;
use App\AI\Core\Contracts\ConstitutionProvider;
use App\AI\Core\Contracts\EmployeeResolver;
use App\AI\Core\Contracts\TrainingProvider;
use App\AI\Core\Pipeline\ModelTurnLoop;
use App\AI\Core\Pipeline\PromptAssembler;
use App\AI\Core\PromptComposer;

/**
 * Wires a real {@see AiRuntime} with test fakes for every future layer.
 */
final class AiRuntimeHarness
{
    public EmployeeResolver $employees;

    public ConstitutionProvider $constitution;

    public FakePolicyProvider $policy;

    public FakeContextProvider $context;

    public TrainingProvider $training;

    public FakeToolRunner $tools;

    public ScriptedModelGateway $model;

    public FakeResponseGuard $guard;

    public function __construct(public int $maxToolRounds = 3)
    {
        $this->employees = new FakeEmployeeResolver;
        $this->constitution = new FakeConstitutionProvider;
        $this->policy = new FakePolicyProvider;
        $this->context = new FakeContextProvider;
        $this->training = new FakeTrainingProvider;
        $this->tools = new FakeToolRunner;
        $this->model = new ScriptedModelGateway;
        $this->guard = new FakeResponseGuard;
    }

    public function runtime(): AiRuntime
    {
        return new AiRuntime(
            $this->employees,
            new PromptAssembler($this->constitution, $this->policy, $this->context, $this->training, new PromptComposer),
            new ModelTurnLoop($this->model, $this->tools, new AiRuntimeLimits($this->maxToolRounds)),
            $this->guard,
        );
    }

    public static function request(AiRunMode $mode = AiRunMode::Live, string $text = 'عندكم ايفون 15؟'): AiRequest
    {
        return new AiRequest(
            workspaceId: 11,
            conversationId: $mode === AiRunMode::Live ? 21 : null,
            employeeId: null,
            message: new AiInboundMessage($text, $mode === AiRunMode::Live ? 31 : null),
            runMode: $mode,
            channel: 'whatsapp',
        );
    }
}
