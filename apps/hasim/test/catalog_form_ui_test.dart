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
  String? lastName;
  num? lastPrice;
  String? lastCurrency;
  String? lastKind;
  String? lastTangibility;
  bool? lastActive;

  @override
  Future<({List<ChatCatalogCategoryModel> items, String? nextCursor})> listCategories({
    String? cursor,
    int perPage = 50,
  }) async {
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
    lastCurrency = currency;
    lastKind = itemKind;
    lastTangibility = tangibility;
    lastActive = isActive;
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
    String? currency,
    int? stock,
    String? inventoryPolicy,
    bool? isActive,
    int? categoryId,
    bool clearCategory = false,
    List<ChatCatalogVariantModel>? variants,
  }) async {
    updated = true;
    lastName = name;
    lastPrice = price;
    lastCurrency = currency;
    lastKind = itemKind;
    lastTangibility = tangibility;
    lastActive = isActive;
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
    );
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
              builder: (context, state) => CatalogFormScreen(item: item),
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
        chatCatalogCategoriesControllerProvider.overrideWith((ref) => _StubCategoriesController(ref)),
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
    Size size = const Size(390, 1400),
  }) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(app(repo: repo, item: item));
    await tester.pump();
    await tester.pumpAndSettle();
  }

  testWidgets('add item chrome matches Hasim catalog cards', (tester) async {
    final repo = _FakeCatalogRepository(api);
    await pumpForm(tester, repo: repo);

    expect(find.text('إضافة صنف'), findsOneWidget);
    expect(find.text('الاسم'), findsOneWidget);
    expect(find.text('أدخل اسم الصنف...'), findsOneWidget);
    expect(find.text('الوصف'), findsOneWidget);
    expect(find.text('أدخل وصف الصنف...'), findsOneWidget);
    expect(find.text('المرجع / SKU'), findsOneWidget);
    expect(find.text('أدخل المرجع أو رمز الصنف...'), findsOneWidget);
    expect(find.text('النوع'), findsOneWidget);
    expect(find.text('منتج'), findsOneWidget);
    expect(find.text('الطبيعة'), findsOneWidget);
    expect(find.text('ملموس'), findsOneWidget);
    expect(find.text('السعر'), findsOneWidget);
    expect(find.text('التصنيف'), findsOneWidget);
    expect(find.text('سياسة المخزون'), findsOneWidget);
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

  testWidgets('save stays wired to current catalog repository create', (tester) async {
    final repo = _FakeCatalogRepository(api);
    await pumpForm(tester, repo: repo);

    await tester.enterText(find.byKey(const Key('catalog-name')), 'قميص صيفي');
    await tester.enterText(find.byKey(const Key('catalog-description')), 'قطن خفيف');
    await tester.enterText(find.byKey(const Key('catalog-sku')), 'SKU-9');
    await tester.enterText(find.byKey(const Key('catalog-price')), '12.5');
    await tester.pump();

    await tester.ensureVisible(find.text('منتج'));
    await tester.tap(find.text('منتج'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('خدمة').last);
    await tester.pumpAndSettle();

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
    expect(repo.lastKind, 'service');
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

    expect(find.text('تعديل صنف'), findsOneWidget);
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

  testWidgets('narrow widths do not overflow the add item form', (tester) async {
    final repo = _FakeCatalogRepository(api);
    for (final width in [360.0, 375.0, 390.0, 414.0]) {
      await pumpForm(tester, repo: repo, size: Size(width, 720));
      expect(find.text('إضافة صنف'), findsOneWidget);
      expect(find.text('حفظ'), findsOneWidget);
      expect(tester.takeException(), isNull);
    }
  });
}
