import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/features/hubs/hub_screens.dart';

import 'helpers.dart';

void main() {
  late FakeFinanceApi api;

  setUp(() async {
    api = FakeFinanceApi(testClient(await mockPrefs()));
    api.sessionWorkspaces = const [
      WorkspaceInfo(id: 1, name: 'Workspace A', financeEnabled: true),
      WorkspaceInfo(id: 2, name: 'Workspace B', financeEnabled: true),
    ];
    api.assistantResponse = const FinanceAssistantResponse(
      answer: 'A-only answer 4250.00',
      facts: const [],
    );
  });

  testWidgets('copilot clears conversation state when workspace changes', (tester) async {
    await tester.pumpWidget(
      financeHarness(
        overrides: financeOverrides(prefs: await mockPrefs(), api: api),
        child: const AssistantScreen(),
      ),
    );
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField), 'كم الربح؟');
    await tester.tap(find.byIcon(Icons.send));
    await tester.pumpAndSettle();
    expect(find.textContaining('4250.00'), findsWidgets);

    api.sessionWorkspaces = const [
      WorkspaceInfo(id: 1, name: 'Workspace A', financeEnabled: true),
      WorkspaceInfo(id: 2, name: 'Workspace B', financeEnabled: true),
    ];
    final container = ProviderScope.containerOf(tester.element(find.byType(AssistantScreen)));
    await container.read(authControllerProvider.notifier).switchWorkspace(2);
    await tester.pumpAndSettle();

    expect(find.textContaining('4250.00'), findsNothing);
    expect(find.text('مساعد HASEM المالي'), findsOneWidget); // assistantEmptyState
  });
}
