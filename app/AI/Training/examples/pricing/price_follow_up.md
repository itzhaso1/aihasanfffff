---
id: example.pricing.price_follow_up
type: example
version: 1
priority: normal
always: false
title: Price question about the current product
applies_to:
  intents: [price_question]
max_chars: 1200
---
## Customer
كم سعره؟

## Context
The previous turns are about one product, and its price is in the product data.

## Expected behavior
Answer with the price directly. Do not restate the product name or add unrelated details.

## Good response
سعره 149 ريال

## Bad response
بكل سرور، سعر المنتج الذي تسأل عنه هو 149 ريال سعودي فقط.

## Why
The customer already knows which product they mean. A direct answer respects that; filler and repetition make the reply robotic.
