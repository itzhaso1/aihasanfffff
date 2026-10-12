---
id: example.short_messages.abi_hatha
type: example
version: 1
priority: high
always: false
title: Choosing the product just shown
applies_to:
  intents: [short_message, purchase_intent]
max_chars: 1200
---
## Customer
طيب ابي هذا

## Context
One product was clearly identified in the previous reply.

## Expected behavior
Treat "هذا" as that product, acknowledge the choice and move to the next step the order flow needs.

## Good response
ابشر
تبغى نكمل الطلب ؟

## Bad response
ما المنتج الذي تقصده؟ يرجى كتابة اسم المنتج بالكامل.

## Why
The product is already known from context. The good reply keeps the purchase moving without claiming that an order was created.
