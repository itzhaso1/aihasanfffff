<?php

namespace App\Services\Platform;

use App\AI\Models\ProviderId;
use App\Models\PlatformAiSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Single source of truth for the AI provider/model used across the product.
 * The allow-list in config/ai.php is only the catalog of values an admin may select.
 */
final class PlatformAiConfiguration
{
    private ?PlatformAiModel $cached = null;

    /**
     * @return list<array{id: string, label: string, models: list<string>}>
     */
    public function catalog(): array
    {
        $labels = [
            'google_ai_studio' => 'Google AI Studio (Gemini)',
            'openai' => 'OpenAI',
        ];
        $catalog = [];
        foreach ($this->allowedProviders() as $id) {
            $catalog[] = [
                'id' => $id,
                'label' => $labels[$id] ?? $id,
                'models' => $this->allowedModels($id),
            ];
        }

        return $catalog;
    }

    public function snapshot(): PlatformAiModel
    {
        return $this->cached ??= $this->load();
    }

    public function update(
        string $provider,
        string $model,
        ?string $fallbackProvider,
        ?string $fallbackModel,
        ?int $updatedBy,
    ): PlatformAiModel {
        $provider = ProviderId::normalize($provider);
        $model = trim($model);
        $fallbackProvider = $this->nullableNormalized($fallbackProvider);
        $fallbackModel = $this->nullableText($fallbackModel);

        $this->assertAllowedPair($provider, $model, 'provider', 'model');
        if ($fallbackProvider !== null || $fallbackModel !== null) {
            if ($fallbackProvider === null || $fallbackModel === null) {
                throw ValidationException::withMessages([
                    'fallback_model' => 'حدد مزوداً ونموذجاً احتياطيين معاً، أو اتركهما فارغين.',
                ]);
            }
            $this->assertAllowedPair($fallbackProvider, $fallbackModel, 'fallback_provider', 'fallback_model');
        }

        $row = $this->row();
        $row->fill([
            'provider' => $provider,
            'model' => $model,
            'fallback_provider' => $fallbackProvider,
            'fallback_model' => $fallbackModel,
            'updated_by' => $updatedBy,
        ]);
        $row->save();
        $this->cached = null;

        return $this->snapshot();
    }

    public function forget(): void
    {
        $this->cached = null;
    }

    /**
     * @return list<string>
     */
    public function allowedProviders(): array
    {
        $configured = config('ai.allowed_providers', ['google_ai_studio', 'openai']);

        return array_values(array_filter(array_map('strval', is_array($configured) ? $configured : [])));
    }

    /**
     * @return list<string>
     */
    public function allowedModels(string $provider): array
    {
        $map = config('ai.allowed_models', []);
        $list = is_array($map[$provider] ?? null) ? $map[$provider] : [];

        return array_values(array_filter(array_map('strval', $list)));
    }

    public function credentialsPresent(string $provider): bool
    {
        return match (ProviderId::normalize($provider)) {
            ProviderId::GEMINI => filled(config('services.google_ai_studio.key')),
            ProviderId::OPENAI => filled(config('services.openai.key')),
            default => false,
        };
    }

    private function load(): PlatformAiModel
    {
        $row = $this->row();
        [$provider, $model, $usingFallback] = $this->resolvePair($row);

        return new PlatformAiModel(
            provider: $provider,
            model: $model,
            fallbackProvider: $this->nullableText($row->fallback_provider),
            fallbackModel: $this->nullableText($row->fallback_model),
            usingFallback: $usingFallback,
        );
    }

    private function row(): PlatformAiSetting
    {
        $row = PlatformAiSetting::query()->first();
        if ($row instanceof PlatformAiSetting) {
            return $row;
        }

        return DB::transaction(function (): PlatformAiSetting {
            $existing = PlatformAiSetting::query()->lockForUpdate()->first();
            if ($existing instanceof PlatformAiSetting) {
                return $existing;
            }

            $seed = $this->seedFromConfig();

            return PlatformAiSetting::query()->create([
                'provider' => $seed['provider'],
                'model' => $seed['model'],
                'fallback_provider' => null,
                'fallback_model' => null,
            ]);
        });
    }

    /**
     * @return array{provider: string, model: string}
     */
    private function seedFromConfig(): array
    {
        $providers = $this->allowedProviders();
        if ($providers === []) {
            throw new InvalidArgumentException('No allowed AI provider is configured.');
        }
        $preferred = ProviderId::normalize((string) config('ai.default_provider', 'google_ai_studio'));
        $provider = in_array($preferred, $providers, true) ? $preferred : $providers[0];
        $models = $this->allowedModels($provider);
        if ($models === []) {
            throw new InvalidArgumentException('No allowed AI model is configured.');
        }
        $configured = trim((string) config('ai.'.$provider.'.model', ''));
        $model = $configured !== '' && in_array($configured, $models, true) ? $configured : $models[0];

        return ['provider' => $provider, 'model' => $model];
    }

    /**
     * @return array{0: string, 1: string, 2: bool}
     */
    private function resolvePair(PlatformAiSetting $row): array
    {
        if ($this->isAllowedPair((string) $row->provider, (string) $row->model)) {
            return [ProviderId::normalize((string) $row->provider), (string) $row->model, false];
        }
        if ($this->isAllowedPair((string) $row->fallback_provider, (string) $row->fallback_model)) {
            return [ProviderId::normalize((string) $row->fallback_provider), (string) $row->fallback_model, true];
        }

        $seed = $this->seedFromConfig();

        return [ProviderId::normalize($seed['provider']), $seed['model'], true];
    }

    private function isAllowedPair(?string $provider, ?string $model): bool
    {
        $provider = ProviderId::normalize((string) $provider);
        $model = trim((string) $model);
        if ($provider === '' || $model === '') {
            return false;
        }

        return in_array($provider, $this->allowedProviders(), true)
            && in_array($model, $this->allowedModels($provider), true);
    }

    private function assertAllowedPair(string $provider, string $model, string $providerField, string $modelField): void
    {
        if (! in_array($provider, $this->allowedProviders(), true)) {
            throw ValidationException::withMessages([
                $providerField => 'هذا المزود غير مسموح.',
            ]);
        }
        if (! in_array($model, $this->allowedModels($provider), true)) {
            throw ValidationException::withMessages([
                $modelField => 'هذا النموذج غير مسموح لهذا المزود.',
            ]);
        }
    }

    private function nullableNormalized(?string $value): ?string
    {
        $text = $this->nullableText($value);

        return $text === null ? null : ProviderId::normalize($text);
    }

    private function nullableText(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
