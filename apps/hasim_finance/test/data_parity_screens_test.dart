import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/features/dashboard/dashboard_screen.dart';
import 'package:hasim_finance/features/modules/module_screens.dart';
import 'package:hasim_finance/features/quotes/quotes_screens.dart';
import 'package:hasim_finance/features/reports/report_view.dart';
import 'package:hasim_finance/features/shared/customer_select.dart';
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

  testWidgets('statement table shows debit, credit, description, and totals', (tester) async {
    await pumpScreen(tester, const StatementScreen(customerId: 7));
    await tester.tap(find.text('تحديث'));
    await tester.pumpAndSettle();

    expect(find.textContaining('115.00'), findsWidgets);
    expect(find.textContaining('50.00'), findsWidgets);
    expect(find.textContaining('فاتورة مبيعات'), findsOneWidget);
    expect(find.textContaining('دفعة جزئية'), findsOneWidget);
    expect(find.text('PARITY INV 001'), findsOneWidget);
    expect(find.byType(DataTable), findsOneWidget);
  });

  testWidgets('reports render ledger totals instead of a JSON tree', (tester) async {
    await pumpScreen(tester, const ReportsScreen());
    await tester.tap(find.text('تحديث'));
    await tester.pumpAndSettle();

    expect(find.byType(FinanceReportView), findsOneWidget);
    expect(find.text('500.00'), findsWidgets);
    expect(find.textContaining('إيرادات'), findsWidgets);
    expect(find.text('object'), findsNothing);
  });

  testWidgets('dashboard shows VAT, cash, and purchases cards from the server', (tester) async {
    api.dashboardData = DashboardData(
      cards: {
        ...api.dashboardData.cards,
        'purchases': '80.00',
        'output_vat': '15.00',
        'cash_balance': '200.00',
        'net_profit': '35.00',
      },
      recentInvoices: api.dashboardData.recentInvoices,
      recentPayments: api.dashboardData.recentPayments,
      overdueInvoices: [
        InvoiceRecord(id: 11, invoiceNumber: 'INV-OVERDUE', amountDue: '115.00', customerName: 'عميل الاختبار'),
      ],
      recentExpenses: const [
        ExpenseRecord(id: 9, description: 'إيجار المكتب', total: '1150.00'),
      ],
    );
    await pumpScreen(tester, const DashboardScreen());

    expect(find.text('80.00'), findsWidgets);
    expect(find.text('15.00'), findsWidgets);
    expect(find.text('200.00'), findsWidgets);
    expect(find.text('INV-OVERDUE'), findsOneWidget);
    expect(find.text('إيجار المكتب'), findsOneWidget);
  });

  testWidgets('customer picker searches and loads more than page one', (tester) async {
    api.catalogCustomers = [
      for (var i = 1; i <= 30; i++)
        CustomerRecord(id: i, name: 'عميل $i', outstandingBalance: '0.00'),
      CustomerRecord(id: 99, name: 'PARITY CUSTOMER 001', outstandingBalance: '0.00'),
    ];

    await pumpScreen(
      tester,
      Scaffold(
        body: CustomerSelectField(selectedId: null, onSelected: (_) {}),
      ),
    );
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    expect(find.text('تحميل المزيد'), findsOneWidget);
    await tester.tap(find.text('تحميل المزيد'));
    await tester.pumpAndSettle();
    expect(find.text('عميل 26', skipOffstage: false), findsWidgets);

    await tester.enterText(find.byType(TextField).first, 'PARITY');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(find.text('PARITY CUSTOMER 001'), findsWidgets);
  });

  testWidgets('quote list filter chips remain available without hiding issued quotes', (tester) async {
    await pumpScreen(tester, const QuotesScreen());
    expect(find.text('Q-5'), findsOneWidget);
    expect(find.text('الكل'), findsOneWidget);
  });
}
