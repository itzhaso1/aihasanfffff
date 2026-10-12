import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_response.dart';

void main() {
  test('ApiResponse parses success envelope and cursor', () {
    final env = ApiResponse.fromJson(
      {
        'success': true,
        'data': {'id': 1, 'name': 'x'},
        'meta': {'next_cursor': 'abc'},
        'message': null,
      },
      (d) => d as Map<String, dynamic>,
    );

    expect(env.success, isTrue);
    expect(env.nextCursor, 'abc');
    expect(env.data?['id'], 1);
  });

  test('UserModel.fromJson', () {
    final u = UserModel.fromJson({
      'id': 9,
      'name': 'أحمد',
      'email': 'a@b.c',
      'phone': null,
    });
    expect(u.name, 'أحمد');
    expect(u.id, 9);
  });

  test('UserModel.fromJson resolves a relative avatar path', () {
    final u = UserModel.fromJson({
      'id': 9,
      'name': 'أحمد',
      'avatar_url': '/storage/avatars/9/photo.jpg',
    });
    expect(u.avatarUrl, endsWith('/storage/avatars/9/photo.jpg'));
    expect(u.avatarUrl, startsWith('http'));
  });

  test('WorkspaceModel.fromJson', () {
    final w = WorkspaceModel.fromJson({
      'id': 1,
      'name': 'عيادة',
      'slug': 'clinic',
      'type': 'company',
    });
    expect(w.name, 'عيادة');
  });

  test('ConversationModel nested last_message', () {
    final c = ConversationModel.fromJson({
      'id': 10,
      'channel': 'whatsapp',
      'status': 'open',
      'unread_count': 2,
      'muted': false,
      'archived': false,
      'customer': {'id': 1, 'name': 'عميل', 'email': null, 'phone': null},
      'last_message': {
        'id': 99,
        'conversation_id': 10,
        'direction': 'inbound',
        'message_type': 'text',
        'content': 'مرحبا',
        'created_at': '2026-01-01T10:00:00Z',
        'attachments': [],
      },
      'last_message_at': '2026-01-01T10:00:00Z',
    });
    expect(c.unreadCount, 2);
    expect(c.lastMessage?.content, 'مرحبا');
    expect(c.title, 'عميل');
    expect(c.channelLabel, 'واتساب');
  });

  test('MessageModel attachments', () {
    final m = MessageModel.fromJson({
      'id': 1,
      'conversation_id': 2,
      'direction': 'outbound',
      'message_type': 'text',
      'content': 'hi',
      'created_at': '2026-01-01T00:00:00Z',
      'attachments': [
        {
          'id': 5,
          'kind': 'image',
          'original_name': 'a.png',
          'mime_type': 'image/png',
          'size_bytes': 10,
          'preview_url': 'https://example.com/a.png',
          'download_url': 'https://example.com/a.png',
        }
      ],
    });
    expect(m.isOutbound, isTrue);
    expect(m.attachments.first.kind, 'image');
    expect(m.attachments.first.displayUrl, isNotNull);
  });

}
