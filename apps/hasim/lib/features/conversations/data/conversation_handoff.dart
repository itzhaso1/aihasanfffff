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

  static bool canTakeover(ConversationModel conversation) {
    if (!isOpen(conversation)) return false;
    final isHumanNeeded =
        conversation.handoffStatus == 'requested' ||
        conversation.handoffStatus == 'queued';
    final isOwnedByHuman =
        conversation.handoffStatus == 'assigned' ||
        conversation.handoffStatus == 'active';
    return isHumanNeeded || conversation.isAiReplying || isOwnedByHuman;
  }

  static bool isOwnedByAnotherHuman(
    ConversationModel conversation, {
    int? currentUserId,
  }) =>
      const ['assigned', 'active'].contains(conversation.handoffStatus) &&
      conversation.assignedUserId != null &&
      (currentUserId == null || conversation.assignedUserId != currentUserId);

  static bool canTakeoverForActor(
    ConversationModel conversation, {
    required bool isWorker,
    int? currentUserId,
  }) {
    if (!canTakeover(conversation)) return false;
    if (isWorker && !conversation.canReply) return false;
    if (isWorker &&
        isOwnedByAnotherHuman(conversation, currentUserId: currentUserId)) {
      return false;
    }
    if (conversation.assignedUserId != null &&
        conversation.assignedUserId == currentUserId &&
        !conversation.aiEnabled &&
        conversation.handoffStatus == 'active') {
      return false;
    }
    return true;
  }

  static bool canResolve(ConversationModel conversation) =>
      isOpen(conversation) &&
      const [
        'requested',
        'queued',
        'assigned',
        'active',
      ].contains(conversation.handoffStatus);

  static bool canResumeAi(ConversationModel conversation) =>
      isOpen(conversation) && !conversation.isAiReplying;

  static bool canResumeAiForActor(
    ConversationModel conversation, {
    required bool isWorker,
    int? currentUserId,
  }) {
    if (!canResumeAi(conversation)) return false;
    if (isWorker && !conversation.canReply) return false;
    if (isWorker &&
        isOwnedByAnotherHuman(conversation, currentUserId: currentUserId)) {
      return false;
    }
    return true;
  }

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
