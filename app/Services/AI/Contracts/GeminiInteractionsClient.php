<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\Gemini\GeminiInteraction;
use App\Services\AI\Gemini\GeminiInteractionRequest;

interface GeminiInteractionsClient
{
    public function create(GeminiInteractionRequest $request): GeminiInteraction;
}
