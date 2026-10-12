import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/data/chat_catalog_repository.dart';
import 'package:hasim/features/catalog/presentation/catalog_form_screen.dart';
import 'package:hasim/features/catalog/presentation/catalog_product_type_selection_screen.dart';
import 'package:hasim/features/catalog/presentation/chat_catalog_settings_screen.dart';
import 'package:hasim/features/catalog/providers/chat_catalog_categories_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _MemorySecureStore extends SecureStore {
  String? _token;
  @override
  Future<void> saveToken(String token) async => _token = token;
  @override
  Future<String?> readToken() async => _token;
  @override
  Future<void> clearToken() async => _token = null;
}

class _StubCategoriesController extends ChatCatalogCategoriesController {
  _StubCategoriesController(super.ref);

  @override
  Future<void> reload() async {
    state = const ChatCatalogCategoriesState();
  }
}

class _FakeCatalogRepository extends ChatCatalogRepository {
  _FakeCatalogRepository(super.api);

  bool created = false;
  bool updated = false;
  int? deletedId;
  int updateCount = 0;
  int _nextVariantId = 100;
  String? lastName;
  num? lastPrice;
  num? lastWorkerMinimumPrice;
  String? lastCurrency;
  String? lastKind;
  String? lastTangibility;
  bool? lastActive;
  List<ChatCatalogVariantModel>? lastVariants;
  final List<List<ChatCatalogVariantModel>?> updateVariantPayloads = [];

  List<ChatCatalogVariantModel> _persistVariants(
    List<ChatCatalogVariantModel>? variants,
  ) {
    return [
      for (final variant in variants ?? const <ChatCatalogVariantModel>[])
        variant.isNew ? variant.copyWith(id: _nextVariantId++) : variant,
    ];
  }

  @override
  Future<({List<ChatCatalogCategoryModel> items, String? nextCursor})>
  listCategories({String? cursor, int perPage = 50}) async {
    return (items: const <ChatCatalogCategoryModel>[], nextCursor: null);
  }

  @override
  Future<ChatCatalogItemModel> create({
    required String name,
    String? description,
    String? sku,
    String itemKind = 'product',
    String tangibility = 'tangible',
    required num price,
    num? workerMinimumPrice,
    String currency = 'SAR',
    int? stock,
    String? inventoryPolicy,
    bool isActive = true,
    int? categoryId,
    List<ChatCatalogVariantModel>? variants,
  }) async {
    created = true;
    lastName = name;
    lastPrice = price;
    lastWorkerMinimumPrice = workerMinimumPrice;
    lastCurrency = currency;
    lastKind = itemKind;
    lastTangibility = tangibility;
    lastActive = isActive;
    lastVariants = variants;
    return ChatCatalogItemModel(
      id: 11,
      name: name,
      description: description,
      sku: sku,
      itemKind: itemKind,
      tangibility: tangibility,
      price: price,
      currency: currency,
      isActive: isActive,
      variants: _persistVariants(variants),
    );
  }

  @override
  Future<ChatCatalogItemModel> update(
    int id, {
    String? name,
    String? description,
    String? sku,
    String? itemKind,
    String? tangibility,
    num? price,
    num? workerMinimumPrice,
    String? currency,
    int? stock,
    String? inventoryPolicy,
    bool? isActive,
    int? categoryId,
    bool clearCategory = false,
    List<ChatCatalogVariantModel>? variants,
  }) async {
    updated = true;
    updateCount++;
    lastName = name;
    lastPrice = price;
    lastWorkerMinimumPrice = workerMinimumPrice;
    lastCurrency = currency;
    lastKind = itemKind;
    lastTangibility = tangibility;
    lastActive = isActive;
    lastVariants = variants;
    updateVariantPayloads.add(variants);
    return ChatCatalogItemModel(
      id: id,
      name: name ?? 'صنف',
      description: description,
      sku: sku,
      itemKind: itemKind ?? 'product',
      tangibility: tangibility ?? 'tangible',
      price: price ?? 0,
      currency: currency ?? 'SAR',
      isActive: isActive ?? true,
      variants: _persistVariants(variants),
    );
  }

  @override
  Future<void> delete(int id) async {
    deletedId = id;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  late SharedPreferences prefs;
  late ApiClient api;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    api = isolatedTestApiClient(
      secureStore: _MemorySecureStore(),
      prefsStore: PrefsStore(prefs),
    );
  });

  Widget app({
    required _FakeCatalogRepository repo,
    ChatCatalogItemModel? item,
    bool digital = false,
    bool embedded = false,
  }) {
    final router = GoRouter(
      initialLocation: '/form',
      routes: [
        GoRoute(
          path: '/',
          builder: (context, state) => const Scaffold(body: Text('القائمة')),
          routes: [
            GoRoute(
              path: 'form',
              builder: (context, state) => CatalogFormScreen(
                item: item,
                digital: digital,
                embedded: embedded,
              ),
            ),
          ],
        ),
      ],
    );

    return ProviderScope(
      overrides: [
        sharedPrefsProvider.overrideWithValue(prefs),
        secureStoreProvider.overrideWithValue(_MemorySecureStore()),
        apiClientProvider.overrideWithValue(api),
        chatCatalogRepositoryProvider.overrideWithValue(repo),
        chatCatalogCategoriesControllerProvider.overrideWith(
          (ref) => _StubCategoriesController(ref),
        ),
        realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
      ],
      child: MaterialApp.router(
        theme: AppTheme.light(),
        locale: const Locale('ar'),
        localizationsDelegates: AppLocalizations.localizationsDelegates,
        supportedLocales: AppLocalizations.supportedLocales,
        builder: (context, child) => Directionality(
          textDirection: TextDirection.rtl,
          child: child ?? const SizedBox.shrink(),
        ),
        routerConfig: router,
      ),
    );
  }

  Future<void> pumpForm(
    WidgetTester tester, {
    required _FakeCatalogRepository repo,
    ChatCatalogItemModel? item,
    bool digital = false,
    bool embedded = false,
    Size size = const Size(390, 1400),
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(
      app(repo: repo, item: item, digital: digital, embedded: embedded),
    );
    await tester.pump();
    await tester.pumpAndSettle();
  }

  testWidgets('add item chrome matches Hasim catalog cards', (tester) async {
    final repo = _FakeCatalogRepository(api);
    await pumpForm(tester, repo: repo);

    expect(find.text('إضافة منتج'), findsOneWidget);
    expect(find.text('الاسم'), findsOneWidget);
    expect(find.text('أدخل اسم الصنف...'), findsOneWidget);
    expect(find.text('الوصف'), findsOneWidget);
    expect(find.text('أدخل وصف الصنف...'), findsOneWidget);
    expect(find.text('المرجع / SKU'), findsOneWidget);
    expect(find.text('أدخل المرجع أو رمز الصنف...'), findsOneWidget);
    expect(find.text('نوع المنتج: منتج عادي'), findsOneWidget);
    expect(find.text('النوع'), findsNothing);
    expect(find.text('الطبيعة'), findsNothing);
    expect(find.text('السعر'), findsOneWidget);
    expect(find.text('التصنيف'), findsOneWidget);
    expect(find.text('طريقة التوفر'), findsOneWidget);
    expect(find.text('SAR'), findsWidgets);
    expect(find.byKey(const Key('catalog-save')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('keeps existing name and price validation', (tester) async {
    final repo = _FakeCatalogRepository(api);
    await pumpForm(tester, repo: repo);

    await tester.tap(find.byKey(const Key('catalog-save')));
    await tester.pump();
    expect(repo.created, isFalse);
    expect(find.text('الاسم مطلوب'), findsNothing);

    await tester.enterText(find.byKey(const Key('catalog-name')), 'قميص');
    await tester.pump();
    await tester.drag(find.byType(ListView), const Offset(0, -420));
    await tester.pumpAndSettle();
    expect(find.text('3/3'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('catalog-name')), '   ');
    await tester.pump();
    expect(find.text('الاسم مطلوب'), findsOneWidget);
    expect(find.text('2/3'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('catalog-name')), 'قميص');
    await tester.enterText(find.byKey(const Key('catalog-price')), 'abc');
    await tester.pump();
    expect(find.text('أدخل سعراً صحيحاً'), findsOneWidget);
    expect(find.text('2/3'), findsOneWidget);
    expect(repo.created, isFalse);
  });

  testWidgets('save stays wired to current catalog repository create', (
    tester,
  ) async {
    final repo = _FakeCatalogRepository(api);
    await pumpForm(tester, repo: repo);

    await tester.enterText(find.byKey(const Key('catalog-name')), 'قميص صيفي');
    await tester.enterText(
      find.byKey(const Key('catalog-description')),
      'قطن خفيف',
    );
    await tester.enterText(find.byKey(const Key('catalog-sku')), 'SKU-9');
    await tester.enterText(find.byKey(const Key('catalog-price')), '12.5');
    await tester.pump();

    await tester.ensureVisible(find.byKey(const Key('catalog-active')));
    await tester.tap(find.byKey(const Key('catalog-active')));
    await tester.pump();

    await tester.ensureVisible(find.byKey(const Key('catalog-save')));
    await tester.tap(find.byKey(const Key('catalog-save')));
    await tester.pump();
    await tester.pumpAndSettle();

    expect(repo.created, isTrue);
    expect(repo.updated, isFalse);
    expect(repo.lastName, 'قميص صيفي');
    expect(repo.lastPrice, 12.5);
    expect(repo.lastCurrency, 'SAR');
    expect(repo.lastKind, 'product');
    expect(repo.lastTangibility, 'tangible');
    expect(repo.lastActive, isFalse);
    expect(find.text('القائمة'), findsOneWidget);
  });

  testWidgets('edit mode keeps update path and current values', (tester) async {
    final repo = _FakeCatalogRepository(api);
    await pumpForm(
      tester,
      repo: repo,
      item: const ChatCatalogItemModel(
        id: 44,
        name: 'طاولة',
        description: 'خشب',
        sku: 'T-1',
        itemKind: 'product',
        tangibility: 'tangible',
        price: 80,
        currency: 'AED',
        isActive: true,
      ),
    );

    expect(find.text('تعديل منتج'), findsOneWidget);
    expect(find.text('طاولة'), findsOneWidget);
    expect(find.text('خشب'), findsOneWidget);
    expect(find.text('T-1'), findsOneWidget);
    await tester.drag(find.byType(ListView), const Offset(0, -420));
    await tester.pumpAndSettle();
    expect(find.text('AED'), findsOneWidget);
    expect(find.text('3/3'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('catalog-name')), 'طاولة مكتب');
    await tester.pump();
    await tester.tap(find.byKey(const Key('catalog-save')));
    await tester.pumpAndSettle();

    expect(repo.updated, isTrue);
    expect(repo.created, isFalse);
    expect(repo.lastName, 'طاولة مكتب');
    expect(repo.lastPrice, 80);
    expect(repo.lastCurrency, 'AED');
  });

  testWidgets('narrow widths do not overflow the add item form', (
    tester,
  ) async {
    final repo = _FakeCatalogRepository(api);
    for (final width in [320.0, 360.0, 390.0, 430.0, 600.0, 768.0, 1024.0]) {
      await pumpForm(tester, repo: repo, size: Size(width, 720));
      expect(find.text('إضافة منتج'), findsOneWidget);
      expect(find.text('حفظ المنتج'), findsOneWidget);
      expect(tester.takeException(), isNull);
    }
  });

  testWidgets('digital add uses the digital product title', (tester) async {
    final repo = _FakeCatalogRepository(api);
    await pumpForm(tester, repo: repo, digital: true);
    expect(find.text('إضافة منتج رقمي'), findsOneWidget);
    expect(find.text('إضافة منتج'), findsNothing);
  });

  testWidgets('digital form keeps the type fixed and hides physical controls', (
    tester,
  ) async {
    final repo = _FakeCatalogRepository(api);
    await pumpForm(tester, repo: repo, digital: true);

    expect(find.text('نوع المنتج: منتج رقمي'), findsOneWidget);
    expect(find.byKey(const Key('catalog-stock')), findsNothing);
    expect(find.byKey(const Key('catalog-worker-min-price')), findsNothing);
    expect(find.byKey(const Key('catalog-variant-add')), findsNothing);
    expect(find.text('النوع'), findsNothing);
    expect(find.text('الطبيعة'), findsNothing);
  });

  testWidgets(
    'product type selector offers separate normal and digital cards',
    (tester) async {
      bool? selectedDigital;
      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          home: CatalogProductTypeSelectionScreen(
            onSelected: (digital) => selectedDigital = digital,
          ),
        ),
      );

      expect(find.text('اختر نوع المنتج'), findsNWidgets(2));
      expect(find.text('منتج عادي'), findsOneWidget);
      expect(find.text('منتج رقمي'), findsOneWidget);
      expect(
        find.byKey(const Key('catalog-product-type-normal')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('catalog-product-type-digital')),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('catalog-product-type-normal')));
      expect(selectedDigital, isFalse);
      await tester.tap(find.byKey(const Key('catalog-product-type-digital')));
      expect(selectedDigital, isTrue);
    },
  );

  testWidgets('mobile catalog flow hides the selected form name', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(prefs),
          secureStoreProvider.overrideWithValue(_MemorySecureStore()),
          apiClientProvider.overrideWithValue(api),
          chatCatalogCategoriesControllerProvider.overrideWith(
            (ref) => _StubCategoriesController(ref),
          ),
        ],
        child: const MaterialApp(
          locale: Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: ChatCatalogSettingsScreen(),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('اختر نوع المنتج'), findsOneWidget);
    expect(find.text('نوع المنتج: منتج عادي'), findsNothing);

    await tester.tap(find.byKey(const Key('catalog-product-type-normal')));
    await tester.pumpAndSettle();

    expect(find.text('إضافة منتج'), findsNothing);
    expect(find.text('إضافة تصنيف'), findsNothing);
    expect(find.text('نوع المنتج: منتج عادي'), findsNothing);
    expect(find.text('صورة المنتج'), findsOneWidget);
    expect(find.text('النوع'), findsNothing);
    expect(find.text('الطبيعة'), findsNothing);

    await tester.tap(find.byIcon(Icons.arrow_forward_rounded));
    await tester.pumpAndSettle();
    expect(find.text('إضافة منتج'), findsOneWidget);
    expect(find.text('إضافة تصنيف'), findsOneWidget);
  });

  testWidgets('normal form keeps the product type fixed', (tester) async {
    final repo = _FakeCatalogRepository(api);
    await pumpForm(tester, repo: repo);

    expect(find.text('نوع المنتج: منتج عادي'), findsOneWidget);
    expect(find.text('النوع'), findsNothing);
    expect(find.text('الطبيعة'), findsNothing);
  });

  testWidgets('edit saves worker prices and deleted variants', (tester) async {
    final repo = _FakeCatalogRepository(api);
    await pumpForm(
      tester,
      repo: repo,
      item: const ChatCatalogItemModel(
        id: 44,
        name: 'طاولة',
        description: 'خشب',
        sku: 'T-1',
        itemKind: 'product',
        tangibility: 'tangible',
        price: 80,
        currency: 'SAR',
        isActive: true,
        variants: [ChatCatalogVariantModel(id: 9, name: 'كبير', price: 80)],
      ),
    );

    await tester.ensureVisible(
      find.byKey(const Key('catalog-worker-min-price')),
    );
    await tester.enterText(
      find.byKey(const Key('catalog-worker-min-price')),
      '22',
    );
    await tester.pump();
    expect(find.byKey(const Key('catalog-worker-max-price')), findsNothing);
    expect(find.text('الحد الأعلى للعامل'), findsNothing);

    await tester.ensureVisible(
      find.byKey(const Key('catalog-variant-remove-0')),
    );
    await tester.tap(find.byKey(const Key('catalog-variant-remove-0')));
    await tester.pump();
    expect(find.text('كبير'), findsNothing);
    expect(find.text('لا توجد نسخ لهذا المنتج'), findsOneWidget);

    await tester.tap(find.byKey(const Key('catalog-save')));
    await tester.pumpAndSettle();

    expect(repo.updated, isTrue);
    expect(repo.lastWorkerMinimumPrice, 22);
    expect(repo.lastVariants, isEmpty);
    expect(repo.lastKind, 'product');
    expect(repo.lastTangibility, 'tangible');
  });

  testWidgets(
    'variants editor empty state and card stay within narrow widths',
    (tester) async {
      final repo = _FakeCatalogRepository(api);
      await pumpForm(tester, repo: repo, size: const Size(360, 1400));
      await tester.ensureVisible(find.text('لا توجد نسخ لهذا المنتج'));
      expect(find.text('النسخ والخيارات'), findsOneWidget);
      expect(find.byKey(const Key('catalog-variant-add')), findsOneWidget);
      expect(tester.takeException(), isNull);

      await tester.tap(find.byKey(const Key('catalog-variant-add')));
      await tester.pump();
      expect(find.text('الهوية'), findsOneWidget);
      expect(find.text('المخزون'), findsWidgets);
      expect(find.text('الحالة'), findsOneWidget);
      expect(find.byKey(const Key('catalog-variant-remove-0')), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('second save keeps server variant ids', (tester) async {
    final repo = _FakeCatalogRepository(api);
    await pumpForm(
      tester,
      repo: repo,
      embedded: true,
      size: const Size(390, 1800),
    );

    await tester.enterText(find.byKey(const Key('catalog-name')), 'قميص');
    await tester.enterText(find.byKey(const Key('catalog-price')), '40');
    await tester.pump();

    await tester.ensureVisible(find.byKey(const Key('catalog-variant-add')));
    await tester.tap(find.byKey(const Key('catalog-variant-add')));
    await tester.pump();

    final variantName = find.byKey(const Key('catalog-variant-name-0'));
    expect(variantName, findsOneWidget);
    await tester.enterText(variantName, 'كبير');
    await tester.pump();

    await tester.ensureVisible(find.byKey(const Key('catalog-save')));
    await tester.tap(find.byKey(const Key('catalog-save')));
    await tester.pumpAndSettle();

    expect(repo.created, isTrue);
    expect(repo.updated, isFalse);
    expect(repo.lastVariants, isNotNull);
    expect(repo.lastVariants, hasLength(1));
    expect(repo.lastVariants!.single.isNew, isTrue);
    expect(repo.lastVariants!.single.name, 'كبير');

    await tester.pump(const Duration(seconds: 4));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('catalog-name')), 'قميص صيفي');
    await tester.pump();
    await tester.ensureVisible(find.byKey(const Key('catalog-save')));
    await tester.tap(find.byKey(const Key('catalog-save')));
    await tester.pumpAndSettle();

    expect(repo.updated, isTrue);
    expect(repo.updateCount, 1);
    expect(repo.lastName, 'قميص صيفي');
    expect(repo.lastVariants, hasLength(1));
    expect(repo.lastVariants!.single.id, 100);
    expect(repo.lastVariants!.single.name, 'كبير');
    expect(repo.lastVariants!.single.isNew, isFalse);
  });

  testWidgets('edit mode delete asks for confirmation before removing', (
    tester,
  ) async {
    final repo = _FakeCatalogRepository(api);
    await pumpForm(
      tester,
      repo: repo,
      item: const ChatCatalogItemModel(
        id: 44,
        name: 'طاولة',
        price: 80,
        currency: 'SAR',
      ),
    );

    expect(find.byKey(const Key('catalog-delete')), findsOneWidget);
    await tester.ensureVisible(find.byKey(const Key('catalog-delete')));
    await tester.tap(find.byKey(const Key('catalog-delete')));
    await tester.pumpAndSettle();
    expect(find.text('حذف المنتج'), findsWidgets);
    expect(find.byKey(const Key('catalog-delete-cancel')), findsOneWidget);

    await tester.tap(find.byKey(const Key('catalog-delete-cancel')));
    await tester.pumpAndSettle();
    expect(repo.deletedId, isNull);
    expect(find.text('تعديل منتج'), findsOneWidget);

    await tester.tap(find.byKey(const Key('catalog-delete')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('catalog-delete-confirm')));
    await tester.pumpAndSettle();

    expect(repo.deletedId, 44);
    expect(find.text('القائمة'), findsOneWidget);
  });

  testWidgets('create mode does not show product delete', (tester) async {
    final repo = _FakeCatalogRepository(api);
    await pumpForm(tester, repo: repo);
    expect(find.byKey(const Key('catalog-delete')), findsNothing);
  });
}
