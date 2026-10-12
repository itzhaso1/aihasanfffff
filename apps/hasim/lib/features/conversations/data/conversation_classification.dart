/// قيم تصنيف المحادثة كما في Laravel enums — لا تُوسَّع من العميل.
abstract final class ConversationClassificationValues {
  static const priorities = <String>['important', 'normal', 'not_important'];
  static const payments = <String>['unpaid', 'paid'];
  static const reviews = <String>['needs_review', 'no_review_needed'];

  static const defaultPriority = 'normal';
  static const defaultPayment = 'unpaid';
  static const defaultReview = 'no_review_needed';

  static bool isAllowedPriority(String value) => priorities.contains(value);
  static bool isAllowedPayment(String value) => payments.contains(value);
  static bool isAllowedReview(String value) => reviews.contains(value);

  static String priorityLabel(String value) => switch (value) {
    'important' => 'مهم',
    'not_important' => 'غير مهم',
    _ => 'عادي',
  };

  static String paymentLabel(String value) =>
      value == 'paid' ? 'مدفوع' : 'غير مدفوع';

  static String reviewLabel(String value) =>
      value == 'needs_review' ? 'يحتاج مراجعة' : 'لا يحتاج مراجعة';
}
