import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/conversations/data/chat_attachment_policy.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_attachment_content.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_message_bubble.dart';
import 'package:hasim/l10n/app_localizations.dart';

import 'support/hasim_widget_harness.dart';

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await initHasimTestPrefs();
  });

  test('MessageAttachmentModel parses Laravel mobile fields', () {
    final a = MessageAttachmentModel.fromJson({
      'id': 3,
      'kind': 'video',
      'status': 'stored',
      'original_name': 'clip.mp4',
      'mime_type': 'video/mp4',
      'size_bytes': 2048,
      'duration_ms': 12500,
      'download_url': 'https://example.com/signed',
      'preview_url': null,
    });
    expect(a.kind, 'video');
    expect(a.durationMs, 12500);
    expect(a.displayUrl, 'https://example.com/signed');
  });

  test('MessageModel parses mixed attachment kinds', () {
    final m = MessageModel.fromJson({
      'id': 1,
      'conversation_id': 2,
      'direction': 'inbound',
      'message_type': 'text',
      'content': '',
      'attachments': [
        {
          'id': 1,
          'kind': 'image',
          'original_name': 'a.png',
          'preview_url': 'https://example.com/p.png',
          'download_url': 'https://example.com/d.png',
        },
        {
          'id': 2,
          'kind': 'file',
          'original_name': 'doc.pdf',
          'mime_type': 'application/pdf',
          'size_bytes': 900,
          'download_url': 'https://example.com/doc.pdf',
        },
      ],
    });
    expect(m.attachments, hasLength(2));
    expect(m.attachments.first.displayUrl, 'https://example.com/p.png');
    expect(m.attachments.last.kind, 'file');
  });

  test('ChatAttachmentPolicy rejects oversize and unknown extensions', () async {
    final dir = await Directory.systemTemp.createTemp('hasim_attach');
    final ok = File('${dir.path}/photo.jpg');
    await ok.writeAsBytes(List.filled(100, 1));
    expect(ChatAttachmentPolicy.validateFile(ok), isNull);
    expect(ChatAttachmentPolicy.kindForExtension('jpg'), 'image');
    expect(ChatAttachmentPolicy.kindForExtension('mp3'), 'audio');
    expect(ChatAttachmentPolicy.kindForExtension('mp4'), 'video');
    expect(ChatAttachmentPolicy.kindForExtension('pdf'), 'file');

    final big = File('${dir.path}/big.jpg');
    await big.writeAsBytes(List.filled(ChatAttachmentPolicy.maxBytes + 1, 1));
    expect(ChatAttachmentPolicy.validateFile(big), 'tooLarge');

    final bad = File('${dir.path}/x.exe');
    await bad.writeAsBytes([1, 2, 3]);
    expect(ChatAttachmentPolicy.validateFile(bad), 'unsupported');
    await dir.delete(recursive: true);
  });

  Widget wrap(Widget child) => hasimTestApp(Center(child: child));

  testWidgets('file attachment row shows name, size, and open label', (tester) async {
    const attachment = MessageAttachmentModel(
      id: 1,
      kind: 'file',
      originalName: 'report.pdf',
      sizeBytes: 2048,
      downloadUrl: 'https://example.com/report.pdf',
    );
    await tester.pumpWidget(
      wrap(
        ChatAttachmentContent(
          attachment: attachment,
          onOpenFailed: () {},
        ),
      ),
    );
    expect(find.text('report.pdf'), findsOneWidget);
    expect(find.text('2.0 KB'), findsOneWidget);
    expect(find.text('فتح'), findsOneWidget);
  });

  testWidgets('audio attachment shows duration and open action', (tester) async {
    const attachment = MessageAttachmentModel(
      id: 2,
      kind: 'audio',
      originalName: 'note.mp3',
      durationMs: 65000,
      downloadUrl: 'https://example.com/note.mp3',
    );
    await tester.pumpWidget(
      wrap(
        ChatAttachmentContent(
          attachment: attachment,
          onOpenFailed: () {},
        ),
      ),
    );
    expect(find.text('note.mp3'), findsOneWidget);
    expect(find.text('01:05'), findsOneWidget);
    expect(find.text('فتح'), findsOneWidget);
  });

  testWidgets('pending upload bubble shows uploading label', (tester) async {
    const message = MessageModel(
      id: -1,
      conversationId: 1,
      direction: 'outbound',
      messageType: 'text',
      content: '📎',
      localPending: true,
      localUploading: true,
      localAttachmentPath: '/tmp/x.jpg',
    );
    await tester.pumpWidget(
      wrap(const ChatMessageBubble(message: message)),
    );
    expect(find.text('جاري الرفع…'), findsOneWidget);
  });
}
