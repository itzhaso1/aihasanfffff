import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/features/settings/data/ai_employee_model.dart';
import 'package:hasim/features/settings/presentation/ai_skills_sheet.dart';

void main() {
  testWidgets('skills can be selected, opened, and removed', (tester) async {
    List<String>? saved;
    await tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (context) => Scaffold(
            body: TextButton(
              onPressed: () => showAiSkillsSheet(
                context: context,
                employeeName: 'موظف',
                selectedKeys: const ['sales'],
                load: () async => const [
                  AiSkillDefinition(
                    key: 'sales',
                    name: 'المبيعات',
                    description: 'بيع داخل المحادثة',
                    category: 'commerce',
                  ),
                  AiSkillDefinition(key: 'support', name: 'الدعم'),
                ],
                save: (keys) async {
                  saved = keys;
                  return true;
                },
              ),
              child: const Text('open'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
    expect(find.text('المبيعات'), findsOneWidget);
    expect(find.text('secret'), findsNothing);

    await tester.tap(find.byKey(const Key('ai-skill-open-sales')));
    await tester.pumpAndSettle();
    expect(find.text('بيع داخل المحادثة'), findsOneWidget);
    await tester.tap(find.text('المهارات'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('ai-skill-sales')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('ai-skill-support')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('ai-skills-save')));
    await tester.pumpAndSettle();
    expect(saved, ['support']);
  });

  testWidgets('skills error is shown', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (context) => Scaffold(
            body: TextButton(
              onPressed: () => showAiSkillsSheet(
                context: context,
                employeeName: 'موظف',
                selectedKeys: const [],
                load: () async => throw Exception('offline'),
                save: (_) async => false,
              ),
              child: const Text('open'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('ai-skills-error')), findsOneWidget);
  });

  testWidgets('empty skills stay empty', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (context) => Scaffold(
            body: TextButton(
              onPressed: () => showAiSkillsSheet(
                context: context,
                employeeName: 'موظف',
                selectedKeys: const [],
                load: () async => const [],
                save: (_) async => true,
              ),
              child: const Text('open'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('ai-skills-empty')), findsOneWidget);
  });
}
