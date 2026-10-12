<?php

declare(strict_types=1);

namespace App\AI\Skills;

final class SkillRegistry
{
    private const ALL_DOMAINS = ['general', 'fashion', 'electronics', 'restaurant', 'beauty', 'services'];

    private const SALES_INTENTS = [
        'greeting', 'short_message', 'follow_up', 'price_question', 'availability_question',
        'product_search', 'product_details', 'purchase_intent', 'discount_request', 'objection',
    ];

    private const SUPPORT_INTENTS = [
        'greeting', 'short_message', 'follow_up', 'human_request', 'complaint', 'confusion', 'off_topic',
    ];

    private const PRODUCT_INTENTS = [
        'short_message', 'price_question', 'availability_question', 'product_search', 'product_details', 'purchase_intent',
    ];

    private const HANDOFF_INTENTS = ['human_request', 'complaint', 'confusion'];

    private const CATALOG_READ_TOOLS = ['search_products', 'get_product_intelligence'];

    private const CATALOG_RECOMMEND_TOOLS = ['search_products', 'get_product_intelligence', 'evaluate_product_suitability', 'save_customer_commerce_context'];

    private static ?self $default = null;

    /** @var array<string, SkillDefinition> */
    private array $definitions = [];

    private string $fingerprint;

    /**
     * @param  iterable<SkillDefinition>  $definitions
     */
    public function __construct(iterable $definitions = [])
    {
        $items = [];
        foreach ($definitions as $definition) {
            if (! $definition instanceof SkillDefinition) {
                throw SkillRegistryException::invalidDefinition('Every skill must be a SkillDefinition instance.');
            }
            $items[] = $definition;
        }

        $this->definitions = $this->index($items === [] ? self::builtInDefinitions() : $items);
        $this->fingerprint = $this->computeFingerprint($this->definitions);
    }

    /**
     * @return list<SkillDefinition>
     */
    public static function all(bool $includeDisabled = true): array
    {
        return self::sharedRegistry()->allDefinitions($includeDisabled);
    }

    /**
     * @return list<SkillDefinition>
     */
    public static function enabled(): array
    {
        return self::sharedRegistry()->enabledDefinitions();
    }

    public static function find(string $key, bool $includeDisabled = true): ?SkillDefinition
    {
        return self::sharedRegistry()->definition($key, $includeDisabled);
    }

    public static function exists(string $key, bool $includeDisabled = true): bool
    {
        return self::find($key, $includeDisabled) !== null;
    }

    /**
     * @param  list<string>  $keys
     */
    public static function validateKeys(array $keys): void
    {
        self::sharedRegistry()->validateSkillKeys($keys);
    }

    /**
     * @return list<string>
     */
    public static function enabledKeys(): array
    {
        return self::sharedRegistry()->skillKeys(false);
    }

    /**
     * @return list<string>
     */
    public static function keys(bool $includeDisabled = true): array
    {
        return self::sharedRegistry()->skillKeys($includeDisabled);
    }

    public static function fingerprint(): string
    {
        return self::sharedRegistry()->registryFingerprint();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function emptySettingsForKeys(array $keys): array
    {
        return self::sharedRegistry()->settingsMapForKeys($keys);
    }

    /**
     * @return list<SkillDefinition>
     */
    public function allDefinitions(bool $includeDisabled = true): array
    {
        $definitions = array_values($this->definitions);

        if ($includeDisabled) {
            return $definitions;
        }

        return array_values(array_filter($definitions, static fn (SkillDefinition $definition): bool => $definition->enabled));
    }

    /**
     * @return list<SkillDefinition>
     */
    public function enabledDefinitions(): array
    {
        return $this->allDefinitions(false);
    }

    public function definition(string $key, bool $includeDisabled = true): ?SkillDefinition
    {
        $definition = $this->definitions[$key] ?? null;
        if ($definition === null) {
            return null;
        }

        if (! $includeDisabled && ! $definition->enabled) {
            return null;
        }

        return $definition;
    }

    /**
     * @param  list<string>  $keys
     */
    public function validateSkillKeys(array $keys, bool $allowDisabled = false): void
    {
        foreach (array_values(array_unique($keys)) as $key) {
            if (! is_string($key)) {
                throw SkillRegistryException::invalidDefinition('Skill keys must be strings.');
            }
            $definition = $this->definitions[$key] ?? null;
            if ($definition === null) {
                throw SkillRegistryException::unknownSkill($key);
            }
            if (! $allowDisabled && ! $definition->enabled) {
                throw SkillRegistryException::disabledSkill($key);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function skillKeys(bool $includeDisabled = true): array
    {
        return array_map(
            static fn (SkillDefinition $definition): string => $definition->key,
            $this->allDefinitions($includeDisabled),
        );
    }

    public function registryFingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, array<string, mixed>>
     */
    public function settingsMapForKeys(array $keys): array
    {
        $this->validateSkillKeys($keys);
        $map = [];
        foreach (array_values(array_unique($keys)) as $key) {
            $map[$key] = [];
        }
        ksort($map, SORT_STRING);

        return $map;
    }

    private static function sharedRegistry(): self
    {
        return self::$default ??= new self(self::builtInDefinitions());
    }

    /**
     * @param  list<SkillDefinition>  $definitions
     * @return array<string, SkillDefinition>
     */
    private function index(array $definitions): array
    {
        $indexed = [];
        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->key])) {
                throw SkillRegistryException::duplicateKey($definition->key);
            }
            $indexed[$definition->key] = $definition;
        }
        ksort($indexed, SORT_STRING);

        return $indexed;
    }

    /**
     * @param  array<string, SkillDefinition>  $definitions
     */
    private function computeFingerprint(array $definitions): string
    {
        $payload = [];
        foreach ($definitions as $definition) {
            $payload[$definition->key] = $definition->versionFingerprint();
        }

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<SkillDefinition>
     */
    private static function builtInDefinitions(): array
    {
        return [
            new SkillDefinition(
                key: 'complaint_handling',
                name: 'Complaint Handling',
                description: 'Handle complaints, calm escalations, and keep expectations truthful.',
                category: 'support',
                supportedIntents: ['complaint', 'confusion', 'follow_up', 'human_request'],
                supportedDomains: self::ALL_DOMAINS,
                allowedTools: [],
                settingsValidation: self::freeformSettings(),
            ),
            new SkillDefinition(
                key: 'customer_support',
                name: 'Customer Support',
                description: 'Answer service, policy, and after-sales questions clearly and safely.',
                category: 'support',
                supportedIntents: self::SUPPORT_INTENTS,
                supportedDomains: self::ALL_DOMAINS,
                allowedTools: self::CATALOG_READ_TOOLS,
                settingsValidation: self::freeformSettings(),
            ),
            new SkillDefinition(
                key: 'faq',
                name: 'FAQ',
                description: 'Handle common questions with short, direct guidance grounded in available facts.',
                category: 'knowledge',
                supportedIntents: ['greeting', 'follow_up', 'off_topic', 'short_message'],
                supportedDomains: self::ALL_DOMAINS,
                allowedTools: [],
                settingsValidation: self::freeformSettings(),
            ),
            new SkillDefinition(
                key: 'human_handoff',
                name: 'Human Handoff',
                description: 'Recognize and support requests to continue with a human teammate.',
                category: 'workflow',
                supportedIntents: self::HANDOFF_INTENTS,
                supportedDomains: self::ALL_DOMAINS,
                allowedTools: [],
                settingsValidation: self::freeformSettings(),
            ),
            new SkillDefinition(
                key: 'order_handling',
                name: 'Order Handling',
                description: 'Legacy-compatible order workflow guidance for existing employee records.',
                category: 'workflow',
                supportedIntents: ['follow_up', 'purchase_intent'],
                supportedDomains: self::ALL_DOMAINS,
                allowedTools: [],
                settingsValidation: self::freeformSettings(),
            ),
            new SkillDefinition(
                key: 'product_recommendation',
                name: 'Product Recommendation',
                description: 'Recommend relevant products using available catalog facts only.',
                category: 'catalog',
                supportedIntents: ['product_search', 'product_details', 'purchase_intent', 'short_message'],
                supportedDomains: self::ALL_DOMAINS,
                allowedTools: self::CATALOG_RECOMMEND_TOOLS,
                settingsValidation: self::freeformSettings(),
            ),
            new SkillDefinition(
                key: 'product_search',
                name: 'Product Search',
                description: 'Find products, prices, and availability from the chat catalog.',
                category: 'catalog',
                supportedIntents: self::PRODUCT_INTENTS,
                supportedDomains: self::ALL_DOMAINS,
                allowedTools: ['search_products'],
                settingsValidation: self::freeformSettings(),
            ),
            new SkillDefinition(
                key: 'sales',
                name: 'Sales',
                description: 'Drive product-focused sales conversations without inventing offers or facts.',
                category: 'sales',
                supportedIntents: self::SALES_INTENTS,
                supportedDomains: self::ALL_DOMAINS,
                allowedTools: self::CATALOG_RECOMMEND_TOOLS,
                settingsValidation: [
                    'type' => 'object',
                    'properties' => [
                        'style' => [
                            'type' => 'string',
                            'description' => 'Optional sales approach label for future prompt shaping.',
                        ],
                    ],
                    'allow_additional_properties' => true,
                ],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function freeformSettings(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'allow_additional_properties' => true,
        ];
    }
}
