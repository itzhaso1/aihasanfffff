# Hasim Chat — Database Cleanup Audit and Execution

**التاريخ:** 2026-10-06  
**النطاق:** التحقق من آثار Cart وStorefront وMarketplace وSellers وProduct Templates، مع حماية Chat وInbox وWorker وAI وCommerce وPOS وCashier وInvoices وFinance وE-Invoicing/ZATCA وقاعدة Drift.

## النتيجة التنفيذية

تم تنفيذ تدقيق الحذف على النسخة الحالية. لم تتم إضافة migration حذف جديدة، ولم يتم حذف جدول أو Model أو Route في هذه العملية؛ لأن كل الجداول المتبقية ضمن الأهداف إما:

- مستخدمة فعليًا من Laravel الحالي.
- مرتبطة بعقود Mobile/Web قائمة.
- مرتبطة بعلاقات Commerce أو Orders أو Catalog.
- أو أن آثارها أزيلت سابقًا بواسطة migrations موجودة ومطبقة.

إضافة migration حذف جديدة في هذه الحالة ستكون حذفًا غير مثبت، لذلك لم تُنشأ.

## Storefront — تم تنظيفه سابقًا وتأكدت من عدم بقاء آثاره

تم التحقق من أن:

- مسارات Public Storefront غير موجودة.
- `PublicStorefrontRenderer` و`StorefrontTemplateResolver` غير موجودين ككود فعلي.
- جداول Website/Storefront القديمة غير موجودة في قاعدة البيانات الحالية.
- أعمدة `storefront_preset` و`storefront_layout` و`storefront_theme` غير موجودة في `chat_catalogs`.

المigrations التي نفذت هذا التنظيف سابقًا وما زالت محفوظة في التاريخ:

- `2026_09_12_180000_retire_website_builder_and_appointment_tables`
- `2026_09_25_180000_drop_storefront_columns_from_chat_catalogs`

اختبار `PublicChatCatalogTest` يبقى مقصودًا؛ فهو يثبت أن Storefront العام والمسارات القديمة غير موجودة.

## Cart — لم يُحذف

المقصود الحالي هو Commerce Cart وليس Storefront Cart مهجورًا. الدليل:

- جدول `commerce_carts` موجود ويحتوي صفًا بحالة `active`.
- جدول `commerce_cart_items` مرتبط بـ `commerce_carts` وبـ Catalog.
- مسارات Mobile وWeb للسلات موجودة.
- `CommerceCartService` و`CommerceOrderService` يستخدمان السلة فعليًا للتحقق والتحويل إلى Order.
- `orders.commerce_cart_id` و`order_items` وعلاقات Commerce تعتمد عليها.
- صلاحيات `commerce.cart.view` و`commerce.cart.manage` موجودة.

حذفها سيكسر Commerce المستخدم، ولذلك تم الحفاظ عليها.

## Product Templates — لم تُحذف

ثبت أنها مستخدمة وليست orphaned:

- جداول `chat_catalog_product_templates` و`chat_catalog_product_template_fields` موجودة.
- القاعدة الحالية تحتوي 3 قوالب و14 حقلًا.
- يوجد Catalog item واحد مرتبط بقالب.
- `ChatCatalogService` يعتمد على `ProductTemplateService` عند الإنشاء والتعديل والتحقق من structured attributes.
- القوالب مستخدمة في Web routes وMobile routes.
- Web Catalog form يعرض القوالب ويستخدمها.
- `ChatCatalogProductTemplateSeeder` مستدعى من `FoundationSeeder`.
- توجد اختبارات Feature مخصصة للقوالب.

عدم وجود شاشة Flutter مستقلة لا يثبت أن عقد Laravel الحالي مهجورًا، لذلك لم تُحذف الجداول أو العلاقات أو الـ Seeder أو الاختبارات.

## Sellers وMarketplace — لم تُحذف

مصطلح Marketplace موجود داخل Commerce الحالي وليس كـ Storefront مستقل مهجور. الدليل:

- `commerce_sellers` و`commerce_seller_payment_accounts` و`commerce_seller_allocations` مرتبطة بـ Orders وPayments.
- `ChatCatalog` و`ChatCatalogItem` يحتويان علاقة Commerce Seller.
- `CommerceOrderService` و`SellerAllocationService` يستخدمان البائع والتسويات.
- مسارات Catalog sellers وCommerce carts/orders موجودة.
- واجهات Web تعرض البائع والسلال والطلبات والتسويات.
- اختبارات Commerce Seller وCommerce Order تغطي هذا السلوك.

الجداول الحالية للبائعين فارغة، لكن فراغ البيانات لا يعني أن البنية مهجورة. تم الحفاظ عليها بسبب الاعتماد البرمجي والعقد الحالي.

أما قيمة `marketplace` الموجودة في `channel_visibility` وCommerce channel فهي جزء من Commerce الحالي، وليست Storefront مستقلًا يمكن حذفه دون تغيير العقد.

## Drift / Local-First

لم يتم تعديل `InboxDatabase` أو أي migration Drift. النسخة الحالية هي schema version 8، ولا توجد جداول Local-First خاصة بـ Cart أو Storefront أو Marketplace أو Sellers أو Product Templates.

تم التحقق من عدم وجود أسماء هذه الميزات في `apps/hasim/lib/core/local_db`. بقيت جداول Chat وInbox وCatalog وStories وNotifications وWorker وChannels/Settings كما هي.

## ما تم حذفه فعليًا في هذه العملية

لا شيء جديد؛ لأن آثار Storefront المستهدفة كانت محذوفة أصلًا قبل هذه العملية، ولأن العناصر المتبقية ثبت استخدامها.

لا يوجد:

- جدول cleanup جديد.
- Model أو Service أو Controller أو Route محذوف في هذه العملية.
- Flutter local table محذوف.
- Seeder أو Fixture أو Test محذوف.

## ما تم الحفاظ عليه

تم الحفاظ على:

- Chat وInbox وMessages وOutbox.
- Worker وEngagements وCapabilities والتعويضات.
- AI وAI Employees.
- Products وDigital Products وDigital Inventory.
- Channels وLocal-First B–G.
- Commerce Cart وOrders وSeller allocations.
- POS وCashier.
- Invoices وFinance.
- E-Invoicing وZATCA.
- Users وWorkspaces والعلاقات الأساسية.

لم يتم تنفيذ `migrate:fresh` لأنه عملية تدميرية عامة قد تمس بيانات غير مرتبطة بالتنظيف. تم تشغيل migration العادي والتحقق من الحالة بدلًا منه.

## Verification

### Laravel migrations

تم تشغيل:

```text
php artisan migrate --force
```

النتيجة: `Nothing to migrate`.

تم التحقق من:

- جميع migrations الحالية بحالة `Ran`.
- عدم وجود أعمدة Storefront في `chat_catalogs`.
- عدم وجود جداول `websites` و`website_templates`.
- عدم وجود Public Storefront routes.
- وجود Product Template routes.
- وجود Commerce Cart routes.

أمر `php artisan db:table` لم يطبع تفاصيل العرض بسبب غياب PHP `intl` extension، لكن فحص Schema عبر Laravel وقراءة الصفوف عبر قاعدة البيانات نجحا ولم يمنعا التحقق.

### بيانات القاعدة الحالية

- `chat_catalog_product_templates`: عدد 3.
- `chat_catalog_product_template_fields`: عدد 14.
- Catalog items المرتبطة بقالب: عدد 1.
- `commerce_carts`: عدد 1، وحالته `active`.
- `commerce_cart_items`: عدد 0.
- `commerce_sellers`: عدد 0، مع بقاء البنية مستخدمة برمجيًا.
- Storefront columns: غير موجودة.

### Laravel tests

تم تشغيل اختبارات Catalog وCommerce وStorefront المرتبطة:

- **59 اختبارًا**
- **58 ناجحًا**
- **1 skipped**
- **233 assertion**

الاختبار المتخطى هو اختبار concurrency للتحويل من Cart إلى Order، لأنه يحتاج MySQL/MariaDB/PostgreSQL بينما بيئة الاختبارات الحالية SQLite.

### Flutter

- `flutter analyze`: **No issues found**.
- اختبارات Drift وLocal-First وChat وProducts وDigital Products وChannels المركزة: **63 passed**.
- المجموعة الكاملة: **484 passed، 2 failed**.

الفشلان قديمان وغير مرتبطين بالتنظيف:

- `app_config_test.dart`: fallback الإنتاجي `http://127.0.0.1:8001` يفشل شرط HTTPS.
- `inner_chrome_corner_test.dart`: الحجم الفعلي `5×4` بينما المتوقع `4×4`.

## العناصر غير المحسومة التي لم تُحذف

- `commerce_sellers` فارغة حاليًا، لكنها ليست مهجورة لأن Orders وCatalog وCommerce services تعتمد عليها.
- `commerce_workspace_profiles` فارغة حاليًا، لكنها جزء من Commerce operating mode وتظهر في الخدمة والاختبارات.
- Product Templates مستخدمة فعليًا في Laravel، حتى لو لم توجد لها شاشة Flutter مستقلة.
- قيمة Commerce `marketplace` بقيت لأنها جزء من عقد Commerce الحالي، وليست Storefront public.

**الخلاصة:** تم تنفيذ تنظيف محافظ مبني على الاستخدام الفعلي. لم يبقَ جدول أو أثر مستهدف يمكن حذفه بأمان وإثباته من الكود الحالي دون إتلاف مجال مستخدم أو كسر عقد قائم.
