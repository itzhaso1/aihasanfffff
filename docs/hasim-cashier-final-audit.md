# FINAL AUDIT — كاشير حاسم

Branch: `cursor/hasim-cashier-app-757c`  
تاريخ: 2026-09-01

## API GAP (additive فقط تحت `/api/cashier/v1`)

| Endpoint | Status | Notes |
|----------|--------|-------|
| GET /kitchen/orders | READY | جديد |
| GET /reports/daily | READY | جديد |
| POST /tables | READY | جديد |
| POST /tables/.../note | READY | جديد |
| GET /catalog/items/{item} | READY | جديد |
| GET /auth/me + permissions | READY | enrich |
| Invoice tax_amount + payment_method + store_name | READY | enrich من orders |
| Table payload open_orders_count/items_count | READY | enrich |
| MenuItem availability | READY | enrich |
| Catalog write (items CRUD) | BLOCKED | يحتاج سياسات كتابة أوسع؛ الويب يبقى للإدارة |
| Printer hardware send | BLOCKED | يحتاج Native SDK |
| Pusher live socket | BLOCKED | يحتاج credentials |

## Feature matrix

| Feature | Web | API | Flutter | Status | Problems | Solution |
|---------|-----|-----|---------|--------|----------|----------|
| Cashier layout | 2\|7\|3 denser | catalog | denser grid + narrower cart | READY | مساحات كانت واسعة | ProductCard denser + ratios |
| Categories | sidebar | /catalog/categories | sidebar + mobile strip | READY | — | — |
| Products | cards | /catalog/items | denser + SKU + availability | READY | — | — |
| Cart | panel | orders POST | totals + qty touch | READY | — | — |
| Order types | طاولة/خارجي/توصيل | order_type | same labels | READY | — | — |
| Success print optional | modal | invoice POST | dialog | READY | — | — |
| Tables board | live board | /tables | board + wizards | READY | — | — |
| Table note | yes | /note | عبر API | PARTIAL | UI note dialog بسيط | endpoint جاهز |
| Kitchen | yes | /kitchen/orders | KitchenBoard | READY | — | — |
| Reports | daily | /reports/daily | DailyReportsPanel | READY | — | — |
| Invoices | yes | enriched show | detail+print arch | PARTIAL | print hardware | Native gateway |
| Menu orders | poll | /orders?menu | badge+sound | READY | realtime socket | Polling OK |
| Offline sync | n/a | client_reference | Pending…Failed | READY | table ops online-only | ConflictStrategy |
| Items admin write | yes | missing write | placeholder | BLOCKED | no write API | Additive later |
| Printing ESC/POS | browser | n/a | architecture | BLOCKED | no device bridge | Native SDK |
| Realtime WS | Echo | channel name | PosEventSource | PARTIAL | no credentials | keep polling |

## READY / PARTIAL / MISSING / BLOCKED

### READY
Cashier denser UI, Categories, Products, Cart, Orders, Menu Orders+sound, Kitchen API+UI, Reports API+UI, Table CRUD create, Table actions wizards, Offline queue, Permissions gating, Laravel+Flutter tests.

### PARTIAL
Invoice hardware print path, Realtime websocket, Table session note UI polish, Delivery customer form depth, Pixel-perfect vs mockup screenshots.

### MISSING
Items/Categories write from Flutter, Shifts (لا في Laravel).

### BLOCKED
1. **Thermal printer send** — يحتاج SDK أصلي لكل منصة؛ الحالي يرفض Fake success.
2. **Pusher/Reverb live** — يحتاج keys + infra؛ Polling يعمل.
3. **Catalog write API** — لم يُنشأ عمدًا كـwrite surface إداري كامل في هذه الجولة (قراءة READY)؛ يمكن إضافته Additive لاحقًا.

## Tests
- `php artisan test --filter=CashierApiV1Test` 
- `flutter test` (13)
- لا تعديل `apps/hasim` / `/api/mobile/v1`
