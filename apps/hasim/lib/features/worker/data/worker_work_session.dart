/// Work-session history returned by GET /worker/engagements/{id}/session.
class WorkerWorkSession {
  const WorkerWorkSession({
    required this.id,
    required this.workerEngagementId,
    required this.status,
    required this.segments,
    this.startedAt,
    this.endedAt,
    this.durationSeconds,
  });

  static const statusLabels = {
    'started': 'جارية',
    'ended': 'منتهية',
  };

  final int id;
  final int workerEngagementId;
  final String status;
  final DateTime? startedAt;
  final DateTime? endedAt;
  final int? durationSeconds;
  final List<WorkerWorkSessionSegment> segments;

  bool get isStarted => status == 'started';
  bool get isEnded => status == 'ended';

  String get statusLabel => statusLabels[status] ?? 'حالة الجلسة';

  WorkerWorkSessionSegment? get openSegment {
    for (final segment in segments) {
      if (segment.isOpen) return segment;
    }
    return null;
  }

  /// Display-only elapsed seconds. Never send this value to the API.
  int displayElapsedSeconds(DateTime now) {
    if (durationSeconds != null) return durationSeconds!;
    final start = startedAt;
    if (start == null) return 0;
    final end = endedAt ?? now;
    final seconds = end.difference(start).inSeconds;
    return seconds < 0 ? 0 : seconds;
  }

  factory WorkerWorkSession.fromJson(Map<String, dynamic> json) {
    final rawSegments = json['segments'];
    return WorkerWorkSession(
      id: (json['id'] as num).toInt(),
      workerEngagementId: (json['worker_engagement_id'] as num).toInt(),
      status: json['status']?.toString() ?? '',
      startedAt: _date(json['started_at']),
      endedAt: _date(json['ended_at']),
      durationSeconds: json['duration_seconds'] is num
          ? (json['duration_seconds'] as num).toInt()
          : null,
      segments: rawSegments is List
          ? [
              for (final item in rawSegments)
                if (item is Map)
                  WorkerWorkSessionSegment.fromJson(
                    Map<String, dynamic>.from(item),
                  ),
            ]
          : const [],
    );
  }
}

class WorkerWorkSessionSegment {
  const WorkerWorkSessionSegment({
    required this.id,
    required this.segmentType,
    this.startedAt,
    this.endedAt,
  });

  static const typeLabels = {
    'active': 'عمل',
    'break': 'استراحة',
    'idle': 'خمول',
  };

  final int id;
  final String segmentType;
  final DateTime? startedAt;
  final DateTime? endedAt;

  bool get isOpen => endedAt == null;
  bool get isActive => segmentType == 'active';
  bool get isBreak => segmentType == 'break';
  bool get isIdle => segmentType == 'idle';

  String get typeLabel => typeLabels[segmentType] ?? 'قطعة';

  factory WorkerWorkSessionSegment.fromJson(Map<String, dynamic> json) {
    return WorkerWorkSessionSegment(
      id: (json['id'] as num).toInt(),
      segmentType: json['segment_type']?.toString() ?? '',
      startedAt: _date(json['started_at']),
      endedAt: _date(json['ended_at']),
    );
  }
}

String formatWorkSessionDuration(int seconds) {
  final safe = seconds < 0 ? 0 : seconds;
  final hours = (safe ~/ 3600).toString().padLeft(2, '0');
  final minutes = ((safe % 3600) ~/ 60).toString().padLeft(2, '0');
  final rest = (safe % 60).toString().padLeft(2, '0');
  return '$hours:$minutes:$rest';
}

DateTime? _date(dynamic value) {
  final text = value?.toString().trim() ?? '';
  if (text.isEmpty) return null;
  return DateTime.tryParse(text);
}
