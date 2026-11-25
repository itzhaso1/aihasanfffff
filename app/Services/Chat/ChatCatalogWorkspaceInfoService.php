<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\AiSetting;
use App\Models\ChatCatalog;
use App\Models\Commerce\CommerceWorkspaceProfile;
use App\Models\Workspace;
use App\Services\AI\AiSafety;
use App\Services\Workspace\WorkspaceCompanyProfileService;
use App\Support\Tenancy\WorkspaceContext;

/**
 * Authoritative workspace store, hours, contact, shipping, return, and payment info lookup.
 * Returns only verified configured facts and explicitly flags unconfigured topics.
 */
final class ChatCatalogWorkspaceInfoService
{
    public function __construct(
        private readonly ChatCatalogRootService $catalogRoot,
        private readonly CatalogAvailabilityService $availability,
        private readonly WorkspaceCompanyProfileService $companyProfile,
        private readonly AiSafety $safety,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function shippingInfo(Workspace $workspace, ?string $city = null): array
    {
        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $city): array {
            $catalog = $this->catalogRoot->forWorkspace($workspace);
            $policy = is_array($catalog->customer_service_policy) ? $catalog->customer_service_policy : [];
            $settings = is_array($workspace->settings) ? $workspace->settings : [];
            $aiSetting = AiSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();
            $biz = is_array($aiSetting?->business_information) ? $aiSetting->business_information : [];
            $commerceMeta = CommerceWorkspaceProfile::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->value('metadata');
            $commerceMeta = is_array($commerceMeta) ? $commerceMeta : [];

            $shippingBlock = $this->extractSafeKeys([
                ...$this->arrayFrom($settings['shipping'] ?? null),
                ...$this->arrayFrom($settings['delivery'] ?? null),
                ...$this->arrayFrom($commerceMeta['shipping'] ?? null),
                ...$this->arrayFrom($biz['shipping'] ?? null),
                ...$this->arrayFrom($policy['shipping'] ?? null),
            ]);

            $notices = array_filter([
                'shipping_policy' => $this->cleanText($policy['shipping_policy'] ?? $biz['shipping_policy'] ?? $biz['delivery_policy'] ?? null),
                'delivery_time' => $this->cleanText($policy['delivery_time'] ?? $biz['delivery_time'] ?? null),
                'shipping_cost' => $this->cleanText($policy['shipping_cost'] ?? $biz['shipping_cost'] ?? null),
                'digital_delivery_notice' => $this->cleanText($policy['digital_delivery_notice'] ?? null),
                'backorder_message' => $this->cleanText($policy['backorder_message'] ?? null),
            ], static fn (?string $val): bool => $val !== null);

            $details = array_filter([...$shippingBlock, ...$notices], static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);

            return [
                'status' => $details !== [] ? 'configured' : 'not_configured',
                'requested_city' => $city !== null && trim($city) !== '' ? trim($city) : null,
                'shipping' => $details,
                'note' => $details === []
                    ? 'تفاصيل ورسوم ومواعيد الشحن والتوصيل غير محددة في إعدادات المتجر الحالية.'
                    : null,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function storeInfo(Workspace $workspace, string $topic = 'all'): array
    {
        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $topic): array {
            $catalog = $this->catalogRoot->forWorkspace($workspace)->loadMissing('seller');
            $policy = is_array($catalog->customer_service_policy) ? $catalog->customer_service_policy : [];
            $settings = is_array($workspace->settings) ? $workspace->settings : [];
            $aiSetting = AiSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();
            $biz = is_array($aiSetting?->business_information) ? $aiSetting->business_information : [];
            $profile = $this->companyProfile->contextPayload((int) $workspace->id);

            $hours = $this->formatSchedule($catalog, $biz, $settings);
            $contact = $this->formatContact($catalog, $biz, $settings);
            $returns = $this->extractSafeKeys([
                'return_policy' => $policy['return_policy'] ?? $biz['return_policy'] ?? $settings['return_policy'] ?? null,
                'exchange_policy' => $policy['exchange_policy'] ?? $biz['exchange_policy'] ?? $settings['exchange_policy'] ?? null,
                'warranty_policy' => $policy['warranty_policy'] ?? $biz['warranty_policy'] ?? null,
            ]);
            $payment = $this->extractSafeKeys([
                'payment_methods' => $policy['payment_methods'] ?? $biz['payment_methods'] ?? $settings['payment_methods'] ?? null,
                'payment_required_notice' => $policy['payment_required_notice'] ?? null,
            ]);
            $location = $this->extractSafeKeys([
                'address' => $biz['address'] ?? $biz['location'] ?? $settings['address'] ?? null,
                'branches' => $biz['branches'] ?? $settings['branches'] ?? null,
                'city' => $biz['city'] ?? $settings['city'] ?? null,
            ]);

            $payload = [
                'store_name' => $catalog->seller_public_display_name
                    ?: ($profile['company_name'] ?? null)
                    ?: $catalog->public_title
                    ?: $workspace->name,
                'about' => $profile['about'] ?? $this->cleanText($catalog->public_description),
                'topic' => $topic,
                'working_hours' => $hours,
                'is_currently_open' => $this->availability->isCatalogOpenForCustomers($catalog),
                'contact' => $contact,
                'return_and_warranty_policy' => $returns,
                'payment_methods' => $payment,
                'location' => $location,
                'support_instructions' => $this->cleanText($policy['support_contact_instructions'] ?? null),
            ];

            $payload = array_filter($payload, static fn (mixed $v): bool => $v !== null && $v !== [] && $v !== '');
            $hasRequestedTopic = match ($topic) {
                'hours' => ($payload['working_hours'] ?? []) !== [],
                'contact' => ($payload['contact'] ?? []) !== [] || isset($payload['support_instructions']),
                'returns', 'policies' => ($payload['return_and_warranty_policy'] ?? []) !== [],
                'payment' => ($payload['payment_methods'] ?? []) !== [],
                'location' => ($payload['location'] ?? []) !== [],
                default => count($payload) > 2,
            };

            $payload['status'] = $hasRequestedTopic ? 'configured' : 'not_configured';

            return $payload;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSchedule(ChatCatalog $catalog, array $biz, array $settings): array
    {
        $schedule = is_array($catalog->availability_schedule) ? $catalog->availability_schedule : [];
        $weekly = is_array($schedule['weekly'] ?? null) ? $schedule['weekly'] : [];

        return array_filter([
            'mode' => $catalog->availability_mode?->value,
            'timezone' => $catalog->timezone,
            'weekly' => $weekly !== [] ? $weekly : null,
            'text' => $this->cleanText($biz['working_hours'] ?? $settings['working_hours'] ?? null),
        ], static fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatContact(ChatCatalog $catalog, array $biz, array $settings): array
    {
        $contact = array_merge(
            $this->arrayFrom($settings['contact'] ?? null),
            $this->arrayFrom($biz['contact'] ?? null),
            $this->arrayFrom($catalog->public_support_contact),
            $this->arrayFrom($catalog->seller_support_contact),
        );

        $safe = $this->extractSafeKeys($contact);
        if (is_string($catalog->public_website) && trim($catalog->public_website) !== '') {
            $safe['website'] = $this->cleanText($catalog->public_website);
        }

        return $safe;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private function extractSafeKeys(array $raw): array
    {
        $safe = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (preg_match('/(?:secret|token|password|credential|key|internal)/i', $key) === 1) {
                continue;
            }
            if (is_string($value)) {
                $clean = $this->cleanText($value);
                if ($clean !== null) {
                    $safe[$key] = $clean;
                }
            } elseif (is_array($value) && $value !== []) {
                $nested = $this->extractSafeKeys($value);
                if ($nested !== []) {
                    $safe[$key] = $nested;
                }
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayFrom(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return $this->safety->truncateInput($this->safety->redactSecrets($trimmed), 400);
    }
}
