import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/data/chat_catalog_repository.dart';
import 'package:hasim/features/catalog/presentation/catalog_categories_screen.dart';
import 'package:hasim/features/catalog/presentation/catalog_list_screen.dart';
import 'package:hasim/l10n/app_localizations.dart';
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

class _QuietAuth extends AuthController {
  _QuietAuth(super.ref);

  @override
  Future<void> bootstrap() async {}
}

class _FakeCatalogRepository extends ChatCatalogRepository {
  _FakeCatalogRepository(super.api, {List<ChatCatalogItemModel>? items})
    : items = [...?items];

  List<ChatCatalogItemModel> items;
  final List<ChatCatalogCategoryModel> storedCategories = [];
  bool createdProduct = false;
  bool createdCategory = false;

  @override
  Future<List<ChatCatalogItemModel>> cachedItems({
    String? search,
    String? itemKind,
  }) async {
    return const [];
  }

  @override
  Future<({List<ChatCatalogItemModel> items, String? nextCursor})> list({
    String? cursor,
    String? search,
    String? itemKind,
    int? categoryId,
    bool? activeOnly,
    int perPage = 20,
  }) async {
    return (items: items, nextCursor: null);
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
    createdProduct = true;
    final saved = ChatCatalogItemModel(
      id: 21,
      name: name,
      description: description,
      sku: sku,
      itemKind: itemKind,
      tangibility: tangibility,
      price: price,
      currency: currency,
      isActive: isActive,
    );
    items = [saved];
    return saved;
  }

  @override
  Future<({List<ChatCatalogCategoryModel> items, String? nextCursor})>
  listCategories({String? cursor, int perPage = 50}) async {
    return (items: storedCategories, nextCursor: null);
  }

  @override
  Future<ChatCatalogCategoryModel> createCategory(
    ChatCatalogCategoryModel draft,
  ) async {
    createdCategory = true;
    final saved = ChatCatalogCategoryModel(id: 7, name: draft.name);
    storedCategories
      ..clear()
      ..add(saved);
    return saved;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

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

  Future<void> pumpCatalog(
    WidgetTester tester,
    _FakeCatalogRepository repo,
  ) async {
    tester.view.physicalSize = const Size(900, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final router = GoRouter(
      initialLocation: '/catalog',
      routes: [
        GoRoute(
          path: '/catalog',
          builder: (context, state) => const CatalogListScreen(),
          routes: [
            GoRoute(
              path: 'categories',
              builder: (context, state) => const CatalogCategoriesScreen(),
            ),
          ],
        ),
      ],
    );

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(prefs),
          apiClientProvider.overrideWithValue(api),
          chatCatalogRepositoryProvider.overrideWithValue(repo),
          authControllerProvider.overrideWith(_QuietAuth.new),
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
      ),
    );
    await tester.pump();
    await tester.pumpAndSettle();
  }

  testWidgets('empty catalog opens the existing add-product form', (
    tester,
  ) async {
    final repo = _FakeCatalogRepository(api);
    await pumpCatalog(tester, repo);

    expect(find.byKey(const Key('catalog-add-product')), findsOneWidget);
    expect(find.byKey(const Key('catalog-open-categories')), findsOneWidget);
    expect(find.text('لا توجد منتجات'), findsOneWidget);
    final addButtons = find.widgetWithText(FilledButton, 'إضافة منتج');
    expect(addButtons, findsNWidgets(2));

    await tester.tap(addButtons.last);
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('catalog-save')), findsOneWidget);
    expect(find.text('تعديل منتج'), findsNothing);

    await tester.enterText(find.byKey(const Key('catalog-name')), 'دفتر');
    await tester.enterText(find.byKey(const Key('catalog-price')), '15');
    await tester.ensureVisible(find.byKey(const Key('catalog-save')));
    await tester.tap(find.byKey(const Key('catalog-save')));
    await tester.pumpAndSettle();

    expect(repo.createdProduct, isTrue);
    expect(find.text('تم حفظ المنتج'), findsOneWidget);
  });

  testWidgets('existing product still opens edit, and categories can be added', (
    tester,
  ) async {
    final repo = _FakeCatalogRepository(
      api,
      items: const [
        ChatCatalogItemModel(id: 4, name: 'قلم', price: 3, currency: 'SAR'),
      ],
    );
    await pumpCatalog(tester, repo);

    expect(find.text('لا توجد منتجات'), findsNothing);
    await tester.tap(find.text('قلم'));
    await tester.pumpAndSettle();
    expect(find.text('تعديل منتج'), findsOneWidget);

    await tester.tap(find.text('إغلاق'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('catalog-open-categories')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('catalog-category-name')), findsOneWidget);
    await tester.enterText(
      find.byKey(const Key('catalog-category-name')),
      'قرطاسية',
    );
    await tester.ensureVisible(find.byKey(const Key('catalog-category-save')));
    await tester.tap(find.byKey(const Key('catalog-category-save')));
    await tester.pumpAndSettle();

    expect(repo.createdCategory, isTrue);
    expect(find.text('تم حفظ التصنيف'), findsOneWidget);
  });
}
