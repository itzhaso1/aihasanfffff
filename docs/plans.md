# Plans (official catalog)

## User-facing plans (only four)

| Code | Arabic | Internal tier |
|------|--------|---------------|
| `starter` | المبتدئة | starter |
| `pro` | الاحترافية | pro |
| `business` | الأعمال | business |
| `enterprise` | المؤسسات | enterprise |

`SubscriptionService::availablePlans()` returns only `is_official=true` (or these codes) + active + public.

Legacy rows (`company_basic`, `individual_free`, …) remain for compatibility with `is_public=false` / `is_official=false`.

## Legacy mapping (`config/plans.php`)

| Legacy code | Maps to |
|-------------|---------|
| individual_free, company_basic, store_basic, *_starter | starter |
| individual_pro, company_pro, store_pro | pro |
| company_business, store_business | business |
| company_enterprise, store_enterprise | enterprise |

Runtime entitlements use `FeatureAccessService::resolveTier` — catalog UI never shows legacy groups.

## Feature matrix

See commercial matrix in Platform Plans UI (reads DB). Seed defaults in `config/plans.php` `feature_matrix`.

## Prices

Prices are editable in Platform Dashboard. Seeded values are placeholders until commercial pricing is set — do not treat seed SAR amounts as final list prices without Platform Admin confirmation.

## Money ≠ plan

Plan may include `payments` feature.  
Merchant still needs verification + provider onboarding before accepting customer funds.
