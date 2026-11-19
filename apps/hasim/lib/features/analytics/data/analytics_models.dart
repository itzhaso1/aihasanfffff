import 'package:equatable/equatable.dart';

class TimelineEntryModel extends Equatable {
  const TimelineEntryModel({
    required this.label,
    required this.orders,
    required this.revenue,
  });

  final String label;
  final int orders;
  final double revenue;

  factory TimelineEntryModel.fromJson(Map<String, dynamic> json) {
    return TimelineEntryModel(
      label: json['label']?.toString() ?? '',
      orders: (json['orders'] as num?)?.toInt() ?? 0,
      revenue: (json['revenue'] as num?)?.toDouble() ?? 0.0,
    );
  }

  @override
  List<Object?> get props => [label, orders, revenue];
}

class TopProductModel extends Equatable {
  const TopProductModel({
    required this.name,
    required this.quantity,
    required this.revenue,
  });

  final String name;
  final double quantity;
  final double revenue;

  factory TopProductModel.fromJson(Map<String, dynamic> json) {
    return TopProductModel(
      name: json['name']?.toString() ?? 'منتج',
      quantity: (json['quantity'] as num?)?.toDouble() ?? 0.0,
      revenue: (json['revenue'] as num?)?.toDouble() ?? 0.0,
    );
  }

  @override
  List<Object?> get props => [name, quantity, revenue];
}

class WorkspaceAnalyticsModel extends Equatable {
  const WorkspaceAnalyticsModel({
    required this.from,
    required this.to,
    required this.revenue,
    required this.ordersCount,
    required this.customersNew,
    required this.conversationsCount,
    required this.aiResponsesCount,
    required this.conversionRate,
    this.timeline = const [],
    this.topProducts = const [],
    this.peakHours = const {},
    this.paymentStatusBreakdown = const {},
  });

  final String from;
  final String to;
  final double revenue;
  final int ordersCount;
  final int customersNew;
  final int conversationsCount;
  final int aiResponsesCount;
  final double conversionRate;
  final List<TimelineEntryModel> timeline;
  final List<TopProductModel> topProducts;
  final Map<String, int> peakHours;
  final Map<String, int> paymentStatusBreakdown;

  factory WorkspaceAnalyticsModel.fromJson(Map<String, dynamic> json) {
    final rawTimeline = json['timeline'] as List? ?? [];
    final timeline = rawTimeline
        .whereType<Map<String, dynamic>>()
        .map(TimelineEntryModel.fromJson)
        .toList();

    final rawProducts = json['top_products'] as List? ?? [];
    final topProducts = rawProducts
        .whereType<Map<String, dynamic>>()
        .map(TopProductModel.fromJson)
        .toList();

    final rawPeak = json['peak_hours'] as Map? ?? {};
    final peakHours = <String, int>{};
    rawPeak.forEach((k, v) {
      peakHours[k.toString()] = (v as num?)?.toInt() ?? 0;
    });

    final rawPay = json['payment_status_breakdown'] as Map? ?? {};
    final payBreakdown = <String, int>{};
    rawPay.forEach((k, v) {
      payBreakdown[k.toString()] = (v as num?)?.toInt() ?? 0;
    });

    return WorkspaceAnalyticsModel(
      from: json['from']?.toString() ?? '',
      to: json['to']?.toString() ?? '',
      revenue: (json['revenue'] as num?)?.toDouble() ?? 0.0,
      ordersCount: (json['orders_count'] as num?)?.toInt() ?? 0,
      customersNew: (json['customers_new'] as num?)?.toInt() ?? 0,
      conversationsCount: (json['conversations_count'] as num?)?.toInt() ?? 0,
      aiResponsesCount: (json['ai_responses_count'] as num?)?.toInt() ?? 0,
      conversionRate: (json['conversion_rate'] as num?)?.toDouble() ?? 0.0,
      timeline: timeline,
      topProducts: topProducts,
      peakHours: peakHours,
      paymentStatusBreakdown: payBreakdown,
    );
  }

  @override
  List<Object?> get props => [
    from,
    to,
    revenue,
    ordersCount,
    customersNew,
    conversationsCount,
    aiResponsesCount,
    conversionRate,
    timeline,
    topProducts,
  ];
}
