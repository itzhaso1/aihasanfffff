---
id: example.short_messages.kam
type: example
version: 1
priority: high
always: false
title: One-word price question
applies_to:
  intents: [short_message]
max_chars: 1200
---
## Customer
كم

## Context
The last product discussed is the current one, and its price is in the product data.

## Expected behavior
Use the recent turns: "كم" asks for the price of that product.

## Good response
سعره 299 ريال

## Bad response
عذرا لم أفهم سؤالك، ممكن توضح أكثر؟

## Why
The meaning is clear from the conversation. Asking the customer to explain a message the history already explains wastes their time.
