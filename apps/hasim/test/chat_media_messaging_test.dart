import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/media_auth_headers.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_image_grid.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_media_preview_dialog.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_message_bubble.dart';
import 'package:hasim/features/conversations/presentation/widgets/customer_info_drawer.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/hasim_widget_harness.dart';

void main() {
  late SharedPreferences prefs;

  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await initHasimTestPrefs();
    prefs = await SharedPreferences.getInstance();
  });

  Widget wrap(Widget child) {
    return UncontrolledProviderScope(
      container: ProviderContainer(
        overrides: [
          sharedPrefsProvider.overrideWithValue(prefs),
          mediaAuthHeadersProvider.overrideWith(
            (ref) async => const {
              'Authorization': 'Bearer test-token',
              'X-Workspace-Id': '1',
            },
          ),
        ],
      ),
      child: MaterialApp(
        locale: const Locale('ar'),
        localizationsDelegates: AppLocalizations.localizationsDelegates,
        supportedLocales: AppLocalizations.supportedLocales,
        home: Material(
        child: SizedBox(width: 400, height: 800, child: child),
      ),
      ),
    );
  }

  group('ChatImageGrid', () {
    testWidgets('renders single image tile', (tester) async {
      const attachments = [
        MessageAttachmentModel(
          id: 1,
          kind: 'image',
          originalName: 'pic1.jpg',
          previewUrl: 'https://example.com/p1.jpg',
        ),
      ];

      await tester.pumpWidget(
        wrap(
          ChatImageGrid(
            attachments: attachments,
            onImageTap: (_) {},
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 50));

      expect(find.byType(ChatImageGrid), findsOneWidget);
      expect(find.text('+1'), findsNothing);

      await tester.pumpWidget(const SizedBox.shrink());
    });

    testWidgets('renders 4+ images with +N badge for remaining images', (
      tester,
    ) async {
      const attachments = [
        MessageAttachmentModel(
          id: 1,
          kind: 'image',
          originalName: '1.jpg',
          previewUrl: 'https://example.com/1.jpg',
        ),
        MessageAttachmentModel(
          id: 2,
          kind: 'image',
          originalName: '2.jpg',
          previewUrl: 'https://example.com/2.jpg',
        ),
        MessageAttachmentModel(
          id: 3,
          kind: 'image',
          originalName: '3.jpg',
          previewUrl: 'https://example.com/3.jpg',
        ),
        MessageAttachmentModel(
          id: 4,
          kind: 'image',
          originalName: '4.jpg',
          previewUrl: 'https://example.com/4.jpg',
        ),
        MessageAttachmentModel(
          id: 5,
          kind: 'image',
          originalName: '5.jpg',
          previewUrl: 'https://example.com/5.jpg',
        ),
        MessageAttachmentModel(
          id: 6,
          kind: 'image',
          originalName: '6.jpg',
          previewUrl: 'https://example.com/6.jpg',
        ),
      ];

      await tester.pumpWidget(
        wrap(
          ChatImageGrid(
            attachments: attachments,
            onImageTap: (_) {},
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 50));

      expect(find.byType(ChatImageGrid), findsOneWidget);
      // 6 images total -> 4 shown, remaining = 2 -> badge says '+2'
      expect(find.text('+2'), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
    });
  });

  group('ChatMessageBubble with media', () {
    testWidgets('displays ChatImageGrid and caption below', (tester) async {
      const message = MessageModel(
        id: 10,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'هذه صورة الفاتورة للمراجعة',
        attachments: [
          MessageAttachmentModel(
            id: 101,
            kind: 'image',
            originalName: 'inv.png',
            previewUrl: 'https://example.com/inv.png',
          ),
        ],
      );

      await tester.pumpWidget(wrap(const ChatMessageBubble(message: message)));
      await tester.pump(const Duration(milliseconds: 50));

      expect(find.byType(ChatImageGrid), findsOneWidget);
      expect(find.text('هذه صورة الفاتورة للمراجعة'), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
    });
  });

  group('ChatMediaPreviewDialog', () {
    testWidgets('renders preview modal with file count, caption, and send button', (
      tester,
    ) async {
      final dir = Directory.systemTemp.createTempSync('hasim_preview_test');
      final f1 = File('${dir.path}/test_1.pdf')..writeAsBytesSync(List.filled(100, 1));
      final f2 = File('${dir.path}/test_2.pdf')..writeAsBytesSync(List.filled(100, 1));

      await tester.pumpWidget(
        wrap(
          ChatMediaPreviewDialog(
            initialFiles: [f1, f2],
            initialCaption: 'ملاحظة تجريبية',
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 50));

      expect(find.text('1 من 2'), findsOneWidget);
      expect(find.text('ملاحظة تجريبية'), findsOneWidget);
      expect(find.byKey(const Key('chat-preview-send-btn')), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
      dir.deleteSync(recursive: true);
    });
  });

  group('CustomerInfoDrawer media gallery', () {
    testWidgets('renders media and documents section with filter chips', (
      tester,
    ) async {
      const conversation = ConversationModel(
        id: 0,
        channel: 'whatsapp',
        status: 'open',
      );

      await tester.pumpWidget(
        wrap(
          CustomerInfoDrawer(
            conversation: conversation,
            attachments: const [
              MessageAttachmentModel(
                id: 1,
                kind: 'image',
                originalName: 'pic.jpg',
                previewUrl: 'https://example.com/p.jpg',
              ),
            ],
            onClose: () {},
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 50));

      expect(find.text('الوسائط والمستندات'), findsOneWidget);
      expect(find.text('الكل (1)'), findsOneWidget);
      expect(find.text('الصور (1)'), findsOneWidget);
      expect(find.text('المستندات (0)'), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
    });
  });
}
