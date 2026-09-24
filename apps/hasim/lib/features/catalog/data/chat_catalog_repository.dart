import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/data/chat_catalog_settings_model.dart';
import 'package:hasim/core/models/models.dart';

class ChatCatalogRepository {
  ChatCatalogRepository(this._api);
  final ApiClient _api;

  Future<({List<ChatCatalogItemModel> items, String? nextCursor})> list({
    String? cursor,
    String? search,
    String? itemKind,
    int? categoryId,
    bool? activeOnly,
    int perPage = 20,
  }) async {
    final res = await _api.get('/catalog', query: {
      'cursor': ?cursor,
      if (search != null && search.isNotEmpty) 'q': search,
      if (itemKind != null && itemKind.isNotEmpty) 'item_kind': itemKind,
      if (categoryId != null) 'category_id': categoryId,
      if (activeOnly != null) 'active_only': activeOnly ? 1 : 0,
      'per_page': perPage,
    });
    return (
      items: asMapList(res.data).map(ChatCatalogItemModel.fromJson).toList(),
      nextCursor: res.nextCursor,
    );
  }

  Future<({List<ChatCatalogCategoryModel> items, String? nextCursor})> listCategories({
    String? cursor,
    int perPage = 50,
  }) async {
    final res = await _api.get('/catalog/categories', query: {
      'cursor': ?cursor,
      'per_page': perPage,
    });
    return (
      items: asMapList(res.data).map(ChatCatalogCategoryModel.fromJson).toList(),
      nextCursor: res.nextCursor,
    );
  }

  Future<List<ChatCatalogCategoryModel>> categories() async {
    return (await listCategories()).items;
  }

  Future<ChatCatalogCategoryModel> createCategory(ChatCatalogCategoryModel draft) async {
    final res = await _api.post(
      '/catalog/categories',
      idempotent: true,
      body: draft.toWriteJson(),
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر إضافة التصنيف.');
    return ChatCatalogCategoryModel.fromJson(map);
  }

  Future<ChatCatalogCategoryModel> updateCategory(int id, ChatCatalogCategoryModel draft) async {
    final res = await _api.patch('/catalog/categories/$id', body: draft.toWriteJson(forUpdate: true));
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحديث التصنيف.');
    return ChatCatalogCategoryModel.fromJson(map);
  }

  Future<void> deleteCategory(int id) async {
    await _api.delete('/catalog/categories/$id');
  }

  Future<ChatCatalogItemModel> show(int id) async {
    final res = await _api.get('/catalog/$id');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'الصنف غير موجود.');
    return ChatCatalogItemModel.fromJson(map);
  }

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
    final res = await _api.post(
      '/catalog',
      idempotent: true,
      body: _itemBody(
        name: name,
        description: description,
        sku: sku,
        itemKind: itemKind,
        tangibility: tangibility,
        price: price,
        currency: currency,
        stock: stock,
        inventoryPolicy: inventoryPolicy,
        isActive: isActive,
        categoryId: categoryId,
        variants: variants,
      ),
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر إضافة الصنف.');
    return ChatCatalogItemModel.fromJson(map);
  }

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
    final res = await _api.patch('/catalog/$id', body: {
      'name': ?name,
      'description': ?description,
      'sku': ?sku,
      'item_kind': ?itemKind,
      'tangibility': ?tangibility,
      'price': ?price,
      'currency': ?currency,
      'stock': ?stock,
      'inventory_policy': ?inventoryPolicy,
      'is_active': ?isActive,
      if (clearCategory) 'chat_catalog_category_id': null,
      if (!clearCategory && categoryId != null) 'chat_catalog_category_id': categoryId,
      if (variants != null) 'variants': variants.map((v) => v.toWriteJson()).toList(),
    });
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحديث الصنف.');
    return ChatCatalogItemModel.fromJson(map);
  }

  Future<ChatCatalogItemModel> updateInventory(
    int id, {
    required String inventoryPolicy,
    int? stock,
  }) async {
    final res = await _api.patch('/catalog/$id/inventory', body: {
      'inventory_policy': inventoryPolicy,
      if (stock != null) 'stock': stock,
    });
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحديث المخزون.');
    return ChatCatalogItemModel.fromJson(map);
  }

  Future<void> setActive(int id, {required bool active}) async {
    await _api.post(active ? '/catalog/$id/activate' : '/catalog/$id/deactivate');
  }

  Future<void> delete(int id) async {
    await _api.delete('/catalog/$id');
  }

  Future<ChatCatalogSettingsModel> fetchSettings() async {
    final res = await _api.get('/catalog/settings');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحميل إعدادات الكتالوج.');
    return ChatCatalogSettingsModel.fromJson(map);
  }

  Future<ChatCatalogSettingsModel> updateSettings(Map<String, dynamic> body) async {
    final res = await _api.patch('/catalog/settings', body: body);
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر حفظ إعدادات الكتالوج.');
    return ChatCatalogSettingsModel.fromJson(map);
  }

  Future<ChatCatalogSettingsModel> updateCommunication(Map<String, dynamic> body) async {
    final res = await _api.patch('/catalog/settings/communication', body: body);
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر حفظ إعدادات التواصل.');
    return ChatCatalogSettingsModel.fromJson(map);
  }

  Future<ChatCatalogSettingsModel> transitionSettings(String action) async {
    final path = switch (action) {
      'publish' => '/catalog/settings/publish',
      'draft' => '/catalog/settings/draft',
      'pause' => '/catalog/settings/pause',
      'archive' => '/catalog/settings/archive',
      _ => throw ArgumentError('Unknown catalog transition: $action'),
    };
    final res = await _api.post(path);
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحديث حالة الكتالوج.');
    return ChatCatalogSettingsModel.fromJson(map);
  }

  Map<String, dynamic> _itemBody({
    required String name,
    String? description,
    String? sku,
    required String itemKind,
    required String tangibility,
    required num price,
    required String currency,
    int? stock,
    String? inventoryPolicy,
    required bool isActive,
    int? categoryId,
    List<ChatCatalogVariantModel>? variants,
  }) {
    return {
      'name': name,
      if (description != null && description.isNotEmpty) 'description': description,
      if (sku != null && sku.isNotEmpty) 'sku': sku,
      'item_kind': itemKind,
      'tangibility': tangibility,
      'price': price,
      'currency': currency,
      if (stock != null) 'stock': stock,
      if (inventoryPolicy != null && inventoryPolicy.isNotEmpty) 'inventory_policy': inventoryPolicy,
      'is_active': isActive,
      if (categoryId != null) 'chat_catalog_category_id': categoryId,
      if (variants != null && variants.isNotEmpty)
        'variants': variants.map((v) => v.toWriteJson()).toList(),
    };
  }
}
