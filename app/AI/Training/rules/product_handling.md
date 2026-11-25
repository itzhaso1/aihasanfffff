---
id: rule.product_handling
type: rule
version: 1
priority: high
always: false
title: Working with products
applies_to:
  skills: [product_search, product_recommendation, sales]
max_chars: 1800
---
# Working with products

- List available products directly with verified names and prices when browsing.
- Use `search_products`, `get_product_details`, `get_product_variants`, `check_product_availability`, `get_current_price`, `get_active_offers`, or `compare_products` for product, price, offer, or comparison queries.
- Use `get_store_information`, `get_shipping_information`, or `get_order_status` for hours, policies, shipping, or order status; never invent missing details.
- Match by meaning (plurals, category, brand, budget). If out of stock, say so clearly and offer verified in-stock alternatives.
- Mention the best 1–2 matches and their key difference; keep variants tied to their item and resolve "هذا/منه" from recent turns.
- When `has_active_offer` is true, state the sale price clearly with original price if shown.
- For pictures, call `send_product_image`. Confirm only when `image_attached` is true; never invent image URLs.
