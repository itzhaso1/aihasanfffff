import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/models/models.dart';

void main() {
  test('conversation json keeps classification dimensions separate', () {
    final model = ConversationModel.fromJson({
      'id': 4,
      'channel': 'whatsapp',
      'status': 'open',
      'customer_priority': 'important',
      'payment_status': 'paid',
      'review_status': 'needs_review',
      'classification_source': 'manual',
    });

    expect(model.customerPriority, 'important');
    expect(model.paymentStatus, 'paid');
    expect(model.reviewStatus, 'needs_review');
    expect(model.classificationSource, 'manual');
    expect(model.toCacheJson()['customer_priority'], 'important');
    expect(model.toCacheJson()['payment_status'], 'paid');
  });

  test('assigned user name is parsed from the mobile resource', () {
    final model = ConversationModel.fromJson({
      'id': 7,
      'channel': 'web',
      'status': 'open',
      'handoff_status': 'assigned',
      'assigned_user': {'id': 9, 'name': 'سارة'},
    });
    expect(model.assignedUserId, 9);
    expect(model.assignedUserName, 'سارة');
  });

  test('server writes keep unread mute archive and last message', () {
    final local = ConversationModel(
      id: 7,
      channel: 'whatsapp',
      status: 'open',
      unreadCount: 4,
      muted: true,
      archived: false,
      canReply: true,
      lastMessage: const MessageModel(
        id: 11,
        conversationId: 7,
        direction: 'inbound',
        messageType: 'text',
        content: 'مرحبا',
      ),
      customer: const CustomerModel(id: 2, name: 'أحمد'),
    );
    final remote = ConversationModel.fromJson({
      'id': 7,
      'channel': 'whatsapp',
      'status': 'open',
      'customer_priority': 'important',
      'payment_status': 'paid',
      'review_status': 'needs_review',
      'handoff_status': 'queued',
      'assigned_user': {'id': 9, 'name': 'سارة'},
    });
    final merged = local.applyingServerWrites(remote);
    expect(merged.customerPriority, 'important');
    expect(merged.handoffStatus, 'queued');
    expect(merged.assignedUserName, 'سارة');
    expect(merged.unreadCount, 4);
    expect(merged.muted, isTrue);
    expect(merged.archived, isFalse);
    expect(merged.canReply, isTrue);
    expect(merged.lastMessage?.content, 'مرحبا');
  });

  test('local inbox overlay keeps classification and handoff', () {
    final remote = ConversationModel.fromJson({
      'id': 7,
      'channel': 'whatsapp',
      'status': 'open',
      'customer_priority': 'important',
      'handoff_status': 'queued',
      'assigned_user': {'id': 9, 'name': 'سارة'},
      'unread_count': 4,
    });
    final local = ConversationModel(
      id: 7,
      channel: 'whatsapp',
      status: 'open',
      unreadCount: 0,
      muted: true,
    );
    final merged = remote.overlayingLocalInbox(local);
    expect(merged.customerPriority, 'important');
    expect(merged.handoffStatus, 'queued');
    expect(merged.assignedUserName, 'سارة');
    expect(merged.unreadCount, 0);
    expect(merged.muted, isTrue);
  });
}
