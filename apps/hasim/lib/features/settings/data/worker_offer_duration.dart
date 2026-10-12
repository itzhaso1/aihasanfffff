class WorkerOfferDuration {
  const WorkerOfferDuration({required this.value, required this.unit});

  final int value;
  final String unit;

  static const day = WorkerOfferDuration(value: 1, unit: 'day');
  static const week = WorkerOfferDuration(value: 1, unit: 'week');
  static const month = WorkerOfferDuration(value: 1, unit: 'month');

  static WorkerOfferDuration? fromPreset(String preset) {
    return switch (preset) {
      'day' => day,
      'week' => week,
      'month' => month,
      _ => null,
    };
  }
}

DateTime? calculateWorkerOfferEndDate({
  required String startsAt,
  required int value,
  required String unit,
}) {
  if (value < 1) return null;

  final parts = startsAt.split('-').map(int.tryParse).toList();
  if (parts.length != 3 || parts.any((part) => part == null)) return null;

  final start = DateTime(parts[0]!, parts[1]!, parts[2]!);
  if (start.year != parts[0] ||
      start.month != parts[1] ||
      start.day != parts[2]) {
    return null;
  }

  return switch (unit) {
    'day' => start.add(Duration(days: value)),
    'week' => start.add(Duration(days: value * 7)),
    'month' => _addMonthsNoOverflow(start, value),
    _ => null,
  };
}

String formatWorkerOfferDate(DateTime date) {
  final year = date.year.toString().padLeft(4, '0');
  final month = date.month.toString().padLeft(2, '0');
  final day = date.day.toString().padLeft(2, '0');
  return '$year-$month-$day';
}

DateTime _addMonthsNoOverflow(DateTime start, int months) {
  final target = DateTime(start.year, start.month + months, 1);
  final lastDay = DateTime(target.year, target.month + 1, 0).day;

  return DateTime(
    target.year,
    target.month,
    start.day < lastDay ? start.day : lastDay,
  );
}
