import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/utils/relative_time.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_composer.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_message_bubble.dart';
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

  testWidgets('composer matches the mobile voice and message layout', (
    tester,
  ) async {
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

    expect(find.text('الرسالة'), findsOneWidget);
    expect(find.byIcon(Icons.attach_file_rounded), findsOneWidget);
    expect(find.byIcon(Icons.camera_alt_outlined), findsOneWidget);
    expect(find.byIcon(Icons.emoji_emotions_outlined), findsOneWidget);
    expect(find.byIcon(Icons.mic_none_rounded), findsOneWidget);
    expect(find.byIcon(Icons.send_rounded), findsNothing);

    final actionSize = tester.getSize(
      find.byKey(const Key('chat-send-button')),
    );
    expect(actionSize.width, 44);
    expect(actionSize.height, 44);

    final actionLeft = tester
        .getTopLeft(find.byKey(const Key('chat-send-button')))
        .dx;
    final fieldLeft = tester.getTopLeft(find.byType(TextField)).dx;
    final emojiLeft = tester
        .getTopLeft(find.byKey(const Key('chat-emoji-button')))
        .dx;
    expect(actionLeft, lessThan(fieldLeft));
    expect(emojiLeft, greaterThan(fieldLeft));

    await tester.enterText(find.byType(TextField), 'هلا');
    await tester.pump();
    expect(find.byIcon(Icons.send_rounded), findsOneWidget);
  });

  testWidgets('composer mic starts voice recording and can cancel', (
    tester,
  ) async {
    var recorded = 0;
    var cancelled = 0;
    var paused = 0;
    await tester.pumpWidget(
      wrap(
        ChatComposer(
          controller: TextEditingController(),
          sendEnabled: true,
          onSend: () {},
          onAttach: () {},
          onEmoji: () {},
          onRecord: () => recorded++,
          onTogglePause: () => paused++,
        ),
      ),
    );

    await tester.tap(find.byKey(const Key('chat-send-button')));
    await tester.pump();
    expect(recorded, 1);

    await tester.pumpWidget(
      wrap(
        ChatComposer(
          controller: TextEditingController(),
          sendEnabled: true,
          recording: true,
          recordElapsed: const Duration(seconds: 3),
          onSend: () {},
          onAttach: () {},
          onEmoji: () {},
          onCancelRecord: () => cancelled++,
          onConfirmRecord: () {},
          onTogglePause: () => paused++,
        ),
      ),
    );
    await tester.pump();
    expect(find.byKey(const Key('chat-voice-timer')), findsOneWidget);
    expect(find.text('إيقاف مؤقت'), findsOneWidget);
    await tester.tap(find.byKey(const Key('chat-voice-pause')));
    expect(paused, 1);
    await tester.tap(find.byKey(const Key('chat-voice-cancel')));
    await tester.pump();
    expect(cancelled, 1);
  });

  testWidgets('composer camera button uses the camera callback', (
    tester,
  ) async {
    var cameras = 0;
    await tester.pumpWidget(
      wrap(
        ChatComposer(
          controller: TextEditingController(),
          sendEnabled: true,
          onSend: () {},
          onAttach: () {},
          onCamera: () => cameras++,
          onEmoji: () {},
        ),
      ),
    );

    await tester.tap(find.byKey(const Key('chat-camera-button')));
    expect(cameras, 1);
  });

  testWidgets('recording composer fits narrow phone widths', (tester) async {
    tester.view.physicalSize = const Size(320, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      wrap(
        ChatComposer(
          controller: TextEditingController(),
          sendEnabled: true,
          recording: true,
          recordElapsed: const Duration(seconds: 2),
          onSend: () {},
          onAttach: () {},
          onEmoji: () {},
          onCancelRecord: () {},
          onConfirmRecord: () {},
          onTogglePause: () {},
        ),
      ),
    );
    await tester.pump();

    expect(tester.takeException(), isNull);
    expect(find.byKey(const Key('chat-voice-pause')), findsOneWidget);
  });

  testWidgets('short text bubble stays compact with inline time row', (
    tester,
  ) async {
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
      find
          .descendant(
            of: find.byType(ChatMessageBubble),
            matching: find.byType(DecoratedBox),
          )
          .first,
    );
    expect(bubble.height, lessThan(40));
  });

  testWidgets(
    'compact chat puts outbound on the left and inbound on the right',
    (tester) async {
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
    },
  );

  testWidgets(
    'desktop chat keeps outbound on the left and inbound on the right',
    (tester) async {
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
        find
            .descendant(
              of: find.widgetWithText(ChatMessageBubble, 'تفضل'),
              matching: find.byType(DecoratedBox),
            )
            .first,
      );
      final staffDecoration = staffBox.decoration as BoxDecoration;
      expect(staffDecoration.color, HasimTokens.outgoingBubbleLight);
    },
  );

  testWidgets(
    'multi-line suggestion fills the composer and does not send',
    (tester) async {
      final controller = TextEditingController();
      var sent = 0;
      await tester.pumpWidget(
        wrap(
          ChatComposer(
            controller: controller,
            sendEnabled: true,
            onSend: () => sent++,
            onAttach: () {},
            onEmoji: () {},
          ),
        ),
      );

      await tester.enterText(find.byType(TextField), 'أهلاً بك\nكيف أقدر أساعدك؟');
      await tester.pump();

      expect(sent, 0);
      expect(controller.text.contains('\n'), isFalse);
      expect(controller.text, 'أهلاً بككيف أقدر أساعدك؟');
    },
  );

  testWidgets('enter on the current draft still sends', (tester) async {
    final controller = TextEditingController(text: 'هلا');
    var sent = 0;
    await tester.pumpWidget(
      wrap(
        ChatComposer(
          controller: controller,
          sendEnabled: true,
          onSend: () => sent++,
          onAttach: () {},
          onEmoji: () {},
        ),
      ),
    );

    await tester.enterText(find.byType(TextField), 'هلا\n');
    await tester.pump();

    expect(sent, 1);
  });

  testWidgets(
    'AI suggestion fills the composer only and send uses the edited suggestion',
    (tester) async {
      final controller = TextEditingController(text: 'مسودة سابقة');
      var sent = '';
      const inbound = MessageModel(
        id: 4,
        conversationId: 1,
        direction: 'inbound',
        messageType: 'text',
        content: 'كم السعر؟',
      );

      await tester.pumpWidget(
        wrap(
          SizedBox(
            width: 390,
            height: 500,
            child: Column(
              children: [
                const ChatMessageBubble(message: inbound, maxWidth: 300),
                ChatComposer(
                  controller: controller,
                  sendEnabled: true,
                  onSend: () => sent = controller.text,
                  onAttach: () {},
                  onEmoji: () {},
                ),
              ],
            ),
          ),
        ),
      );

      expect(find.text('كم السعر؟'), findsOneWidget);
      expect(find.text('مسودة سابقة'), findsOneWidget);

      controller.text = 'المنتج سعره 10 ريال';
      await tester.pump();
      expect(find.text('المنتج سعره 10 ريال'), findsOneWidget);
      expect(find.text('Since the user asked'), findsNothing);
      expect(find.text('مسودة سابقة'), findsNothing);
      expect(find.text('كم السعر؟'), findsOneWidget);

      await tester.enterText(find.byType(TextField), 'المنتج سعره 10 ريال إذا حاب نطلب');
      await tester.pump();
      await tester.tap(find.byKey(const Key('chat-send-button')));
      await tester.pump();

      expect(sent, 'المنتج سعره 10 ريال إذا حاب نطلب');
      expect(sent.contains('Since the user asked'), isFalse);
      expect(find.text('كم السعر؟'), findsOneWidget);
    },
  );

  testWidgets('AI outbound bubble shows the final reply without composer mixing', (
    tester,
  ) async {
    const reply = MessageModel(
      id: 9,
      conversationId: 1,
      direction: 'outbound',
      messageType: 'text',
      content: 'تقدر تقول لي وش تحتاج؟',
      aiGenerated: true,
    );

    await tester.pumpWidget(
      wrap(
        SizedBox(
          width: 390,
          height: 500,
          child: Column(
            children: [
              const ChatMessageBubble(message: reply, maxWidth: 300),
              ChatComposer(
                controller: TextEditingController(),
                sendEnabled: true,
                onSend: () {},
                onAttach: () {},
                onEmoji: () {},
              ),
            ],
          ),
        ),
      ),
    );

    expect(find.text('تقدر تقول لي وش تحتاج؟'), findsOneWidget);
    expect(find.text('Since the user asked'), findsNothing);
    expect(find.text('SYSTEM:'), findsNothing);
    expect(find.textContaining('suggestion'), findsNothing);
  });

  testWidgets('handoff note is an internal chip without delivery ticks', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        ChatMessageBubble(
          message: MessageModel(
            id: 9,
            conversationId: 1,
            direction: 'internal_note',
            messageType: 'system',
            content:
                'تم تحويل المحادثة للفريق، وسيتم الرد عليك من أحد أعضاء الفريق.',
            createdAt: DateTime(2026, 1, 1, 1, 5),
          ),
        ),
      ),
    );

    expect(find.byKey(const Key('chat-internal-note')), findsOneWidget);
    expect(find.text('ملاحظة داخلية'), findsOneWidget);
    expect(
      find.text('تم تحويل المحادثة للفريق، وسيتم الرد عليك من أحد أعضاء الفريق.'),
      findsOneWidget,
    );
    expect(find.byIcon(Icons.done_all_rounded), findsNothing);
  });
}
