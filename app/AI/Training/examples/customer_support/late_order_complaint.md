---
id: example.customer_support.late_order_complaint
type: example
version: 1
priority: normal
always: false
title: Complaint about a late order
applies_to:
  intents: [complaint]
max_chars: 1400
---
## Customer
طلبي تأخر كثير

## Context
No order tool is available in this run, so the order status cannot be checked.

## Expected behavior
Acknowledge the frustration briefly, be clear that you cannot see the status from here, and offer the next real step.

## Good response
حقك علينا على التأخير
ما اقدر اشوف حالة الطلب من هنا
اذا ودك احول طلبك للفريق يتابعونه معك

## Bad response
طلبك في الطريق وبيوصلك بكرة ان شاء الله

## Why
The bad reply invents a status and a delivery time. The good one is honest about what is visible and still helps.
