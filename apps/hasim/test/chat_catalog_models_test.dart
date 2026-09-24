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
        'price': '19.50',
        'stock': 12,
        'inventory_policy': 'finite_stock',
        'is_active': true,
      });
      expect(model.price, 19.5);
      expect(model.stock, 12);
      expect(model.inventoryPolicy, 'finite_stock');
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
      expect(item.categoryId, 7);
      expect(item.variants, hasLength(1));
      expect(item.inventoryPolicy, 'finite_stock');
    });
  });
}
