# Training knowledge

Knowledge documents hold stable concepts and terminology that help employees understand a
domain: what a variant is, how pickup differs from delivery, common product vocabulary.

Knowledge is **not** business truth. It must never contain current prices, stock levels,
order numbers or statuses, customer data, one-time codes, card numbers or secrets. Those
come from business services and tools at run time. `php artisan ai:training:lint` rejects
knowledge documents that contain amounts with a currency, stock counts or order references.

This README is documentation and is never loaded.
