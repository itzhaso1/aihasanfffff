# Hasim AI Training Library

Training teaches AI employees **how to behave**: writing style, conversation habits,
sales and support workflows, product handling, examples and scenarios. It sits below the
constitution in the instruction order and never overrides it.

- Constitution = laws (`app/AI/Constitution`)
- Training = behavior, skills, examples, scenarios (this directory)
- Business truth = application services and tools (never training)

## Structure

```
profiles/     type: profile   – named bundles of documents for an employee training profile
rules/        type: rule      – behavioral rules, always-on or selected by skill/dialect/...
examples/     type: example   – one situation with good and bad responses (one sub-folder level)
scenarios/    type: scenario  – a situation with expected, allowed and forbidden behavior
playbooks/    type: playbook  – step-by-step behavioral workflows
knowledge/    type: knowledge – stable concepts and terminology, never live business facts
```

`README.md` files are documentation and are never loaded.

## Document format

Every document starts with front matter:

```
---
id: rule.communication          # "<type>.<name>", lowercase, unique
type: rule                      # rule | example | scenario | playbook | knowledge | profile
version: 1                      # positive integer, bump when behavior changes
priority: high                  # critical | high | normal | low
always: false                   # true only for rules without applies_to
title: Short human title        # optional
applies_to:                     # optional selectors; every declared dimension must match
  skills: [sales]
  intents: [short_message]
  dialects: [saudi]
  languages: [ar]
  roles: [sales_agent]
  domains: [fashion]
max_chars: 2500                 # body length limit for this document
includes: [rule.sales]          # profiles only
---
```

Examples must contain the sections `## Customer`, `## Expected behavior`,
`## Good response`, `## Bad response`, `## Why`. Scenarios must contain `## Context`,
`## Customer intent`, `## Expected behavior`, `## Allowed actions`,
`## Forbidden behavior`, `## Good example`, `## Bad example`. Examples and scenarios must
declare `applies_to.intents` so they are only selected for matching situations.

Selector values must be listed in the canonical vocabularies; skill selectors come from
`App\AI\Skills\SkillRegistry`, while the remaining selector dimensions continue through
`TrainingVocabulary`. The linter rejects unknown selectors.

## Selection

`TrainingContextBuilder` never sends the whole library. For one run it collects, in order:

1. always-on rules
2. documents included by the employee's training profile
3. documents matching skills, 4. dialect, 5. language, 6. intent, 7. role, 8. domain
9. examples and scenarios matching the run

Within a tier: priority, then specificity (how many selector dimensions matched), then id.
Documents are added in that order while they fit the character budget
(`TrainingLimits::DEFAULT_CONTEXT_BUDGET_CHARS` unless the query sets one); a document
that does not fit is skipped and reported as omitted. Always-on rules must fit, otherwise
selection fails. Selection is deterministic: no randomness, no filesystem order.

## Versioning

- Document `contentHash`: SHA-256 of normalized metadata and body.
- Document version label: `<version>:<first 16 hex of contentHash>`, recorded per document
  in `AiDecision.trainingVersions`.
- Selection version: SHA-256 over the selected documents in order (id, version, hash).
- Library version: SHA-256 over every document id and content hash.

Line endings and a UTF-8 BOM are normalized before hashing, so CRLF checkouts keep the same
versions. Any content change produces new versions; timestamps are never used.

## Validation and commands

- `php artisan ai:training:lint` – full check: front matter, types, versions, selectors,
  sizes, layout, duplicates, constitution restatements, live business facts in knowledge
  and sensitive data anywhere. Exits non-zero on any issue.
- `php artisan ai:training:index` – rebuilds the index from source and prints the library
  version and counts.

Both commands are read-only on training files. The runtime loader fails closed: a library
with any structural problem is never partially used.

## Knowledge boundary

Knowledge holds stable concepts only. Prices, stock, order status or numbers, customer
data, one-time codes, card numbers and secrets never belong here; they come from business
services and tools at run time. Examples may show illustrative prices inside a reply.

## Editing and approval

- Change training through normal code review. Files are versioned in Git, auditable and
  can be rolled back like code. Run `ai:training:lint` and the AI tests before merging.
- Training is generic: no employee names, provider names or channel names.
- Live conversations never edit training. The intended loop is: conversation → evaluation
  → failure → suggested change → human approval → new version → regression tests → publish.
