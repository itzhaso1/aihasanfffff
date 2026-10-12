<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdatePlatformAiModelRequest;
use App\Services\Platform\PlatformAiConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AiModelSettingsController extends Controller
{
    public function edit(PlatformAiConfiguration $configuration): View
    {
        return view('platform.ai.edit', [
            'selected' => $configuration->snapshot(),
            'catalog' => $configuration->catalog(),
        ]);
    }

    public function update(UpdatePlatformAiModelRequest $request, PlatformAiConfiguration $configuration): RedirectResponse
    {
        $validated = $request->validated();
        $configuration->update(
            (string) $validated['provider'],
            (string) $validated['model'],
            isset($validated['fallback_provider']) ? (string) $validated['fallback_provider'] : null,
            isset($validated['fallback_model']) ? (string) $validated['fallback_model'] : null,
            $request->user('platform_admin')?->id,
        );

        return redirect()->route('platform.ai.edit')->with('success', 'تم حفظ إعداد نموذج الذكاء الاصطناعي للمنصة.');
    }
}
