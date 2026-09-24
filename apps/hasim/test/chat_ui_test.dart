import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/utils/relative_time.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_composer.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_message_bubble.dart';
import 'package:hasim/l10n/app_localizations.dart';

import 'support/hasim_widget_harness.dart';

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await initHasimTestPrefs();
  });

  Widget wrap(Widget child) => hasimTestApp(child);

  test('inboxListTimeAr uses clock time for the same day', () {
    final now = DateTime(2026, 2, 2, 14, 22);
    expect(inboxListTimeAr(now, now: now), '14:22');
  });

  testWidgets('composer keeps a 40px send button with attach and emoji', (tester) async {
    await tester.pumpWidget(
      wrap(
        ChatComposer(
          controller: TextEditingController(),
          sendEnabled: true,
          onSend: () {},
          onAttach: () {},
          onEmoji: () {},
        ),
      ),
    );

    expect(find.text('اكتب رسالة...'), findsOneWidget);
    expect(find.byIcon(Icons.attach_file_rounded), findsOneWidget);
    expect(find.byIcon(Icons.emoji_emotions_outlined), findsOneWidget);
    expect(find.byIcon(Icons.mic_none_rounded), findsOneWidget);
    expect(find.byIcon(Icons.send_rounded), findsNothing);

    final actionSize = tester.getSize(find.byKey(const Key('chat-send-button')));
    expect(actionSize.width, 40);
    expect(actionSize.height, 40);

    final actionLeft = tester.getTopLeft(find.byKey(const Key('chat-send-button'))).dx;
    final fieldLeft = tester.getTopLeft(find.byType(TextField)).dx;
    final emojiLeft = tester.getTopLeft(find.byKey(const Key('chat-emoji-button'))).dx;
    expect(actionLeft, lessThan(fieldLeft));
    expect(emojiLeft, greaterThan(fieldLeft));

    await tester.enterText(find.byType(TextField), 'هلا');
    await tester.pump();
    expect(find.byIcon(Icons.send_rounded), findsOneWidget);
  });

  testWidgets('short text bubble stays compact with inline time row', (tester) async {
    await tester.pumpWidget(
      wrap(
        MediaQuery(
          data: const MediaQueryData(size: Size(390, 400)),
          child: ChatMessageBubble(
            message: MessageModel(
              id: 1,
              conversationId: 1,
              direction: 'outbound',
              messageType: 'text',
              content: 'هلا',
              createdAt: DateTime(2026, 1, 1, 5, 20),
            ),
            maxWidth: 280,
          ),
        ),
      ),
    );

    final bubble = tester.getRect(
      find.descendant(
        of: find.byType(ChatMessageBubble),
        matching: find.byType(DecoratedBox),
      ).first,
    );
    expect(bubble.height, lessThan(40));
  });

  testWidgets('compact chat puts outbound on the left and inbound on the right', (tester) async {
    const outbound = MessageModel(
      id: 1,
      conversationId: 1,
      direction: 'outbound',
      messageType: 'text',
      content: 'مرسلة',
    );
    const inbound = MessageModel(
      id: 2,
      conversationId: 1,
      direction: 'inbound',
      messageType: 'text',
      content: 'واردة',
    );

    await tester.pumpWidget(
      wrap(
        MediaQuery(
          data: const MediaQueryData(size: Size(390, 400)),
          child: const SizedBox(
            width: 390,
            height: 400,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                ChatMessageBubble(message: outbound, maxWidth: 300),
                ChatMessageBubble(message: inbound, maxWidth: 300),
              ],
            ),
          ),
        ),
      ),
    );

    final sent = tester.getRect(find.text('مرسلة'));
    final received = tester.getRect(find.text('واردة'));
    expect(sent.center.dx, lessThan(received.center.dx));
  });

  testWidgets('desktop chat keeps outbound on the left and inbound on the right', (tester) async {
    const outbound = MessageModel(
      id: 1,
      conversationId: 1,
      direction: 'outbound',
      messageType: 'text',
      content: 'تفضل',
    );
    const inbound = MessageModel(
      id: 2,
      conversationId: 1,
      direction: 'inbound',
      messageType: 'text',
      content: 'هلا',
    );

    await tester.pumpWidget(
      wrap(
        const SizedBox(
          width: 800,
          height: 400,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              ChatMessageBubble(message: outbound, maxWidth: 400),
              ChatMessageBubble(message: inbound, maxWidth: 400),
            ],
          ),
        ),
      ),
    );

    final staff = tester.getRect(find.text('تفضل'));
    final customer = tester.getRect(find.text('هلا'));
    expect(staff.center.dx, lessThan(customer.center.dx));

    final staffBox = tester.widget<DecoratedBox>(
      find.descendant(of: find.widgetWithText(ChatMessageBubble, 'تفضل'), matching: find.byType(DecoratedBox)).first,
    );
    final staffDecoration = staffBox.decoration as BoxDecoration;
    expect(staffDecoration.color, HasimTokens.outgoingBubbleLight);
  });
}
