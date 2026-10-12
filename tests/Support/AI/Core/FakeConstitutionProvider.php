<?php

declare(strict_types=1);

namespace Tests\Support\AI\Core;

use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\ConstitutionProvider;
use App\AI\Core\Data\LoadedConstitution;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;
use Throwable;

final class FakeConstitutionProvider implements ConstitutionProvider
{
    public ?Throwable $throw = null;

    public LoadedConstitution $constitution;

    public function __construct()
    {
        $this->constitution = new LoadedConstitution('const-v1', [
            new PromptSection(PromptLayer::Constitution, 'constitution.core', 'CONSTITUTION-LAYER', 'core.md', 'const-v1'),
        ]);
    }

    public function load(AiRequest $request): LoadedConstitution
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->constitution;
    }
}
