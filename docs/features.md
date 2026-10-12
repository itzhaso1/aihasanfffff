# Features

Feature flags and plan entitlements gate product modules.

## Official commercial matrix (source of truth = `plans` table)

After seed / Platform Dashboard edits, FeatureAccessService reads DB JSON — not hardcoded controllers.

| Feature | Starter | Pro | Business | Enterprise |
|---------|---------|-----|----------|------------|
| AI | ✓ | ✓ | ✓ | ✓ |
| WhatsApp | — | ✓ | ✓ | ✓ |
| POS | — | ✓ | ✓ | ✓ |
| Finance | — | ✓ | ✓ | ✓ |
| Email | — | ✓ | ✓ | ✓ |
| API | — | — | ✓ | ✓ |
| Analytics | — | ✓ | ✓ | ✓ |
| Advanced customers | — | — | ✓ | ✓ |

`payments` / `payment_gateway` on a plan means the **feature may be used if merchant-eligible** — it does **not** approve the merchant.

## How to check in code

```php
$featureAccess->hasFeature($user, $workspace, 'analytics');
$featureAccess->assertCanUse($user, $workspace, 'ai', 'ai_usage');
app(MerchantPaymentEligibilityService::class)->assertCanAcceptCustomerPayments($workspace);
```

Middleware example:

```php
Route::middleware('workspace.feature:analytics')->group(...);
Route::middleware('workspace.feature:api')->group(...);
```

## Recently productized surfaces

| Feature | Route / UI |
|---------|------------|
| Analytics | `workspace/analytics` |
| API keys | `workspace/api-keys` |
| Merchant payments | `workspace/payments/merchant` |
| Platform merchant queue | `platform/merchant-verifications` |

## Overrides

`workspace_feature_flags` can force-enable/disable a key per workspace.

## Compatibility

Legacy plan codes map via `config/plans.php` `legacy_code_tier_map` + `FeatureAccessService::resolveTier`.
