import 'package:equatable/equatable.dart';
import 'package:hasim/core/models/models.dart';

/// Parses Laravel [Money::of] string or numeric price fields.
num parseCatalogMoney(dynamic value) {
  if (value == null) return 0;
  if (value is num) return value;
  if (value is String) return num.tryParse(value.trim()) ?? 0;
  return 0;
}

const chatCatalogInventoryPolicies = [
  'not_tracked',
  'finite_stock',
  'unlimited',
  'backorder',
  'on_request',
  'digital_pool',
  'file_asset',
  'service_capacity',
];

class ChatCatalogCategoryModel extends Equatable {
  const ChatCatalogCategoryModel({
    required this.id,
    required this.name,
    this.slug,
    this.description,
    this.parentId,
    this.status,
    this.isActive = true,
    this.isVisible = true,
    this.isFeatured = false,
    this.sortOrder = 0,
  });

  final int id;
  final String name;
  final String? slug;
  final String? description;
  final int? parentId;
  final String? status;
  final bool isActive;
  final bool isVisible;
  final bool isFeatured;
  final int sortOrder;

  factory ChatCatalogCategoryModel.fromJson(Map<String, dynamic> json) => ChatCatalogCategoryModel(
        id: (json['id'] as num?)?.toInt() ?? 0,
        name: json['name']?.toString() ?? '',
        slug: json['slug']?.toString(),
        description: json['description']?.toString(),
        parentId: (json['parent_id'] as num?)?.toInt(),
        status: json['status']?.toString(),
        isActive: json['is_active'] != false,
        isVisible: json['is_visible'] != false,
        isFeatured: json['is_featured'] == true,
        sortOrder: (json['sort_order'] as num?)?.toInt() ?? 0,
      );

  Map<String, dynamic> toWriteJson({bool forUpdate = false}) {
    return {
      if (forUpdate) ...{
        'name': name,
      } else ...{
        'name': name,
      },
      if (description != null && description!.isNotEmpty) 'description': description,
      if (parentId != null) 'parent_id': parentId,
      'is_active': isActive,
      'is_visible': isVisible,
      'is_featured': isFeatured,
      'sort_order': sortOrder,
      if (status != null && status!.isNotEmpty) 'status': status,
      if (slug != null && slug!.isNotEmpty) 'slug': slug,
    };
  }

  ChatCatalogCategoryModel copyWith({
    String? name,
    String? slug,
    String? description,
    int? parentId,
    bool clearParentId = false,
    String? status,
    bool? isActive,
    bool? isVisible,
    bool? isFeatured,
    int? sortOrder,
  }) {
    return ChatCatalogCategoryModel(
      id: id,
      name: name ?? this.name,
      slug: slug ?? this.slug,
      description: description ?? this.description,
      parentId: clearParentId ? null : (parentId ?? this.parentId),
      status: status ?? this.status,
      isActive: isActive ?? this.isActive,
      isVisible: isVisible ?? this.isVisible,
      isFeatured: isFeatured ?? this.isFeatured,
      sortOrder: sortOrder ?? this.sortOrder,
    );
  }

  @override
  List<Object?> get props => [id, name, isActive, sortOrder, parentId];
}

class ChatCatalogVariantModel extends Equatable {
  const ChatCatalogVariantModel({
    required this.id,
    required this.name,
    this.sku,
    this.barcode,
    this.price,
    this.currency,
    this.stock,
    this.inventoryPolicy,
    this.isActive = true,
    this.isVisible = true,
    this.status,
    this.sortOrder = 0,
    this.attributes = const {},
  });

  final int id;
  final String name;
  final String? sku;
  final String? barcode;
  final num? price;
  final String? currency;
  final int? stock;
  final String? inventoryPolicy;
  final bool isActive;
  final bool isVisible;
  final String? status;
  final int sortOrder;
  final Map<String, dynamic> attributes;

  bool get isNew => id <= 0;

  factory ChatCatalogVariantModel.fromJson(Map<String, dynamic> json) => ChatCatalogVariantModel(
        id: (json['id'] as num?)?.toInt() ?? 0,
        name: json['name']?.toString() ?? '',
        sku: json['sku']?.toString(),
        barcode: json['barcode']?.toString(),
        price: json['price'] == null ? null : parseCatalogMoney(json['price']),
        currency: json['currency']?.toString(),
        stock: (json['stock'] as num?)?.toInt(),
        inventoryPolicy: json['inventory_policy']?.toString(),
        isActive: json['is_active'] != false,
        isVisible: json['is_visible'] != false,
        status: json['status']?.toString(),
        sortOrder: (json['sort_order'] as num?)?.toInt() ?? 0,
        attributes: json['attributes'] is Map<String, dynamic>
            ? json['attributes'] as Map<String, dynamic>
            : const {},
      );

  Map<String, dynamic> toWriteJson() {
    return {
      if (!isNew) 'id': id,
      'name': name,
      if (sku != null && sku!.isNotEmpty) 'sku': sku,
      if (barcode != null && barcode!.isNotEmpty) 'barcode': barcode,
      if (price != null) 'price': price,
      if (currency != null && currency!.isNotEmpty) 'currency': currency,
      if (stock != null) 'stock': stock,
      if (inventoryPolicy != null && inventoryPolicy!.isNotEmpty) 'inventory_policy': inventoryPolicy,
      'is_active': isActive,
      'is_visible': isVisible,
      if (status != null && status!.isNotEmpty) 'status': status,
      'sort_order': sortOrder,
      if (attributes.isNotEmpty) 'attributes': attributes,
    };
  }

  ChatCatalogVariantModel copyWith({
    int? id,
    String? name,
    String? sku,
    String? barcode,
    num? price,
    String? currency,
    int? stock,
    String? inventoryPolicy,
    bool? isActive,
    bool? isVisible,
    String? status,
    int? sortOrder,
    Map<String, dynamic>? attributes,
  }) {
    return ChatCatalogVariantModel(
      id: id ?? this.id,
      name: name ?? this.name,
      sku: sku ?? this.sku,
      barcode: barcode ?? this.barcode,
      price: price ?? this.price,
      currency: currency ?? this.currency,
      stock: stock ?? this.stock,
      inventoryPolicy: inventoryPolicy ?? this.inventoryPolicy,
      isActive: isActive ?? this.isActive,
      isVisible: isVisible ?? this.isVisible,
      status: status ?? this.status,
      sortOrder: sortOrder ?? this.sortOrder,
      attributes: attributes ?? this.attributes,
    );
  }

  @override
  List<Object?> get props => [id, name, sku, price, stock];
}

class ChatCatalogItemModel extends Equatable {
  const ChatCatalogItemModel({
    required this.id,
    required this.name,
    this.slug,
    this.description,
    this.sku,
    this.itemKind = 'product',
    this.tangibility = 'tangible',
    this.price = 0,
    this.currency = 'SAR',
    this.stock,
    this.inventoryPolicy,
    this.isActive = true,
    this.imageUrl,
    this.categoryId,
    this.category,
    this.variants = const [],
  });

  final int id;
  final String name;
  final String? slug;
  final String? description;
  final String? sku;
  final String itemKind;
  final String tangibility;
  final num price;
  final String currency;
  final int? stock;
  final String? inventoryPolicy;
  final bool isActive;
  final String? imageUrl;
  final int? categoryId;
  final ChatCatalogCategoryModel? category;
  final List<ChatCatalogVariantModel> variants;

  factory ChatCatalogItemModel.fromJson(Map<String, dynamic> json) {
    final categoryRaw = json['category'];
    final categoryId = (json['chat_catalog_category_id'] as num?)?.toInt();
    return ChatCatalogItemModel(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: json['name']?.toString() ?? '',
      slug: json['slug']?.toString(),
      description: json['description']?.toString(),
      sku: json['sku']?.toString(),
      itemKind: json['item_kind']?.toString() ?? 'product',
      tangibility: json['tangibility']?.toString() ?? 'tangible',
      price: parseCatalogMoney(json['price']),
      currency: json['currency']?.toString() ?? 'SAR',
      stock: (json['stock'] as num?)?.toInt(),
      inventoryPolicy: json['inventory_policy']?.toString(),
      isActive: json['is_active'] != false,
      imageUrl: json['image_url']?.toString(),
      categoryId: categoryId ?? (categoryRaw is Map ? (categoryRaw['id'] as num?)?.toInt() : null),
      category: categoryRaw is Map
          ? ChatCatalogCategoryModel.fromJson(
              categoryRaw is Map<String, dynamic> ? categoryRaw : Map<String, dynamic>.from(categoryRaw),
            )
          : null,
      variants: asMapList(json['variants']).map(ChatCatalogVariantModel.fromJson).toList(),
    );
  }

  @override
  List<Object?> get props => [id, name, price, isActive, itemKind, stock, inventoryPolicy];
}
