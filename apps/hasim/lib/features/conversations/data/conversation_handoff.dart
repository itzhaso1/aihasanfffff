import 'package:hasim/core/models/models.dart';

/// حالات وأسباب التحويل كما في Laravel — بدون قيم مخترعة.
abstract final class ConversationHandoffValues {
  static const requestableStatuses = <String>['none', 'resolved'];
  static const assignableStatuses = <String>['none', 'requested', 'queued'];

  static const reasons = <String>[
    'customer_request',
    'payment_issue',
    'refund_request',
    'cancel_request',
    'digital_delivery_failure',
    'high_value_lead',
    'ai_uncertainty',
    'repeated_ai_failure',
    'sensitive_support',
    'agent_requested',
    'system_trigger',
  ];

  static const defaultReason = 'agent_requested';

  static bool isAllowedReason(String value) => reasons.contains(value);

  static bool isOpen(ConversationModel conversation) =>
      conversation.status == 'open' && !conversation.archived;

  static bool canRequest(ConversationModel conversation) =>
      isOpen(conversation) &&
      requestableStatuses.contains(conversation.handoffStatus);

  static bool canAssign(ConversationModel conversation) =>
      isOpen(conversation) &&
      assignableStatuses.contains(conversation.handoffStatus);

  static bool canTakeover(ConversationModel conversation) =>
      isOpen(conversation);

  static bool canResolve(ConversationModel conversation) =>
      isOpen(conversation) &&
      const [
        'requested',
        'queued',
        'assigned',
        'active',
      ].contains(conversation.handoffStatus);

  static bool canResumeAi(ConversationModel conversation) =>
      isOpen(conversation) &&
      !conversation.isAiReplying &&
      !conversation.isHumanHandoffActive;

  static bool canMoveToTeamReview(ConversationModel conversation) =>
      isOpen(conversation) &&
      conversation.isAiReplying &&
      conversation.handoffStatus == 'none';

  static bool canEndHandoff(ConversationModel conversation) =>
      canResolve(conversation);

  static bool isAiReplying(ConversationModel conversation) =>
      conversation.isAiReplying;

  static String reasonLabel(String value) => switch (value) {
    'customer_request' => 'طلب العميل',
    'payment_issue' => 'مشكلة دفع',
    'refund_request' => 'طلب استرداد',
    'cancel_request' => 'طلب إلغاء',
    'digital_delivery_failure' => 'فشل تسليم رقمي',
    'high_value_lead' => 'عميل ذو قيمة',
    'ai_uncertainty' => 'عدم تأكد الذكاء الاصطناعي',
    'repeated_ai_failure' => 'تكرار فشل الذكاء الاصطناعي',
    'sensitive_support' => 'دعم حساس',
    'system_trigger' => 'محفّز النظام',
    _ => 'طلب الموظف',
  };

  static String statusLabel(String value) => switch (value) {
    'requested' => 'مطلوب',
    'queued' => 'في الانتظار',
    'assigned' => 'معيَّن',
    'active' => 'نشط',
    'resolved' => 'منتهٍ',
    _ => 'بدون تحويل',
  };
}
