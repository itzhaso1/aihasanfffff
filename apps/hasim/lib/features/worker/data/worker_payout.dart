/// Read-only payout history from GET /worker/payouts.
class WorkerPayout {
  const WorkerPayout({
    required this.id,
    required this.workerEngagementId,
    required this.workerEarningPeriodId,
    required this.amountMinor,
    required this.currency,
    required this.status,
    this.requestedAt,
    this.processingAt,
    this.paidAt,
    this.failedAt,
    this.cancelledAt,
    this.failureReason,
  });

  static const statusLabels = {
    'pending': 'قيد الانتظار',
    'processing': 'قيد المعالجة',
    'paid': 'مدفوع',
    'failed': 'فشل',
    'cancelled': 'ملغى',
  };

  final int id;
  final int workerEngagementId;
  final int workerEarningPeriodId;
  final int amountMinor;
  final String currency;
  final String status;
  final DateTime? requestedAt;
  final DateTime? processingAt;
  final DateTime? paidAt;
  final DateTime? failedAt;
  final DateTime? cancelledAt;
  final String? failureReason;

  String get statusLabel => statusLabels[status] ?? 'حالة الدفعة';

  factory WorkerPayout.fromJson(Map<String, dynamic> json) {
    return WorkerPayout(
      id: (json['id'] as num).toInt(),
      workerEngagementId: (json['worker_engagement_id'] as num).toInt(),
      workerEarningPeriodId: (json['worker_earning_period_id'] as num).toInt(),
      amountMinor: json['amount_minor'] is num
          ? (json['amount_minor'] as num).toInt()
          : 0,
      currency: json['currency']?.toString() ?? '',
      status: json['status']?.toString() ?? '',
      requestedAt: _date(json['requested_at']),
      processingAt: _date(json['processing_at']),
      paidAt: _date(json['paid_at']),
      failedAt: _date(json['failed_at']),
      cancelledAt: _date(json['cancelled_at']),
      failureReason: _text(json['failure_reason']),
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
