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
