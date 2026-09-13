/// Time-based Arabic greeting helper.
String greetingFor([DateTime? now]) {
  final hour = (now ?? DateTime.now()).hour;
  if (hour >= 5 && hour < 12) return 'صباح الخير';
  if (hour >= 12 && hour < 18) return 'مساء الخير';
  return 'مرحباً';
}
