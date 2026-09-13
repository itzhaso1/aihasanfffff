import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_finance/features/people/people_screens.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'helpers.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late SharedPreferences prefs;
  late FakeFinanceApi api;

  setUp(() async {
    prefs = await mockPrefs();
    api = FakeFinanceApi(testClient(prefs));
  });

  Future<void> pumpScreen(WidgetTester tester, Widget child) async {
    tester.view.physicalSize = const Size(1400, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(financeHarness(
      overrides: financeOverrides(prefs: prefs, api: api),
      child: child,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
  }

  testWidgets('people list shows company person balances from the API', (tester) async {
    await pumpScreen(tester, const PeopleScreen());
    expect(find.text('موظف الشركة'), findsOneWidget);
    expect(find.text('1500.00'), findsWidgets);
    expect(find.text('500.00'), findsWidgets);
  });

  testWidgets('person detail shows owed paid remaining and advances', (tester) async {
    await pumpScreen(tester, const PersonDetailScreen(id: 4));
    expect(find.text('موظف الشركة'), findsWidgets);
    expect(find.text('المستحق'), findsOneWidget);
    expect(find.text('المدفوع'), findsOneWidget);
    expect(find.text('المتبقي'), findsWidgets);
    expect(find.text('السلفة'), findsWidgets);
  });

  testWidgets('payroll overview uses server cards', (tester) async {
    await pumpScreen(tester, const PayrollOverviewScreen());
    expect(find.text('الرواتب والمستحقات'), findsOneWidget);
    expect(find.text('1000.00'), findsWidgets);
    expect(find.text('موظف الشركة'), findsOneWidget);
  });
}
