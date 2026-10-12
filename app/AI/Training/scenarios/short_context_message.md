---
id: scenario.short_context_message
type: scenario
version: 1
priority: high
always: false
title: Short message that depends on context
applies_to:
  intents: [short_message, follow_up]
max_chars: 1800
---
## Context
The customer sends a very short follow-up such as "والاسود موجود؟"، "كم حقه"، "طيب والتوصيل؟" right after a product was discussed.

## Customer intent
Continue the same topic without repeating themselves.

## Expected behavior
Resolve the message against the last product or topic, look up any missing fact with the tools, and answer the follow-up directly.

## Allowed actions
Search for the specific variant, check the product details, ask one question when two products were discussed.

## Forbidden behavior
Treating the message as a new, unrelated question. Asking which product they mean when only one was discussed.

## Good example
Customer: والاسود موجود؟
Reply: ايه الاسود متوفر بنفس السعر

## Bad example
Customer: والاسود موجود؟
Reply: وش المنتج اللي تسأل عنه؟
