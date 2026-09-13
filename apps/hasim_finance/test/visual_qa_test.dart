import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/routing/finance_shell.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';
import 'package:hasim_finance/features/dashboard/dashboard_screen.dart';
import 'package:hasim_finance/features/invoices/invoices_screens.dart';
import 'package:hasim_finance/features/people/people_screens.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'helpers.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late SharedPreferences prefs;
  late FakeFinanceApi api;

  setUp(() async {
    prefs = await mockPrefs();
    api = FakeFinanceApi(testClient(prefs));
    api.dashboardData = DashboardData(
      cards: {
        ...api.dashboardData.cards,
        'sales': '28450.00',
        'outstanding_customer_balance': '12300.00',
        'payables': '8750.00',
        'receivables': '115000.00',
        'purchases': '210800.00',
        'expenses': '45600.00',
        'net_profit': '83850.00',
        'output_vat': '15750.00',
        'invoices_due': '18',
        'overdue_invoices': '7',
        'cash_balance': '200.00',
        'company_employees': '3',
        'payroll_paid_total': '1000.00',
        'open_advances_total': '500.00',
        'deductions_total': '120.00',
      },
      recentInvoices: [
        InvoiceRecord(id: 12, invoiceNumber: 'INV-00124', customerName: 'شركة النور', total: '12500.00', issueDate: '2026-09-10', paymentStatus: 'paid'),
        InvoiceRecord(id: 13, invoiceNumber: 'INV-00123', customerName: 'مؤسسة السحاب', total: '8750.00', issueDate: '2026-09-09', paymentStatus: 'unpaid'),
        InvoiceRecord(id: 11, invoiceNumber: 'INV-11', customerName: 'عميل الاختبار', total: '115.00', issueDate: '2026-09-08', paymentStatus: 'partial'),
      ],
      recentPayments: const [],
      overdueInvoices: [
        InvoiceRecord(id: 14, invoiceNumber: 'INV-00121', customerName: 'مؤسسة الصفا', total: '6400.00', issueDate: '2026-09-07', paymentStatus: 'overdue'),
      ],
      recentExpenses: const [
        ExpenseRecord(id: 9, description: 'إيجار المكتب', total: '1150.00'),
      ],
      analytics: const {
        'from': '2026-09-01',
        'to': '2026-09-10',
        'hero': [
          {'key': 'sales', 'label': 'كم بعنا؟', 'value': '28,450.00', 'hint': 'مقارنة بالفترة السابقة', 'delta': '+12%', 'direction': 1},
          {'key': 'receivables', 'label': 'كم يحتسب عند العميل؟', 'value': '12,300.00', 'hint': 'مقارنة بالفترة السابقة', 'delta': '+8%', 'direction': 1},
          {'key': 'payables', 'label': 'كم علينا؟', 'value': '8,750.00', 'hint': 'مقارنة بالفترة السابقة', 'delta': '+5%', 'direction': 1},
        ],
        'attention': [
          {'title': 'تحصيل متأخر', 'reason': 'توجد فواتير متأخرة تحتاج متابعة'},
        ],
        'series': [
          {'month': '2026-04', 'sales': 90000, 'expenses': 40000},
          {'month': '2026-05', 'sales': 110000, 'expenses': 42000},
          {'month': '2026-06', 'sales': 125000, 'expenses': 38000},
          {'month': '2026-07', 'sales': 98000, 'expenses': 41000},
          {'month': '2026-08', 'sales': 140000, 'expenses': 47000},
          {'month': '2026-09', 'sales': 155000, 'expenses': 45000},
        ],
        'products': [
          {'name': 'منتجات', 'total': 45},
          {'name': 'خدمات', 'total': 30},
          {'name': 'اشتراكات', 'total': 15},
          {'name': 'أخرى', 'total': 10},
        ],
      },
    );
  });

  Future<void> pumpApp(WidgetTester tester, {required Size size, required String location}) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final router = GoRouter(
      initialLocation: location,
      routes: [
        ShellRoute(
          builder: (context, state, child) => FinanceShell(child: child),
          routes: [
            GoRoute(path: '/dashboard', builder: (_, _) => const DashboardScreen()),
            GoRoute(path: '/people', builder: (_, _) => const PeopleScreen()),
            GoRoute(path: '/people/:id', builder: (_, state) => PersonDetailScreen(id: int.parse(state.pathParameters['id']!))),
            GoRoute(path: '/payroll', builder: (_, _) => const PayrollOverviewScreen()),
            GoRoute(path: '/advances', builder: (_, _) => const SalaryAdvancesScreen()),
            GoRoute(path: '/invoices', builder: (_, _) => const InvoicesScreen()),
            GoRoute(path: '/invoices/new', builder: (_, _) => const InvoiceFormScreen()),
            GoRoute(path: '/search', builder: (_, _) => const FinanceSearchScreen()),
            GoRoute(path: '/alerts', builder: (_, _) => const SizedBox.shrink()),
            GoRoute(path: '/settings', builder: (_, _) => const SizedBox.shrink()),
            GoRoute(path: '/exports', builder: (_, _) => const SizedBox.shrink()),
          ],
        ),
      ],
    );

    await tester.pumpWidget(
      RepaintBoundary(
        child: ProviderScope(
          overrides: financeOverrides(prefs: prefs, api: api),
          child: MaterialApp.router(
            debugShowCheckedModeBanner: false,
            theme: ThemeData(
              useMaterial3: true,
              colorScheme: const ColorScheme.light(primary: FinanceTokens.brand, surface: FinanceTokens.surface),
              scaffoldBackgroundColor: FinanceTokens.canvas,
            ),
            locale: const Locale('ar'),
            supportedLocales: AppLocalizations.supportedLocales,
            localizationsDelegates: const [
              AppLocalizations.delegate,
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
              GlobalCupertinoLocalizations.delegate,
            ],
            routerConfig: router,
          ),
        ),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 80));
    await tester.pump(const Duration(milliseconds: 80));
  }

  Future<void> saveShot(WidgetTester tester, String name) async {
    await tester.runAsync(() async {
      try {
        final boundary = tester.renderObject<RenderRepaintBoundary>(find.byType(RepaintBoundary).first);
        final image = await boundary.toImage(pixelRatio: 1);
        final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
        final png = bytes!.buffer.asUint8List();
        for (final dir in [
          Directory('/opt/cursor/artifacts'),
          Directory('/tmp/finance-visual'),
        ]) {
          try {
            dir.createSync(recursive: true);
            File('${dir.path}/$name.png').writeAsBytesSync(png);
          } catch (_) {}
        }
      } catch (_) {}
    });
  }

  testWidgets('desktop dashboard visual capture', (tester) async {
    await pumpApp(tester, size: const Size(1440, 1100), location: '/dashboard');
    expect(find.byType(FinanceShell), findsOneWidget);
    expect(find.text('لوحة المالية'), findsWidgets);
    expect(tester.takeException(), isNull);
    await saveShot(tester, 'finance_dashboard_desktop_1440');
  });

  testWidgets('invoices page auto-expands sales sidebar group', (tester) async {
    await pumpApp(tester, size: const Size(1440, 1100), location: '/invoices');
    expect(find.text('الفواتير'), findsWidgets);
    expect(tester.takeException(), isNull);
    await saveShot(tester, 'finance_sidebar_sales_expanded_1440');
  });

  testWidgets('mobile dashboard visual capture', (tester) async {
    await pumpApp(tester, size: const Size(390, 844), location: '/dashboard');
    expect(find.byType(NavigationBar), findsOneWidget);
    expect(tester.takeException(), isNull);
    await saveShot(tester, 'finance_dashboard_mobile_390');
  });

  testWidgets('people obligations visual capture', (tester) async {
    await pumpApp(tester, size: const Size(1440, 900), location: '/people');
    expect(find.text('موظف الشركة'), findsWidgets);
    expect(tester.takeException(), isNull);
    await saveShot(tester, 'finance_people_desktop_1440');
  });

  testWidgets('person detail visual capture', (tester) async {
    await pumpApp(tester, size: const Size(1440, 1100), location: '/people/4');
    expect(find.text('المستحق'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await saveShot(tester, 'finance_person_detail_desktop_1440');
  });

  testWidgets('invoice compose visual capture', (tester) async {
    await pumpApp(tester, size: const Size(1440, 1100), location: '/invoices/new');
    await tester.pump(const Duration(milliseconds: 200));
    expect(find.textContaining('الفواتير'), findsWidgets);
    expect(tester.takeException(), isNull);
    await saveShot(tester, 'finance_invoice_compose_desktop_1440');
  });
}
