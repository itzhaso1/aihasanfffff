import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/data/chat_catalog_repository.dart';
import 'package:hasim/features/catalog/providers/chat_catalog_categories_controller.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _MemorySecureStore extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}
  @override
  Future<String?> readToken() async => null;
  @override
  Future<void> clearToken() async {}
}

class _FakeCatalogRepo extends ChatCatalogRepository {
  _FakeCatalogRepo(super.api);

  List<ChatCatalogCategoryModel> nextPage = const [
    ChatCatalogCategoryModel(id: 1, name: 'Workspace A'),
  ];

  @override
  Future<({List<ChatCatalogCategoryModel> items, String? nextCursor})> listCategories({
    String? cursor,
    int perPage = 50,
  }) async {
    return (items: nextPage, nextCursor: null);
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('categories controller reload replaces items after workspace data changes', () async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    final api = isolatedTestApiClient(
      secureStore: _MemorySecureStore(),
      prefsStore: PrefsStore(prefs),
    );
    final fake = _FakeCatalogRepo(api);

    final container = ProviderContainer(
      overrides: [
        sharedPrefsProvider.overrideWithValue(prefs),
        secureStoreProvider.overrideWithValue(_MemorySecureStore()),
        apiClientProvider.overrideWithValue(api),
        chatCatalogRepositoryProvider.overrideWithValue(fake),
      ],
    );
    addTearDown(container.dispose);

    container.read(chatCatalogCategoriesControllerProvider);
    await Future<void>.delayed(const Duration(milliseconds: 30));
    expect(container.read(chatCatalogCategoriesControllerProvider).items.single.name, 'Workspace A');

    fake.nextPage = const [ChatCatalogCategoryModel(id: 2, name: 'Workspace B')];
    await container.read(chatCatalogCategoriesControllerProvider.notifier).reload();
    expect(container.read(chatCatalogCategoriesControllerProvider).items.single.name, 'Workspace B');
  });
}
