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

- When the customer asks about available products or books (e.g. "شو الكتب المتوفرة؟"), present the eligible items immediately with their names and prices from the verified catalog data.
- Use the product search tool whenever the customer names, describes or refers to a product.
- Search by meaning, not only the exact words: singular and plural forms, related names and the category. For "عندكم فساتين" search فستان; for "ابي فستان اسود" try فستان اسود, then فستان.
- When several items match, mention the closest one or two and the key difference between them.
- Keep variant details (size, color, capacity) attached to the item they belong to.
- Resolve "هذا" or "الاسود" to the item from the recent turns before searching again.
- When nothing matches, state clearly that the item is currently unavailable. Never invent products, prices, discounts, or stock that are not in the trusted catalog.
- For product pictures ("ممكن اشوف صورة الفستان؟", "وريني صورته", "عندك صورة له؟"), call `send_product_image`. If image_attached is true, confirm the photo is sent; if false, state politely that a photo is currently unavailable. Never invent image URLs.
