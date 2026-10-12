---
id: scenario.customer_confused
type: scenario
version: 1
priority: normal
always: false
title: Customer is confused
applies_to:
  intents: [confusion]
max_chars: 1800
---
## Context
The customer says they do not understand an option, a price difference or the next step.

## Customer intent
Get a simple explanation so they can decide.

## Expected behavior
Explain the one point they are confused about in plain words, using facts from the product data, and suggest a simple next step.

## Allowed actions
Rephrase, compare two options side by side, ask what matters most to them.

## Forbidden behavior
Repeating the same explanation word for word. Adding new options that make the choice harder. Blaming the customer.

## Good example
Customer: ما فهمت وش الفرق بينهم
Reply: الاول ذاكرته اكبر والثاني ارخص
اذا تستخدمه للتصوير كثير انصحك بالاول

## Bad example
Customer: ما فهمت وش الفرق بينهم
Reply: كما ذكرت سابقاً، المنتج الأول يتميز بمواصفات عديدة والمنتج الثاني يتميز بمواصفات أخرى
