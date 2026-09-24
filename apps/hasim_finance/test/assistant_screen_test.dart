import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/features/hubs/hub_screens.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

import 'helpers.dart';

void main() {
  late FakeFinanceApi api;

  setUp(() async {
    api = FakeFinanceApi(testClient(await mockPrefs()));
    api.assistantError = null;
  });

  Future<void> pumpAssistant(
    WidgetTester tester, {
    bool allowAll = true,
    Map<String, bool>? permissions,
  }) async {
    await tester.pumpWidget(
      financeHarness(
        overrides: financeOverrides(
          prefs: await mockPrefs(),
          api: api,
          allowAll: allowAll,
          permissions: permissions,
        ),
        child: const AssistantScreen(),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('assistant screen submits question and renders answer with facts', (tester) async {
    await pumpAssistant(tester);

    await tester.enterText(find.byType(TextField), 'كم صافي الربح هذا الشهر؟');
    await tester.tap(find.byIcon(Icons.send));
    await tester.pumpAndSettle();

    expect(api.lastAssistantQuestion, 'كم صافي الربح هذا الشهر؟');
    expect(find.textContaining('4250.00'), findsWidgets);
    expect(find.text('صافي الربح'), findsWidgets);
    expect(find.textContaining('2026-09-01'), findsOneWidget);
  });

  testWidgets('quick prompt triggers assistant request', (tester) async {
    await pumpAssistant(tester);

    await tester.tap(find.text('صافي الربح'));
    await tester.pumpAndSettle();

    expect(api.lastAssistantQuestion, 'صافي الربح');
  });

  testWidgets('assistant shows API error state', (tester) async {
    api.assistantError = ApiException('تعذر الاتصال.', statusCode: 503);
    await pumpAssistant(tester);

    await tester.tap(find.text('الرواتب'));
    await tester.pumpAndSettle();

    expect(find.text('تعذر الاتصال.'), findsOneWidget);
  });

  testWidgets('reports-only user can access assistant screen', (tester) async {
    await pumpAssistant(
      tester,
      allowAll: false,
      permissions: const {'reports.view': true},
    );

    expect(find.byType(TextField), findsOneWidget);
    expect(find.text(AppLocalizations.of(tester.element(find.byType(TextField))).noPermissionScreen), findsNothing);
  });

  testWidgets('finance-view user can access assistant screen', (tester) async {
    await pumpAssistant(
      tester,
      allowAll: false,
      permissions: const {'finance.view': true},
    );

    expect(find.byType(TextField), findsOneWidget);
  });

  testWidgets('payroll-only user can access assistant screen', (tester) async {
    await pumpAssistant(
      tester,
      allowAll: false,
      permissions: const {'payroll.view': true},
    );

    expect(find.byType(TextField), findsOneWidget);
  });

  testWidgets('user without assistant permissions sees gate', (tester) async {
    await pumpAssistant(
      tester,
      allowAll: false,
      permissions: const {'customers.view': true},
    );

    expect(find.byType(TextField), findsNothing);
    expect(find.byIcon(Icons.lock_outline), findsOneWidget);
  });

  testWidgets('unavailable period response renders unavailable label', (tester) async {
    await pumpAssistant(tester);

    await tester.enterText(find.byType(TextField), 'كم مبيعات سنة 2050؟');
    await tester.tap(find.byIcon(Icons.send));
    await tester.pumpAndSettle();

    expect(find.text('فترة غير مدعومة'), findsOneWidget);
  });

  testWidgets('conversation history drawer lists saved conversations', (tester) async {
    await pumpAssistant(tester);

    await tester.enterText(find.byType(TextField), 'كم صافي الربح؟');
    await tester.tap(find.byIcon(Icons.send));
    await tester.pumpAndSettle();

    await tester.tap(find.byIcon(Icons.history));
    await tester.pumpAndSettle();

    expect(find.text('سجل المحادثات'), findsOneWidget);
    expect(find.text('كم صافي الربح؟'), findsOneWidget);
  });

  testWidgets('loading saved conversation hydrates prior messages', (tester) async {
    api.assistantConversationDetails[7] = const FinanceAssistantConversationDetail(
      id: 7,
      title: 'محادثة سابقة',
      messages: [
        FinanceAssistantMessage(id: 1, role: 'user', body: 'كم المبيعات؟'),
        FinanceAssistantMessage(id: 2, role: 'assistant', body: 'المبيعات 5000.00'),
      ],
    );

    await tester.pumpWidget(
      financeHarness(
        overrides: financeOverrides(prefs: await mockPrefs(), api: api),
        child: const AssistantScreen(initialConversationId: 7),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('كم المبيعات؟'), findsOneWidget);
    expect(find.text('المبيعات 5000.00'), findsOneWidget);
  });

  testWidgets('new conversation clears current messages', (tester) async {
    await pumpAssistant(tester);

    await tester.enterText(find.byType(TextField), 'كم الربح؟');
    await tester.tap(find.byIcon(Icons.send));
    await tester.pumpAndSettle();
    expect(find.textContaining('4250.00'), findsWidgets);

    await tester.tap(find.byTooltip('محادثة جديدة'));
    await tester.pumpAndSettle();

    expect(find.textContaining('4250.00'), findsNothing);
    expect(find.text('مساعد HASEM المالي'), findsOneWidget);
  });
}
