import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_message_bubble.dart';
import 'package:hasim/l10n/app_localizations.dart';

Widget _wrap(Widget child) {
  return MaterialApp(
    theme: AppTheme.light(),
    locale: const Locale('ar'),
    localizationsDelegates: AppLocalizations.localizationsDelegates,
    supportedLocales: AppLocalizations.supportedLocales,
    builder: (context, c) => Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(body: c!),
    ),
    home: child,
  );
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('ChatMessageBubble Sender Attribution Tests', () {
    testWidgets('renders employee name and employee role badge on outbound employee message', (tester) async {
      const message = MessageModel(
        id: 101,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'أهلاً بك، تفضل كيف يمكنني مساعدتك؟',
        userName: 'أحمد',
        participantType: 'company_staff',
      );

      await tester.pumpWidget(_wrap(const ChatMessageBubble(message: message)));
      await tester.pumpAndSettle();

      expect(find.text('أحمد'), findsOneWidget);
      expect(find.text('موظف'), findsOneWidget);
      expect(find.byIcon(Icons.badge_outlined), findsOneWidget);
      expect(find.text('أهلاً بك، تفضل كيف يمكنني مساعدتك؟'), findsOneWidget);
    });

    testWidgets('renders AI sender name and AI role badge on AI-generated message', (tester) async {
      const message = MessageModel(
        id: 102,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'تم تسجيل طلبك بنجاح وسيتواصل معك الموظف قريباً.',
        aiGenerated: true,
        userName: 'مشعل AI',
        participantType: 'ai_employee',
      );

      await tester.pumpWidget(_wrap(const ChatMessageBubble(message: message)));
      await tester.pumpAndSettle();

      expect(find.text('مشعل AI'), findsOneWidget);
      expect(find.text('ذكاء اصطناعي'), findsOneWidget);
      expect(find.byIcon(Icons.smart_toy_outlined), findsOneWidget);
    });

    testWidgets('renders customer name and customer role badge on inbound customer message', (tester) async {
      const message = MessageModel(
        id: 103,
        conversationId: 1,
        direction: 'inbound',
        messageType: 'text',
        content: 'مرحباً، أين شحنتي؟',
        customerName: 'فهد المنصور',
      );

      await tester.pumpWidget(_wrap(const ChatMessageBubble(message: message)));
      await tester.pumpAndSettle();

      expect(find.text('فهد المنصور'), findsOneWidget);
      expect(find.text('عميل'), findsOneWidget);
      expect(find.byIcon(Icons.person_outline), findsOneWidget);
    });

    testWidgets('falls back to generic employee label if employee has no user name', (tester) async {
      const message = MessageModel(
        id: 104,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'جاري مراجعة الطلب.',
      );

      await tester.pumpWidget(_wrap(const ChatMessageBubble(message: message)));
      await tester.pumpAndSettle();

      expect(find.text('الموظف'), findsOneWidget);
      expect(find.text('موظف'), findsOneWidget);
      expect(find.byIcon(Icons.badge_outlined), findsOneWidget);
    });
  });
}
