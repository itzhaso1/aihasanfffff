/// Engagement-scoped earning periods from GET /worker/engagements/{id}/earnings.
class WorkerEarningPeriod {
  const WorkerEarningPeriod({
    required this.id,
    required this.workerEngagementId,
    required this.status,
    required this.totalsMinor,
    required this.lines,
    this.startsAt,
    this.endsAt,
  });

  static const statusLabels = {
    'pending_approval': 'بانتظار الاعتماد',
    'approved': 'معتمد',
    'disputed': 'محل اعتراض',
    'paid': 'مدفوع',
  };

  final int id;
  final int workerEngagementId;
  final String status;
  final String? startsAt;
  final String? endsAt;
  final Map<String, int> totalsMinor;
  final List<WorkerEarningLine> lines;

  bool get isDraft => status == 'draft';
  bool get canDispute => status == 'pending_approval' || status == 'approved';

  String get statusLabel => statusLabels[status] ?? 'حالة الأرباح';

  factory WorkerEarningPeriod.fromJson(Map<String, dynamic> json) {
    final rawLines = json['lines'];
    return WorkerEarningPeriod(
      id: (json['id'] as num).toInt(),
      workerEngagementId: (json['worker_engagement_id'] as num).toInt(),
      status: json['status']?.toString() ?? '',
      startsAt: _text(json['starts_at']),
      endsAt: _text(json['ends_at']),
      totalsMinor: _totals(json['totals_minor']),
      lines: rawLines is List
          ? [
              for (final item in rawLines)
                if (item is Map)
                  WorkerEarningLine.fromJson(Map<String, dynamic>.from(item)),
            ]
          : const [],
    );
  }
}

class WorkerEarningLine {
  const WorkerEarningLine({
    required this.id,
    required this.lineType,
    required this.sourceType,
    required this.amountMinor,
    required this.currency,
    this.sourceId,
    this.description,
  });

  static const lineTypeLabels = {
    'base': 'أساسي',
    'commission': 'عمولة',
    'bonus': 'مكافأة',
    'adjustment': 'تسوية',
    'deduction': 'خصم',
  };

  static const sourceTypeLabels = {
    'engagement_terms': 'شروط الارتباط',
    'work_session': 'جلسة عمل',
    'activity_event': 'حدث نشاط',
    'adjustment': 'تسوية',
  };

  final int id;
  final String lineType;
  final String sourceType;
  final int? sourceId;
  final int amountMinor;
  final String currency;
  final String? description;

  String get lineTypeLabel => lineTypeLabels[lineType] ?? 'بند';
  String get sourceTypeLabel => sourceTypeLabels[sourceType] ?? 'مصدر';

  factory WorkerEarningLine.fromJson(Map<String, dynamic> json) {
    return WorkerEarningLine(
      id: (json['id'] as num).toInt(),
      lineType: json['line_type']?.toString() ?? '',
      sourceType: json['source_type']?.toString() ?? '',
      sourceId: json['source_id'] is num
          ? (json['source_id'] as num).toInt()
          : null,
      amountMinor: json['amount_minor'] is num
          ? (json['amount_minor'] as num).toInt()
          : 0,
      currency: json['currency']?.toString() ?? '',
      description: _text(json['description']),
    );
  }
}

/// Formats integer minor units without floating-point money math.
String formatWorkerMoney(int amountMinor, String currency) {
  final negative = amountMinor < 0;
  final absolute = amountMinor.abs();
  final whole = absolute ~/ 100;
  final fraction = (absolute % 100).toString().padLeft(2, '0');
  final code = currency.trim();
  final amount = '${negative ? '-' : ''}$whole.$fraction';
  return code.isEmpty ? amount : '$amount $code';
}

Map<String, int> _totals(dynamic raw) {
  if (raw is! Map) return const {};
  return {
    for (final entry in raw.entries)
      if (entry.value is num)
        entry.key.toString(): (entry.value as num).toInt(),
  };
}

String? _text(dynamic value) {
  final text = value?.toString().trim() ?? '';
  if (text.isEmpty) return null;
  return text;
}
