import 'package:intl/intl.dart';

/// Clock-style inbox time (matches the reference conversation list).
String inboxListTimeAr(DateTime? value, {DateTime? now}) {
  if (value == null) return '';
  final n = now ?? DateTime.now();
  final local = value.toLocal();
  final sameDay = local.year == n.year && local.month == n.month && local.day == n.day;
  if (sameDay) {
    return DateFormat('HH:mm').format(local);
  }
  return relativeTimeAr(value, now: n);
}

/// Arabic relative time labels for list rows.
String relativeTimeAr(DateTime? value, {DateTime? now}) {
  if (value == null) return '';
  final n = now ?? DateTime.now();
  final local = value.toLocal();
  var diff = n.difference(local);
  if (diff.isNegative) diff = Duration.zero;

  if (diff.inSeconds < 45) return 'الآن';
  if (diff.inMinutes < 60) return 'منذ ${diff.inMinutes} د';
  if (diff.inHours < 24) return 'منذ ${diff.inHours} س';
  if (diff.inDays < 7) return 'منذ ${diff.inDays} ي';
  if (diff.inDays < 30) return 'منذ ${(diff.inDays / 7).floor()} أ';
  final months = (diff.inDays / 30).floor();
  if (months < 12) return 'منذ $months ش';
  return 'منذ ${(months / 12).floor()} سنة';
}
