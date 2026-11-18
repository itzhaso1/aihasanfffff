import 'package:equatable/equatable.dart';
import 'package:hasim/core/models/models.dart';

class CommerceOrderItemModel extends Equatable {
  const CommerceOrderItemModel({
    required this.id,
    required this.name,
    required this.quantity,
    required this.unitPrice,
    required this.totalAmount,
    this.variantName,
    this.sku,
    this.currency = 'SAR',
    this.sellerDisplayName,
    this.description,
  });

  final int id;
  final String name;
  final int quantity;
  final dynamic unitPrice;
  final dynamic totalAmount;
  final String? variantName;
  final String? sku;
  final String currency;
  final String? sellerDisplayName;
  final String? description;

  factory CommerceOrderItemModel.fromJson(Map<String, dynamic> json) =>
      CommerceOrderItemModel(
        id: (json['id'] as num?)?.toInt() ?? 0,
        name: json['name']?.toString() ?? '',
        quantity: (json['quantity'] as num?)?.toInt() ?? 1,
        unitPrice: json['unit_price'],
        totalAmount: json['total_amount'],
        variantName: json['variant_name']?.toString(),
        sku: json['sku']?.toString(),
        currency: json['currency']?.toString() ?? 'SAR',
        sellerDisplayName: json['seller_display_name']?.toString(),
        description: json['description']?.toString(),
      );

  Map<String, dynamic> toJson() => {
    'id': id,
    'name': name,
    'quantity': quantity,
    'unit_price': unitPrice,
    'total_amount': totalAmount,
    if (variantName != null) 'variant_name': variantName,
    if (sku != null) 'sku': sku,
    'currency': currency,
    if (sellerDisplayName != null) 'seller_display_name': sellerDisplayName,
    if (description != null) 'description': description,
  };

  @override
  List<Object?> get props => [
    id,
    name,
    quantity,
    unitPrice,
    totalAmount,
    variantName,
    sku,
    currency,
    sellerDisplayName,
    description,
  ];
}

class CommerceOrderModel extends Equatable {
  const CommerceOrderModel({
    required this.id,
    required this.orderNumber,
    required this.status,
    required this.currency,
    this.customerId,
    this.customer,
    this.paymentStatus,
    this.fulfillmentStatus,
    this.subtotal,
    this.discountAmount,
    this.taxAmount,
    this.shippingAmount,
    this.totalAmount,
    this.channel,
    this.placedAt,
    this.cancelledAt,
    this.items = const [],
  });

  final int id;
  final String orderNumber;
  final String status;
  final String currency;
  final int? customerId;
  final CustomerModel? customer;
  final String? paymentStatus;
  final String? fulfillmentStatus;
  final dynamic subtotal;
  final dynamic discountAmount;
  final dynamic taxAmount;
  final dynamic shippingAmount;
  final dynamic totalAmount;
  final String? channel;
  final String? placedAt;
  final String? cancelledAt;
  final List<CommerceOrderItemModel> items;

  factory CommerceOrderModel.fromJson(Map<String, dynamic> json) =>
      CommerceOrderModel(
        id: (json['id'] as num).toInt(),
        orderNumber: json['order_number']?.toString() ?? '',
        status: json['status']?.toString() ?? 'draft',
        currency: json['currency']?.toString() ?? 'SAR',
        customerId: (json['customer_id'] as num?)?.toInt(),
        customer: json['customer'] is Map
            ? CustomerModel.fromJson(
                Map<String, dynamic>.from(json['customer'] as Map),
              )
            : null,
        paymentStatus: json['payment_status']?.toString(),
        fulfillmentStatus: json['fulfillment_status']?.toString(),
        subtotal: json['subtotal'],
        discountAmount: json['discount_amount'],
        taxAmount: json['tax_amount'],
        shippingAmount: json['shipping_amount'],
        totalAmount: json['total_amount'],
        channel: json['channel']?.toString(),
        placedAt: json['placed_at']?.toString(),
        cancelledAt: json['cancelled_at']?.toString(),
        items:
            (json['items'] as List?)
                ?.whereType<Map>()
                .map(
                  (e) => CommerceOrderItemModel.fromJson(
                    Map<String, dynamic>.from(e),
                  ),
                )
                .toList() ??
            const [],
      );

  String get statusLabel {
    return switch (status.toLowerCase()) {
      'completed' || 'fulfilled' => 'مكتمل',
      'pending_payment' => 'بانتظار الدفع',
      'processing' => 'قيد المعالجة',
      'cancelled' => 'ملغي',
      'draft' => 'مسودة',
      _ => status,
    };
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'order_number': orderNumber,
    'status': status,
    'currency': currency,
    if (customerId != null) 'customer_id': customerId,
    if (customer != null) 'customer': customer!.toJson(),
    if (paymentStatus != null) 'payment_status': paymentStatus,
    if (fulfillmentStatus != null) 'fulfillment_status': fulfillmentStatus,
    if (subtotal != null) 'subtotal': subtotal,
    if (discountAmount != null) 'discount_amount': discountAmount,
    if (taxAmount != null) 'tax_amount': taxAmount,
    if (shippingAmount != null) 'shipping_amount': shippingAmount,
    if (totalAmount != null) 'total_amount': totalAmount,
    if (channel != null) 'channel': channel,
    if (placedAt != null) 'placed_at': placedAt,
    if (cancelledAt != null) 'cancelled_at': cancelledAt,
    'items': items.map((e) => e.toJson()).toList(),
  };

  @override
  List<Object?> get props => [
    id,
    orderNumber,
    status,
    currency,
    customerId,
    customer,
    paymentStatus,
    fulfillmentStatus,
    subtotal,
    discountAmount,
    taxAmount,
    shippingAmount,
    totalAmount,
    channel,
    placedAt,
    cancelledAt,
    items,
  ];
}
