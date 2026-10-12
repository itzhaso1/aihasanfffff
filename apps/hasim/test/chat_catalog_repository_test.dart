import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/data/chat_catalog_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemorySecureStore extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}

  @override
  Future<String?> readToken() async => 'token';

  @override
  Future<void> clearToken() async {}
}

Map<String, dynamic> _itemJson({int id = 44, String name = 'طاولة'}) {
  return {
    'id': id,
    'name': name,
    'price': '80.00',
    'currency': 'SAR',
    'item_kind': 'product',
    'tangibility': 'tangible',
    'is_active': true,
    'worker_min_price': '22.00',
    'worker_max_price': null,
    'variants': [
      {'id': 9, 'name': 'كبير', 'price': '80.00'},
    ],
  };
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  Future<({ChatCatalogRepository repo, List<RequestOptions> calls})>
  repository() async {
    SharedPreferences.setMockInitialValues({});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    final calls = <RequestOptions>[];
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1/api/mobile/v1/'));
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          calls.add(options);
          handler.resolve(
            Response(
              requestOptions: options,
              data: {'success': true, 'data': _itemJson()},
            ),
          );
        },
      ),
    );
    return (
      repo: ChatCatalogRepository(
        ApiClient(
          secureStore: _MemorySecureStore(),
          prefsStore: prefs,
          dio: dio,
        ),
      ),
      calls: calls,
    );
  }

  test(
    'update omits worker_max_price and keeps existing worker_min_price',
    () async {
      final setup = await repository();
      await setup.repo.update(
        44,
        name: 'طاولة مكتب',
        price: 80,
        workerMinimumPrice: 22,
        currency: 'SAR',
        variants: const [
          ChatCatalogVariantModel(id: 9, name: 'كبير', price: 80),
        ],
      );

      expect(setup.calls, hasLength(1));
      expect(setup.calls.single.method, 'PATCH');
      expect(setup.calls.single.path, 'catalog/44');
      final body = Map<String, dynamic>.from(setup.calls.single.data as Map);
      expect(body.containsKey('worker_max_price'), isFalse);
      expect(body['worker_min_price'], '22.00');
      expect(body['price'], '80.00');
      expect(body['name'], 'طاولة مكتب');
      expect(body['variants'], [
        {
          'id': 9,
          'name': 'كبير',
          'price': 80,
          'is_active': true,
          'is_visible': true,
          'sort_order': 0,
        },
      ]);
    },
  );

  test(
    'update does not invent a null worker_max_price when the field is unused',
    () async {
      final setup = await repository();
      await setup.repo.update(44, name: 'طاولة');
      final body = Map<String, dynamic>.from(setup.calls.single.data as Map);
      expect(body.containsKey('worker_max_price'), isFalse);
      expect(body.containsKey('worker_min_price'), isTrue);
      expect(body['worker_min_price'], isNull);
    },
  );

  test('normal product payload contains physical fields only', () async {
    final setup = await repository();
    await setup.repo.create(
      name: 'طاولة',
      itemKind: 'product',
      tangibility: 'tangible',
      price: 80,
      workerMinimumPrice: 22,
      currency: 'SAR',
      stock: 5,
      inventoryPolicy: 'finite_stock',
      variants: const [ChatCatalogVariantModel(id: 0, name: 'كبير', price: 80)],
    );

    final body = Map<String, dynamic>.from(setup.calls.single.data as Map);
    expect(body['classification'], 'physical');
    expect(body['tangibility'], 'tangible');
    expect(body['stock'], 5);
    expect(body['worker_min_price'], '22.00');
    expect(body['variants'], isNotEmpty);
    expect(body.containsKey('digital_subtype'), isFalse);
  });

  test('digital product payload excludes physical inventory fields', () async {
    final setup = await repository();
    await setup.repo.create(
      name: 'ترخيص',
      itemKind: 'digital',
      tangibility: 'intangible',
      price: 80,
      workerMinimumPrice: 22,
      currency: 'SAR',
      stock: 5,
      inventoryPolicy: 'digital_pool',
      variants: const [ChatCatalogVariantModel(id: 0, name: 'غير مطلوب')],
    );

    final body = Map<String, dynamic>.from(setup.calls.single.data as Map);
    expect(body['item_kind'], 'product');
    expect(body['classification'], 'digital');
    expect(body['tangibility'], 'intangible');
    expect(body['inventory_policy'], 'digital_pool');
    expect(body.containsKey('stock'), isFalse);
    expect(body.containsKey('worker_min_price'), isFalse);
    expect(body.containsKey('variants'), isFalse);
  });

  test(
    'digital update keeps the existing type and excludes physical fields',
    () async {
      final setup = await repository();
      await setup.repo.update(
        44,
        itemKind: 'digital',
        tangibility: 'intangible',
        price: 90,
        workerMinimumPrice: 22,
        stock: 5,
        inventoryPolicy: 'file_asset',
        variants: const [ChatCatalogVariantModel(id: 9, name: 'غير مطلوب')],
      );

      final body = Map<String, dynamic>.from(setup.calls.single.data as Map);
      expect(body['classification'], 'digital');
      expect(body['tangibility'], 'intangible');
      expect(body['inventory_policy'], 'file_asset');
      expect(body.containsKey('stock'), isFalse);
      expect(body.containsKey('worker_min_price'), isFalse);
      expect(body.containsKey('variants'), isFalse);
    },
  );

  test('delete uses the existing catalog destroy route', () async {
    final setup = await repository();
    await setup.repo.delete(44);
    expect(setup.calls.single.method, 'DELETE');
    expect(setup.calls.single.path, 'catalog/44');
  });

  test('digital reserve release consume match the mobile contract', () async {
    final setup = await repository();
    await setup.repo.reserveDigitalUnit(
      7,
      reservationKey: 'hold-44',
      ttlSeconds: 900,
    );
    await setup.repo.releaseDigitalUnit(12, reservationKey: 'hold-44');
    await setup.repo.consumeDigitalUnit(12, reservationKey: 'hold-44');

    expect(setup.calls, hasLength(3));
    expect(setup.calls[0].method, 'POST');
    expect(setup.calls[0].path, 'catalog/digital-pools/7/reserve');
    expect(setup.calls[0].data, {
      'reservation_key': 'hold-44',
      'ttl_seconds': 900,
    });
    expect(setup.calls[1].method, 'POST');
    expect(setup.calls[1].path, 'catalog/digital-units/12/release');
    expect(setup.calls[1].data, {'reservation_key': 'hold-44'});
    expect(setup.calls[2].method, 'POST');
    expect(setup.calls[2].path, 'catalog/digital-units/12/consume');
    expect(setup.calls[2].data, {'reservation_key': 'hold-44'});
  });
}
