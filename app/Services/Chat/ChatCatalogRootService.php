<?php

namespace App\Services\Chat;

use App\Enums\ChatCatalog\ChatCatalogOperatingStatus;
use App\Models\ChatCatalog;
use App\Models\ChatCatalogCommunicationProfile;
use App\Models\ChatCatalogItem;
use App\Models\Commerce\Seller;
use App\Models\Workspace;
use App\Support\Chat\ChatCatalogDefaults;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ChatCatalogRootService
{
    public function __construct(private readonly ChatCatalogDataSanitizer $sanitizer) {}

    public function forWorkspace(Workspace $workspace): ChatCatalog
    {
        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): ChatCatalog {
            $existing = ChatCatalog::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->first();

            if ($existing) {
                return $existing->loadMissing('communicationProfile');
            }

            return $this->provision($workspace);
        });
    }

    public function provision(Workspace $workspace): ChatCatalog
    {
        try {
            return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): ChatCatalog {
                return DB::transaction(function () use ($workspace): ChatCatalog {
                    $legacyCatalogHasItems = ChatCatalogItem::withoutGlobalScopes()
                        ->where('workspace_id', $workspace->id)
                        ->exists();
                    $attributes = ChatCatalogDefaults::catalogRoot($workspace->id, $workspace->name ?? 'Catalog');

                    // Existing v1 catalog rows remain searchable after migration.
                    if ($legacyCatalogHasItems) {
                        $attributes['status'] = ChatCatalogOperatingStatus::Published;
                        $attributes['published_at'] = now();
                    }

                    $catalog = ChatCatalog::withoutGlobalScopes()->create($attributes);

                    ChatCatalogCommunicationProfile::withoutGlobalScopes()->create([
                        ...ChatCatalogDefaults::communicationProfile(),
                        'chat_catalog_id' => $catalog->id,
                        'workspace_id' => $workspace->id,
                    ]);

                    return $catalog->fresh(['communicationProfile']);
                });
            });
        } catch (UniqueConstraintViolationException) {
            return ChatCatalog::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->with('communicationProfile')
                ->firstOrFail();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(ChatCatalog $catalog, array $data): ChatCatalog
    {
        return $this->inCatalogWorkspace($catalog, function () use ($catalog, $data): ChatCatalog {
            $payload = collect($data)->only($catalog->getFillable())->all();
            if (array_key_exists('commerce_seller_id', $payload) && $payload['commerce_seller_id'] !== null) {
                $seller = Seller::withoutGlobalScopes()
                    ->where('workspace_id', $catalog->workspace_id)
                    ->whereKey((int) $payload['commerce_seller_id'])
                    ->first();
                if (! $seller) {
                    throw new InvalidArgumentException('Seller does not belong to the catalog workspace.');
                }
            }
            if (array_key_exists('seller_support_contact', $payload)) {
                $payload['seller_support_contact'] = $this->filledSellerSupportContact($payload['seller_support_contact']);
            }
            $sellerId = array_key_exists('commerce_seller_id', $payload)
                ? $payload['commerce_seller_id']
                : $catalog->commerce_seller_id;
            if ($sellerId === null && array_key_exists('commerce_seller_id', $payload)) {
                $payload['seller_public_display_name'] = null;
                $payload['seller_support_contact'] = null;
            } elseif ($sellerId === null && $this->sellerProfileDataProvided($payload)) {
                throw new InvalidArgumentException('Seller profile settings require a workspace seller.');
            }

            foreach ([
                'display_settings' => ChatCatalogDefaults::displaySettings(),
                'discovery_settings' => ChatCatalogDefaults::discoverySettings(),
                'channel_visibility' => ChatCatalogDefaults::channelVisibility(),
                'customer_service_policy' => ChatCatalogDefaults::customerServicePolicy(),
                'availability_schedule' => ChatCatalogDefaults::availabilitySchedule(),
                'search_weights' => ChatCatalogDefaults::searchWeights(),
            ] as $field => $defaults) {
                if (array_key_exists($field, $payload)) {
                    $payload[$field] = array_replace(
                        $defaults,
                        is_array($catalog->{$field}) ? $catalog->{$field} : [],
                        is_array($payload[$field]) ? $payload[$field] : [],
                    );
                }
            }

            if (array_key_exists('customer_templates', $payload)) {
                $payload['customer_templates'] = array_replace_recursive(
                    ChatCatalogDefaults::customerTemplates(),
                    is_array($catalog->customer_templates) ? $catalog->customer_templates : [],
                    $this->sanitizer->customerTemplates(is_array($payload['customer_templates']) ? $payload['customer_templates'] : []),
                );
            }
            if (array_key_exists('customer_service_policy', $payload)) {
                foreach ([
                    'out_of_hours_message',
                    'unavailable_product_message',
                    'unavailable_catalog_message',
                    'backorder_message',
                    'low_stock_message',
                    'digital_delivery_notice',
                    'payment_required_notice',
                    'support_contact_instructions',
                ] as $key) {
                    if (isset($payload['customer_service_policy'][$key])) {
                        $payload['customer_service_policy'][$key] = $this->sanitizer->plainText(
                            $payload['customer_service_policy'][$key],
                            1000,
                        );
                    }
                }
            }
            if (isset($payload['availability_schedule']['custom_closed_message'])) {
                $payload['availability_schedule']['custom_closed_message'] = $this->sanitizer->plainText(
                    $payload['availability_schedule']['custom_closed_message'],
                    1000,
                );
            }

            foreach (['name', 'internal_name', 'public_name', 'description', 'short_description', 'brand_description', 'legal_business_name', 'public_title', 'public_description', 'seller_public_display_name'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $payload[$field] = $this->sanitizer->plainText(
                        $payload[$field],
                        in_array($field, ['short_description'], true) ? 512 : 5000,
                    );
                }
            }
            foreach (['social_links', 'public_support_contact', 'seller_support_contact', 'seo'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $payload[$field] = $this->sanitizer->sanitizeArray(is_array($payload[$field]) ? $payload[$field] : []);
                }
            }
            if (isset($payload['seo']['canonical_slug'])
                && $payload['seo']['canonical_slug'] !== ''
                && $payload['seo']['canonical_slug'] !== ($payload['slug'] ?? $catalog->slug)) {
                throw new InvalidArgumentException('Canonical slug must match the catalog slug.');
            }

            $catalog->update($payload);

            return $catalog->fresh(['communicationProfile']);
        });
    }

    /**
     * Drop null and blank contact values. An array of empty keys is not seller data.
     *
     * @return array<string, mixed>|null
     */
    private function filledSellerSupportContact(mixed $contact): ?array
    {
        if (! is_array($contact)) {
            return null;
        }

        $filled = collect($contact)
            ->filter(fn (mixed $value): bool => filled(is_string($value) ? trim($value) : $value))
            ->all();

        return $filled === [] ? null : $filled;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sellerProfileDataProvided(array $payload): bool
    {
        $displayName = $payload['seller_public_display_name'] ?? null;
        if (is_string($displayName)) {
            $displayName = trim($displayName);
        }

        return filled($displayName) || $this->filledSellerSupportContact($payload['seller_support_contact'] ?? null) !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCommunicationProfile(ChatCatalog $catalog, array $data): ChatCatalogCommunicationProfile
    {
        return $this->inCatalogWorkspace($catalog, function () use ($catalog, $data): ChatCatalogCommunicationProfile {
            $profile = $catalog->communicationProfile;
            if (! $profile) {
                throw new InvalidArgumentException('Communication profile missing.');
            }

            $payload = collect($data)->only($profile->getFillable())->all();
            if (array_key_exists('supported_languages', $payload)) {
                $payload['supported_languages'] = array_values(array_unique($payload['supported_languages']));
            }
            $profile->update($payload);

            return $profile->fresh();
        });
    }

    public function publish(ChatCatalog $catalog): ChatCatalog
    {
        return $this->inCatalogWorkspace($catalog, function () use ($catalog): ChatCatalog {
            $catalog->update([
                'status' => ChatCatalogOperatingStatus::Published,
                'published_at' => now(),
                'archived_at' => null,
            ]);

            return $catalog->fresh();
        });
    }

    public function draft(ChatCatalog $catalog): ChatCatalog
    {
        return $this->inCatalogWorkspace($catalog, function () use ($catalog): ChatCatalog {
            $catalog->update([
                'status' => ChatCatalogOperatingStatus::Draft,
                'published_at' => null,
                'archived_at' => null,
            ]);

            return $catalog->fresh();
        });
    }

    public function pause(ChatCatalog $catalog): ChatCatalog
    {
        return $this->inCatalogWorkspace($catalog, function () use ($catalog): ChatCatalog {
            $catalog->update([
                'status' => ChatCatalogOperatingStatus::Paused,
            ]);

            return $catalog->fresh();
        });
    }

    public function archive(ChatCatalog $catalog): ChatCatalog
    {
        return $this->inCatalogWorkspace($catalog, function () use ($catalog): ChatCatalog {
            $catalog->update([
                'status' => ChatCatalogOperatingStatus::Archived,
                'archived_at' => now(),
            ]);

            return $catalog->fresh();
        });
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function inCatalogWorkspace(ChatCatalog $catalog, \Closure $callback): mixed
    {
        $workspace = $catalog->workspace;
        if (! $workspace) {
            throw new InvalidArgumentException('Catalog is missing workspace.');
        }

        return app(WorkspaceContext::class)->runFor($workspace, $callback);
    }
}
