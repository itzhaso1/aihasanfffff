import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_message_bubble.dart';
import 'support/hasim_widget_harness.dart';

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await initHasimTestPrefs();
  });

  testWidgets('ChatMessageBubble renders interactive location card cleanly', (
    tester,
  ) async {
    final msg = MessageModel.fromJson({
      'id': 501,
      'conversation_id': 99,
      'direction': 'inbound',
      'message_type': 'location',
      'content': '📍 موقع',
      'created_at': '2026-11-28T01:00:00Z',
      'location': {
        'id': 12,
        'latitude': 24.7136,
        'longitude': 46.6753,
        'accuracy_meters': 14,
        'source': 'gps_adjusted',
        'source_label': 'GPS + تعديل يدوي',
        'platform': 'whatsapp',
        'verification_status': 'confirmed',
        'verification_label': 'موقع مؤكد',
        'formatted_address': 'الرياض، العليا، طريق الملك فهد',
        'city': 'الرياض',
        'district': 'العليا',
        'customer_notes': 'الدور الثاني',
        'google_maps_url': 'https://www.google.com/maps?q=24.7136000,46.6753000',
      },
    });

    await tester.pumpWidget(
      hasimTestApp(
        Scaffold(
          body: Center(
            child: ChatMessageBubble(message: msg),
          ),
        ),
      ),
    );

    expect(find.byKey(const Key('chat-message-location-card')), findsOneWidget);
    expect(find.text('موقع التوصيل'), findsOneWidget);
    expect(find.text('موقع مؤكد'), findsOneWidget);
    expect(find.text('الرياض، العليا، طريق الملك فهد'), findsOneWidget);
    expect(find.text('ملاحظة: الدور الثاني'), findsOneWidget);
    expect(find.text('فتح في الخريطة'), findsOneWidget);
  });

  test('InboxLocalStore persists and restores MessageModel location_json', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = InboxLocalStore(db);

    final conv = ConversationModel.fromJson({
      'id': 77,
      'channel': 'telegram',
      'status': 'open',
      'external_id': 'tg:77',
      'ai_enabled': true,
      'unread_count': 0,
      'updated_at': '2026-11-28T01:00:00Z',
    });
    await store.upsertConversations(1, [conv]);

    final locMsg = MessageModel.fromJson({
      'id': 888,
      'conversation_id': 77,
      'direction': 'inbound',
      'message_type': 'location',
      'content': '📍 موقع',
      'created_at': '2026-11-28T01:01:00Z',
      'location': {
        'id': 33,
        'latitude': 21.4858,
        'longitude': 39.1925,
        'accuracy_meters': 22,
        'source': 'telegram_location',
        'source_label': 'مشاركة موقع تيليجرام',
        'platform': 'telegram',
        'verification_status': 'confirmed',
        'verification_label': 'موقع مؤكد',
        'formatted_address': 'جدة، أبحر الشمالية',
        'city': 'جدة',
        'district': 'أبحر الشمالية',
      },
    });

    await store.upsertMessages(1, 77, [locMsg]);
    final restored = await store.getMessages(1, 77);
    expect(restored.length, 1);
    expect(restored.first.messageType, 'location');
    expect(restored.first.location?.formattedAddress, 'جدة، أبحر الشمالية');
    expect(restored.first.location?.accuracyMeters, 22);
  });
}
