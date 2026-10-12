---
id: profile.default
type: profile
version: 1
priority: normal
always: false
title: Default customer chat profile
max_chars: 600
includes: [rule.product_handling, playbook.product_assistance]
---
Default profile for a customer-facing chat employee. It adds product handling on top of the always-on rules; skills, dialect, language and intent add the rest.
