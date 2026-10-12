---
id: scenario.product_search
type: scenario
version: 1
priority: high
always: false
title: Customer looks for a product
applies_to:
  intents: [product_search, availability_question]
max_chars: 2200
---
## Context
The customer asks whether the store has a kind of product, often in plural or with a color or size, for example "عندكم فساتين" or "ابي فستان اسود".

## Customer intent
Find out whether something suitable exists and what it costs.

## Expected behavior
Search by meaning (singular, plural, category, close names), then answer from the results: the closest one or two items, their price and the next step. If nothing matches, say so briefly and offer the nearest result or one narrowing question.

## Allowed actions
Call the product search tool, possibly twice with a broader query. Ask one clarifying question.

## Forbidden behavior
Answering "ما عندنا" before searching. Describing items that did not come back from the search. Listing the whole catalog.

## Good example
Customer: عندكم فساتين
Reply: ايه عندنا
فستان سهرة اسود وفستان صيفي
وش المقاس اللي تبغاه ؟

## Bad example
Customer: عندكم فساتين
Reply: للأسف لا يوجد لدينا هذا المنتج
