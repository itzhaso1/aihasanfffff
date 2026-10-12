class WorkerProduct {
  const WorkerProduct({
    required this.id,
    required this.name,
    this.description,
    this.imageUrl,
    this.categoryName,
    this.price,
    this.minimumSellingPrice,
    this.maximumSellingPrice,
    this.currency,
    this.status,
    this.itemKind = 'product',
  });

  final int id;
  final String name;
  final String? description;
  final String? imageUrl;
  final String? categoryName;
  final num? price;
  final num? minimumSellingPrice;
  final num? maximumSellingPrice;
  final String? currency;
  final String? status;
  final String itemKind;

  factory WorkerProduct.fromJson(Map<String, dynamic> json) {
    final category = json['category'];
    return WorkerProduct(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: json['name']?.toString() ?? '',
      description: _text(json['description']),
      imageUrl: _text(json['image_url']),
      categoryName: category is Map
          ? _text(category['name'])
          : _text(json['category_name']),
      price: _number(json['price']),
      minimumSellingPrice: _number(json['minimum_selling_price']),
      maximumSellingPrice: _number(json['maximum_selling_price']),
      currency: _text(json['currency']),
      status: _text(json['status']),
      itemKind: json['item_kind']?.toString() ?? 'product',
    );
  }
}

String? _text(dynamic value) {
  final text = value?.toString().trim() ?? '';
  return text.isEmpty ? null : text;
}

num? _number(dynamic value) {
  if (value is num) return value;
  if (value is String) return num.tryParse(value.trim());
  if (value is Map) return _number(value['amount'] ?? value['value']);
  return null;
}
