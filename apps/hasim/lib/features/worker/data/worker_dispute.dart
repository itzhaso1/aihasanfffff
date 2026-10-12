/// Engagement-scoped WorkerDispute cases from /worker/disputes.
class WorkerDispute {
  const WorkerDispute({
    required this.id,
    required this.workerEngagementId,
    required this.reporterType,
    required this.category,
    required this.description,
    required this.status,
    this.resolutionSummary,
    this.openedAt,
    this.underReviewAt,
    this.resolvedAt,
    this.closedAt,
  });

  static const statusLabels = {
    'open': 'مفتوح',
    'under_review': 'قيد المراجعة',
    'resolved': 'محلول',
    'closed': 'مغلق',
  };

  static const reporterLabels = {
    'worker': 'العامل',
    'company': 'الشركة',
    'platform_admin': 'إدارة المنصة',
  };

  final int id;
  final int workerEngagementId;
  final String reporterType;
  final String category;
  final String description;
  final String status;
  final String? resolutionSummary;
  final DateTime? openedAt;
  final DateTime? underReviewAt;
  final DateTime? resolvedAt;
  final DateTime? closedAt;

  String get statusLabel => statusLabels[status] ?? 'حالة النزاع';
  String get reporterLabel => reporterLabels[reporterType] ?? 'مبلّغ';

  factory WorkerDispute.fromJson(Map<String, dynamic> json) {
    return WorkerDispute(
      id: (json['id'] as num).toInt(),
      workerEngagementId: (json['worker_engagement_id'] as num).toInt(),
      reporterType: json['reporter_type']?.toString() ?? '',
      category: json['category']?.toString() ?? '',
      description: json['description']?.toString() ?? '',
      status: json['status']?.toString() ?? '',
      resolutionSummary: _text(json['resolution_summary']),
      openedAt: _date(json['opened_at']),
      underReviewAt: _date(json['under_review_at']),
      resolvedAt: _date(json['resolved_at']),
      closedAt: _date(json['closed_at']),
    );
  }
}

class WorkerDisputePage {
  const WorkerDisputePage({
    required this.items,
    required this.total,
    required this.perPage,
    required this.currentPage,
    required this.lastPage,
  });

  final List<WorkerDispute> items;
  final int total;
  final int perPage;
  final int currentPage;
  final int lastPage;

  bool get hasMore => currentPage < lastPage;
}

class WorkerDisputeFilters {
  const WorkerDisputeFilters({
    this.status,
    this.from,
    this.to,
    this.perPage = 20,
    this.page = 1,
  });

  final String? status;
  final String? from;
  final String? to;
  final int perPage;
  final int page;

  Map<String, dynamic> toQuery() {
    return {
      if (status != null && status!.trim().isNotEmpty) 'status': status!.trim(),
      if (from != null && from!.trim().isNotEmpty) 'from': from!.trim(),
      if (to != null && to!.trim().isNotEmpty) 'to': to!.trim(),
      'per_page': perPage,
      'page': page,
    };
  }

  WorkerDisputeFilters copyWith({
    String? status,
    bool clearStatus = false,
    String? from,
    bool clearFrom = false,
    String? to,
    bool clearTo = false,
    int? perPage,
    int? page,
  }) {
    return WorkerDisputeFilters(
      status: clearStatus ? null : status ?? this.status,
      from: clearFrom ? null : from ?? this.from,
      to: clearTo ? null : to ?? this.to,
      perPage: perPage ?? this.perPage,
      page: page ?? this.page,
    );
  }
}

String? _text(dynamic value) {
  final text = value?.toString().trim() ?? '';
  if (text.isEmpty) return null;
  return text;
}

DateTime? _date(dynamic value) {
  final text = value?.toString().trim() ?? '';
  if (text.isEmpty) return null;
  return DateTime.tryParse(text);
}
