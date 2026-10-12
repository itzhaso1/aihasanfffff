import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/widgets/hasim_channel_mark.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_filter_chip.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('channel marks paint for every inbox channel', (tester) async {
    const channels = [
      'whatsapp',
      'email',
      'web',
      'facebook_messenger',
      'instagram',
      'telegram',
      'tiktok',
    ];
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          body: Row(
            children: [
              HasimChannelMark(channel: 'whatsapp', size: 28),
              HasimChannelMark(channel: 'email', size: 28),
              HasimChannelMark(channel: 'web', size: 28),
              HasimChannelMark(channel: 'facebook_messenger', size: 28),
              HasimChannelMark(channel: 'instagram', size: 28),
              HasimChannelMark(channel: 'telegram', size: 28),
              HasimChannelMark(channel: 'tiktok', size: 28),
            ],
          ),
        ),
      ),
    );
    await tester.pump();

    expect(find.byType(HasimChannelMark), findsNWidgets(channels.length));
    expect(HasimChannelMark.colorFor('whatsapp'), const Color(0xFF25D366));
    expect(HasimChannelMark.colorFor('facebook_messenger'), const Color(0xFF0084FF));
  });

  testWidgets('channel dropdown uses brand marks instead of generic icons', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Center(
            child: ConversationChannelDropdown(
              channels: const [
                ('whatsapp', 'واتساب', Icons.chat),
                ('email', 'بريد', Icons.mail_outline),
                ('web', 'ويب', Icons.language),
                ('facebook_messenger', 'ماسنجر', Icons.facebook_outlined),
                ('instagram', 'إنستغرام', Icons.camera_alt_outlined),
              ],
              selectedChannel: null,
              onSelected: (_) {},
            ),
          ),
        ),
      ),
    );

    await tester.tap(find.byKey(const Key('conversation-channel-dropdown')));
    await tester.pumpAndSettle();

    expect(find.byType(HasimChannelMark), findsNWidgets(5));
    expect(find.byIcon(Icons.chat), findsNothing);
    expect(find.byIcon(Icons.mail_outline), findsNothing);
    expect(find.byIcon(Icons.camera_alt_outlined), findsNothing);
    expect(find.text('واتساب'), findsOneWidget);
    expect(find.text('إنستغرام'), findsOneWidget);
  });
}
