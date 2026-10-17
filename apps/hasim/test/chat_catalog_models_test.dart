import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';

void main() {
  group('ChatCatalogCategoryModel', () {
    test('parses Laravel category resource fields', () {
      final model = ChatCatalogCategoryModel.fromJson({
        'id': 3,
        'name': 'Electronics',
        'slug': 'electronics',
        'description': 'Phones',
        'parent_id': 1,
        'status': 'published',
        'is_active': true,
        'is_visible': false,
        'is_featured': true,
        'sort_order': 4,
      });
      expect(model.id, 3);
      expect(model.name, 'Electronics');
      expect(model.parentId, 1);
      expect(model.isVisible, isFalse);
      expect(model.sortOrder, 4);
    });
  });

  group('ChatCatalogVariantModel', () {
    test('parses money strings and inventory policy', () {
      final model = ChatCatalogVariantModel.fromJson({
        'id': 9,
        'name': 'Large',
        'sku': 'L-1',
        'barcode': '123',
        'price': '19.50',
        'sale_price': '15.00',
        'compare_at_price': '22.00',
        'currency': 'SAR',
        'stock': 12,
        'inventory_policy': 'finite_stock',
        'is_active': true,
        'is_visible': true,
      });
      expect(model.price, 19.5);
      expect(model.currency, 'SAR');
      expect(model.isVisible, isTrue);
      expect(model.stock, 12);
      expect(model.inventoryPolicy, 'finite_stock');
      expect(selectableInventoryPolicy('backorder'), 'finite_stock');
      expect(chatCatalogInventoryPolicies.contains('backorder'), isFalse);
      expect(model.toWriteJson()['price'], '19.50');
      expect(model.toWriteJson().containsKey('sale_price'), isFalse);
      expect(model.toWriteJson().containsKey('compare_at_price'), isFalse);
      expect(model.toWriteJson().containsKey('barcode'), isFalse);
    });

    test('write payload remaps retired inventory policy and money text', () {
      const variant = ChatCatalogVariantModel(
        id: 4,
        name: 'عربي PDF',
        price: 10,
        inventoryPolicy: 'backorder',
      );

      expect(variant.toWriteJson()['price'], '10.00');
      expect(variant.toWriteJson()['inventory_policy'], 'finite_stock');
      expect(parseOptionalCatalogMoney('15,5'), 15.5);
      expect(parseOptionalCatalogMoney('١٥.٥'), 15.5);
    });

    test('toWriteJson omits id for new variants', () {
      const v = ChatCatalogVariantModel(id: 0, name: 'New');
      expect(v.toWriteJson().containsKey('id'), isFalse);
      expect(v.toWriteJson()['name'], 'New');
    });
  });

  group('ChatCatalogItemModel', () {
    test('parses nested variants and category id', () {
      final item = ChatCatalogItemModel.fromJson({
        'id': 1,
        'name': 'Shirt',
        'price': '25.00',
        'worker_min_price': '22.00',
        'worker_max_price': '30.00',
        'currency': 'SAR',
        'chat_catalog_category_id': 7,
        'inventory_policy': 'finite_stock',
        'stock': 3,
        'variants': [
          {'id': 2, 'name': 'M', 'price': '25.00', 'stock': 1},
        ],
      });
      expect(parseCatalogMoney('25.00'), 25);
      expect(item.price, 25);
      expect(item.workerMinimumPrice, 22);
      expect(item.workerMaximumPrice, 30);
      expect(item.categoryId, 7);
      expect(item.variants, hasLength(1));
      expect(item.inventoryPolicy, 'finite_stock');
    });
  });
}
