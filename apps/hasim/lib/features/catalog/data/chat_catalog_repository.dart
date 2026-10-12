import 'package:dio/dio.dart';
import 'package:hasim/core/local_db/domain_local_store.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/data/chat_catalog_settings_model.dart';
import 'package:hasim/core/models/models.dart';

class ChatCatalogRepository {
  ChatCatalogRepository(
    this._api, {
    DomainLocalStore? local,
    int? Function()? workspaceIdOf,
  }) : _local = local,
       _workspaceIdOf = workspaceIdOf;

  final ApiClient _api;
  final DomainLocalStore? _local;
  final int? Function()? _workspaceIdOf;
  bool lastReadFromCache = false;

  int? get _workspace => _workspaceIdOf?.call();

  Future<List<ChatCatalogItemModel>> cachedItems({
    String? search,
    String? itemKind,
  }) async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const [];
    return _local.products(workspaceId, search: search, itemKind: itemKind);
  }

  Stream<List<ChatCatalogItemModel>> watchItems({
    String? search,
    String? itemKind,
  }) {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const Stream.empty();
    return _local.watchProducts(
      workspaceId,
      search: search,
      itemKind: itemKind,
    );
  }

  Future<void> _rememberProducts(List<ChatCatalogItemModel> items) async {
    final workspaceId = _workspace;
    if (workspaceId == null || items.isEmpty) return;
    await _local?.upsertProducts(workspaceId, items);
  }

  Future<({List<ChatCatalogItemModel> items, String? nextCursor})> list({
    String? cursor,
    String? search,
    String? itemKind,
    int? categoryId,
    bool? activeOnly,
    int perPage = 20,
  }) async {
    final workspaceId = _workspace;
    try {
      final res = await _api.get(
        '/catalog',
        query: {
          'cursor': ?cursor,
          if (search != null && search.isNotEmpty) 'q': search,
          if (itemKind == 'digital') 'classification': 'digital',
          if (itemKind != null && itemKind.isNotEmpty && itemKind != 'digital')
            'item_kind': itemKind,
          'category_id': ?categoryId,
          if (activeOnly != null) 'active_only': activeOnly ? 1 : 0,
          'per_page': perPage,
        },
      );
      final items = asMapList(
        res.data,
      ).map(ChatCatalogItemModel.fromJson).toList();
      lastReadFromCache = false;
      await _rememberProducts(items);
      return (items: items, nextCursor: res.nextCursor);
    } catch (_) {
      if (workspaceId != null && (cursor == null || cursor.isEmpty)) {
        final cached = await cachedItems(search: search, itemKind: itemKind);
        if (cached.isNotEmpty) {
          lastReadFromCache = true;
          return (items: cached, nextCursor: null);
        }
      }
      rethrow;
    }
  }

  Future<({List<ChatCatalogCategoryModel> items, String? nextCursor})>
  listCategories({String? cursor, int perPage = 50}) async {
    final res = await _api.get(
      '/catalog/categories',
      query: {'cursor': ?cursor, 'per_page': perPage},
    );
    final items = asMapList(
      res.data,
    ).map(ChatCatalogCategoryModel.fromJson).toList();
    final workspaceId = _workspace;
    if (workspaceId != null) await _local?.upsertCategories(workspaceId, items);
    return (items: items, nextCursor: res.nextCursor);
  }

  Future<List<ChatCatalogCategoryModel>> cachedCategories() async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const [];
    return _local.categories(workspaceId);
  }

  Future<List<ChatCatalogCategoryModel>> categories() async {
    return (await listCategories()).items;
  }

  Future<ChatCatalogCategoryModel> createCategory(
    ChatCatalogCategoryModel draft,
  ) async {
    final res = await _api.post(
      '/catalog/categories',
      idempotent: true,
      body: draft.toWriteJson(),
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر إضافة التصنيف.');
    final category = ChatCatalogCategoryModel.fromJson(map);
    final workspaceId = _workspace;
    if (workspaceId != null) {
      await _local?.upsertCategories(workspaceId, [category]);
    }
    return category;
  }

  Future<ChatCatalogCategoryModel> updateCategory(
    int id,
    ChatCatalogCategoryModel draft,
  ) async {
    final res = await _api.patch(
      '/catalog/categories/$id',
      body: draft.toWriteJson(forUpdate: true),
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحديث التصنيف.');
    final category = ChatCatalogCategoryModel.fromJson(map);
    final workspaceId = _workspace;
    if (workspaceId != null) {
      await _local?.upsertCategories(workspaceId, [category]);
    }
    return category;
  }

  Future<void> deleteCategory(int id) async {
    await _api.delete('/catalog/categories/$id');
    final workspaceId = _workspace;
    if (workspaceId != null) await _local?.deleteCategory(workspaceId, id);
  }

  Future<ChatCatalogItemModel> show(int id) async {
    try {
      final res = await _api.get('/catalog/$id');
      final map = asMap(res.data);
      if (map == null) throw ApiException(res.message ?? 'الصنف غير موجود.');
      final item = ChatCatalogItemModel.fromJson(map);
      await _rememberProducts([item]);
      return item;
    } catch (_) {
      final workspaceId = _workspace;
      if (workspaceId != null) {
        final cached = await _local?.product(workspaceId, id);
        if (cached != null) return cached;
      }
      rethrow;
    }
  }

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
        workerMinimumPrice: workerMinimumPrice,
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
    final item = ChatCatalogItemModel.fromJson(map);
    await _rememberProducts([item]);
    return item;
  }

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
    final digital = itemKind == 'digital' || tangibility == 'intangible';
    final res = await _api.patch(
      '/catalog/$id',
      body: {
        'name': ?name,
        'description': ?description,
        'sku': ?sku,
        if (itemKind != null || tangibility != null)
          ..._kindFields(itemKind ?? 'product', tangibility ?? 'tangible'),
        if (price != null) 'price': price.toStringAsFixed(2),
        if (!digital)
          'worker_min_price': workerMinimumPrice?.toStringAsFixed(2),
        'currency': ?currency,
        if (!digital) 'stock': ?stock,
        'inventory_policy': ?inventoryPolicy,
        'is_active': ?isActive,
        if (clearCategory) 'chat_catalog_category_id': null,
        if (!clearCategory && categoryId != null)
          'chat_catalog_category_id': categoryId,
        if (!digital && variants != null)
          'variants': variants.map((v) => v.toWriteJson()).toList(),
      },
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحديث الصنف.');
    final item = ChatCatalogItemModel.fromJson(map);
    await _rememberProducts([item]);
    return item;
  }

  Future<ChatCatalogItemModel> updateInventory(
    int id, {
    required String inventoryPolicy,
    int? stock,
  }) async {
    final res = await _api.patch(
      '/catalog/$id/inventory',
      body: {'inventory_policy': inventoryPolicy, 'stock': ?stock},
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحديث المخزون.');
    final item = ChatCatalogItemModel.fromJson(map);
    await _rememberProducts([item]);
    return item;
  }

  Future<void> setActive(int id, {required bool active}) async {
    await _api.post(
      active ? '/catalog/$id/activate' : '/catalog/$id/deactivate',
    );
    final workspaceId = _workspace;
    if (workspaceId != null) {
      await _local?.setProductActive(workspaceId, id, active: active);
    }
  }

  Future<void> delete(int id) async {
    await _api.delete('/catalog/$id');
    final workspaceId = _workspace;
    if (workspaceId != null) await _local?.deleteProduct(workspaceId, id);
  }

  Future<void> uploadItemMedia(
    int id, {
    List<int>? imageBytes,
    String? imageName,
    List<int>? fileBytes,
    String? fileName,
    void Function(int sent, int total)? onProgress,
  }) async {
    final map = <String, dynamic>{};
    if (imageBytes != null && imageName != null) {
      map['image_file'] = MultipartFile.fromBytes(
        imageBytes,
        filename: imageName,
      );
    }
    if (fileBytes != null && fileName != null) {
      map['gallery_files'] = [
        MultipartFile.fromBytes(fileBytes, filename: fileName),
      ];
    }
    if (map.isEmpty) return;
    await _api.upload(
      '/catalog/$id',
      formData: FormData.fromMap(map),
      onSendProgress: onProgress,
    );
  }

  Future<({List<Map<String, dynamic>> items, String? nextCursor})>
  digitalPools({String? cursor}) async {
    final res = await _api.get(
      '/catalog/digital-pools',
      query: {'cursor': ?cursor, 'per_page': 30},
    );
    final items = asMapList(res.data);
    final workspaceId = _workspace;
    if (workspaceId != null) {
      await _local?.upsertDigitalPools(workspaceId, items);
    }
    return (items: items, nextCursor: res.nextCursor);
  }

  Future<List<Map<String, dynamic>>> cachedDigitalPools() async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const [];
    return _local.digitalPools(workspaceId);
  }

  Future<Map<String, dynamic>> createDigitalPool({
    required int itemId,
    required String name,
    int? variantId,
  }) async {
    final res = await _api.post(
      '/catalog/digital-pools',
      idempotent: true,
      body: {
        'chat_catalog_item_id': itemId,
        'name': name,
        'chat_catalog_item_variant_id': ?variantId,
      },
    );
    final map = asMap(res.data);
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر إنشاء المخزون الرقمي.');
    }
    return map;
  }

  Future<List<Map<String, dynamic>>> digitalUnits(int poolId) async {
    final res = await _api.get('/catalog/digital-pools/$poolId/units');
    final items = asMapList(res.data);
    final workspaceId = _workspace;
    if (workspaceId != null) {
      await _local?.upsertDigitalUnits(workspaceId, poolId, items);
    }
    return items;
  }

  Future<void> importDigitalSecrets(
    int poolId, {
    required List<String> secrets,
  }) async {
    await _api.post(
      '/catalog/digital-pools/$poolId/units/import',
      idempotent: true,
      body: {'unit_type': 'code', 'secrets': secrets},
    );
  }

  Future<void> deleteDigitalPool(int poolId) async {
    await _api.delete('/catalog/digital-pools/$poolId');
  }

  Future<void> deleteDigitalUnit(int unitId) async {
    await _api.delete('/catalog/digital-units/$unitId');
  }

  Future<void> reserveDigitalUnit(
    int poolId, {
    required String reservationKey,
    int? ttlSeconds,
  }) async {
    await _api.post(
      '/catalog/digital-pools/$poolId/reserve',
      idempotent: true,
      body: {'reservation_key': reservationKey, 'ttl_seconds': ?ttlSeconds},
    );
  }

  Future<void> releaseDigitalUnit(
    int unitId, {
    required String reservationKey,
  }) async {
    await _api.post(
      '/catalog/digital-units/$unitId/release',
      idempotent: true,
      body: {'reservation_key': reservationKey},
    );
  }

  Future<void> consumeDigitalUnit(
    int unitId, {
    required String reservationKey,
  }) async {
    await _api.post(
      '/catalog/digital-units/$unitId/consume',
      idempotent: true,
      body: {'reservation_key': reservationKey},
    );
  }

  Future<void> replaceDigitalSecret(
    int unitId, {
    required String secret,
  }) async {
    await _api.patch(
      '/catalog/digital-units/$unitId/secret',
      body: {'secret': secret},
    );
  }

  Future<void> updateDigitalUnitStatus(
    int unitId, {
    required String status,
  }) async {
    await _api.patch(
      '/catalog/digital-units/$unitId/status',
      body: {'status': status},
    );
  }

  Future<ChatCatalogSettingsModel> fetchSettings() async {
    final res = await _api.get('/catalog/settings');
    final map = asMap(res.data);
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر تحميل إعدادات المنتجات.');
    }
    return ChatCatalogSettingsModel.fromJson(map);
  }

  Future<ChatCatalogSettingsModel> updateSettings(
    Map<String, dynamic> body,
  ) async {
    final res = await _api.patch('/catalog/settings', body: body);
    final map = asMap(res.data);
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر حفظ إعدادات المنتجات.');
    }
    return ChatCatalogSettingsModel.fromJson(map);
  }

  Future<ChatCatalogSettingsModel> updateCommunication(
    Map<String, dynamic> body,
  ) async {
    final res = await _api.patch('/catalog/settings/communication', body: body);
    final map = asMap(res.data);
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر حفظ إعدادات التواصل.');
    }
    return ChatCatalogSettingsModel.fromJson(map);
  }

  Map<String, dynamic> _itemBody({
    required String name,
    String? description,
    String? sku,
    required String itemKind,
    required String tangibility,
    required num price,
    num? workerMinimumPrice,
    required String currency,
    int? stock,
    String? inventoryPolicy,
    required bool isActive,
    int? categoryId,
    List<ChatCatalogVariantModel>? variants,
  }) {
    final digital = itemKind == 'digital' || tangibility == 'intangible';
    return {
      'name': name,
      if (description != null && description.isNotEmpty)
        'description': description,
      if (sku != null && sku.isNotEmpty) 'sku': sku,
      ..._kindFields(itemKind, tangibility),
      'price': price.toStringAsFixed(2),
      if (!digital && workerMinimumPrice != null)
        'worker_min_price': workerMinimumPrice.toStringAsFixed(2),
      'currency': currency,
      if (!digital) 'stock': ?stock,
      if (inventoryPolicy != null && inventoryPolicy.isNotEmpty)
        'inventory_policy': inventoryPolicy,
      'is_active': isActive,
      'chat_catalog_category_id': ?categoryId,
      if (!digital && variants != null && variants.isNotEmpty)
        'variants': variants.map((v) => v.toWriteJson()).toList(),
    };
  }

  /// The API accepts item_kind product|service only. Digital products use
  /// classification=digital and tangibility=intangible.
  Map<String, String> _kindFields(String itemKind, String tangibility) {
    if (itemKind == 'service') {
      return {
        'item_kind': 'service',
        'classification': 'service',
        'tangibility': tangibility,
      };
    }
    if (itemKind == 'digital' || tangibility == 'intangible') {
      return {
        'item_kind': 'product',
        'classification': 'digital',
        'tangibility': 'intangible',
      };
    }
    return {
      'item_kind': 'product',
      'classification': 'physical',
      'tangibility': tangibility,
    };
  }
}
