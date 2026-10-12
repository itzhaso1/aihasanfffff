<?php

declare(strict_types=1);

namespace App\AI\Models\Contracts;

use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelResponse;
use App\AI\Models\ProviderCapability;
use App\AI\Models\ResolvedModelSettings;

interface ProviderAdapter
{
    public function provider(): string;

    public function capabilities(): ProviderCapability;

    public function generate(ResolvedModelSettings $settings, ModelRequest $request): ModelResponse;
}
