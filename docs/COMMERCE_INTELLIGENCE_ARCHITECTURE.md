# HASEM Commerce Intelligence Foundation

## 1. Problem Statement
HASEM is a multi-tenant Laravel 13 modular monolith supporting varied commerce models across diverse sectors. Hardcoding industry-specific settings (e.g. `FashionSettings`, `AutomotiveSettings`, or `if ($domain === 'fashion')`) creates fragile, fragmented codebases and god classes.

The **Commerce Intelligence Foundation** decouples domain semantics from basic catalog storage:
- **Chat Catalog**: Answers *"What do I sell?"* (SKU, name, price, stock, variants, media, publication status).
- **Commerce Intelligence**: Answers *"What does this item mean, what customer context does it require, what is it compatible with, and what rules apply when evaluating or recommending it?"*

---

## 2. Core Architectural Principles
1. **Zero Industry Lock-In**: Generic primitives (`CommerceIntelligenceProfile`, `CommerceAttributeDefinition`, `CommerceRequirementDefinition`, `CommerceCompatibility`, `CommerceIntelligenceRule`, `CommerceCustomerContext`) represent any industry without custom code deployments.
2. **Catalog Authority Boundary**: Catalog remains the single source of truth for commercial facts (prices, stock, SKUs, publication, seller association). Intelligence adds semantic context around catalog items.
3. **Strict Tenancy & Isolation**: All models extend `WorkspaceScopedModel` with `workspace_id` FK, enforcing cross-tenant isolation and policy checks.
4. **Provenance & Authority Rules**: Data sources are explicitly tracked (`merchant_provided`, `merchant_rule`, `customer_explicit`, `system_derived`, `ai_inferred`). AI-inferred or customer-provided data **never** silently overwrites merchant-authoritative facts.
5. **Deterministic Evaluation (No Fake Certainty)**: `CommerceRecommendationEvaluator` returns structured evaluation results (`compatible`, `incompatible`, `insufficient_information`, `conditionally_suitable`). Missing required context triggers `insufficient_information` rather than guessed answers.
6. **AI & Order Seam Safety**: Intelligence is deterministic domain infrastructure. Inbox AI consumes intelligence through read-only tools and services. Payment and Order Engine boundaries remain untouched.

---

## 3. Core Models & Domain Primitives

### 3.1 Commerce Domain Profile (`CommerceIntelligenceProfile`)
Describes workspace domain classification (`primary_domain`, `secondary_domains`, `enabled_capabilities`, `metadata`) without becoming a monolithic settings table.

### 3.2 Generic Attribute System (`CommerceAttributeDefinition` & `CommerceEntityAttributeValue`)
Supports typed attributes (`string`, `integer`, `decimal`, `boolean`, `enum`, `date`, `datetime`, `measurement`, `dimension`, `range`, `reference`, `multi_value`) with units, validation constraints, searchability flags, and provenance tracking.

### 3.3 Requirements Framework (`CommerceRequirementDefinition`)
Defines customer context attributes required before making an authoritative recommendation (e.g., body measurements, vehicle year/make, room dimensions, service location).

### 3.4 Compatibility Engine (`CommerceCompatibility`)
Defines queryable, deterministic compatibility rules (e.g. phone case → compatible with → iPhone 15 Pro; car part → compatible with → Toyota Camry 2020 2.5L).

### 3.5 Rule Foundation (`CommerceIntelligenceRule`)
Executes structured operand/condition logic (`==`, `!=`, `>`, `>=`, `<`, `<=`, `in`, `contains`) without executing arbitrary stored code or PHP scripts.

### 3.6 Customer Context (`CommerceCustomerContext`)
Stores customer-supplied attributes (explicitly provided or validated) separately from catalog truth.

---

## 4. Industry Examples Using the SAME Architecture

### Example 1: Fashion Workspace
- **Domain Profile**: `primary_domain = fashion`
- **Attribute Definitions**:
  - `fabric_material` (string)
  - `chest_measurement` (measurement, unit: `cm`)
  - `waist_measurement` (measurement, unit: `cm`)
- **Requirement Definition**:
  - `bust_cm` (measurement, unit: `cm`, is_required: true)
  - `waist_cm` (measurement, unit: `cm`, is_required: true)
- **Rules**:
  - `rule_type = incompatibility`, condition: `waist < 65`, message: `غير مناسب لمقاس خصر أقل من 65 سم.`
- **Evaluation**: Customer provides `waist_cm = 60` → Evaluator returns `incompatible`.

### Example 2: Electronics Workspace
- **Domain Profile**: `primary_domain = electronics`
- **Attribute Definitions**:
  - `ram_gb` (integer, unit: `GB`)
  - `connector_type` (enum, options: `USB-C`, `Lightning`)
- **Compatibility**:
  - Source: Charger Item #10 → `compatibility_type = compatible`, target_reference: `{"connector": "USB-C"}`
- **Requirement Definition**:
  - `device_connector` (enum)
- **Evaluation**: Customer provides `device_connector = USB-C` → Evaluator returns `compatible`.

### Example 3: Automotive Workspace
- **Domain Profile**: `primary_domain = automotive`
- **Attribute Definitions**:
  - `oem_part_number` (string)
  - `engine_liters` (decimal, unit: `L`)
- **Compatibility**:
  - Source: Brake Pad Item #42 → target_reference: `{"make": "Toyota", "model": "Camry", "year": 2020}`
- **Requirement Definition**:
  - `vehicle_make`, `vehicle_model`, `vehicle_year` (required: true)
- **Evaluation**: Customer omits `vehicle_year` → Evaluator returns `insufficient_information`.

### Example 4: Furniture Workspace
- **Domain Profile**: `primary_domain = furniture`
- **Attribute Definitions**:
  - `width_cm` (dimension, unit: `cm`)
  - `depth_cm` (dimension, unit: `cm`)
  - `height_cm` (dimension, unit: `cm`)
- **Requirement Definition**:
  - `room_width_cm`, `room_depth_cm`
- **Rules**:
  - `rule_type = recommendation_constraint`, condition: `room_width_cm < 200`, message: `يتطلب عرض غرفة لا يقل عن 200 سم.`
- **Evaluation**: Evaluator returns `conditionally_suitable` with clearance warning.

### Example 5: Services Workspace
- **Domain Profile**: `primary_domain = services`
- **Attribute Definitions**:
  - `service_duration_mins` (integer, unit: `mins`)
  - `provider_required` (boolean)
- **Requirement Definition**:
  - `service_city` (string, is_required: true)
  - `requested_date` (date, is_required: true)
- **Evaluation**: Validates area & schedule constraints deterministically.

---

## 5. Security & Isolation Boundaries
- **Workspace Scoping**: Enforced via `WorkspaceScopedModel`, global scopes, and policy gates (`commerce.intelligence.view`, `commerce.intelligence.manage`, `commerce.intelligence.evaluate`).
- **Audit Logging**: All profile, definition, rule, compatibility, and attribute mutations are audited via `AuditLogService`. Sensitive credentials/secrets are never logged.
- **Payment & Order Boundaries**: Untouched. Orders remain at `pending_payment` state; payment links, HyperPay, and settlement execution remain separate.
