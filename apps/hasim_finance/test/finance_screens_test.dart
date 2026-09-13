import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/features/customers/customers_screens.dart';
import 'package:hasim_finance/features/dashboard/dashboard_screen.dart';
import 'package:hasim_finance/features/invoices/invoices_screens.dart';
import 'package:hasim_finance/features/modules/module_screens.dart';
import 'package:hasim_finance/features/quotes/quotes_screens.dart';
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
  });

  Future<void> pumpScreen(
    WidgetTester tester,
    Widget child, {
    bool allowAll = true,
  }) async {
    tester.view.physicalSize = const Size(1400, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(financeHarness(
      overrides: financeOverrides(prefs: prefs, api: api, allowAll: allowAll),
      child: child,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
  }

  testWidgets('dashboard shows server cards and recent invoices', (tester) async {
    await pumpScreen(tester, const DashboardScreen());

    expect(find.text('لوحة المالية'), findsOneWidget);
    expect(find.text('115.00'), findsWidgets);
    expect(find.text('INV-11'), findsOneWidget);
  });

  testWidgets('invoice list uses server totals and statuses', (tester) async {
    await pumpScreen(tester, const InvoicesScreen());

    expect(find.text('INV-11'), findsOneWidget);
    expect(find.text('صادرة'), findsOneWidget);
    expect(find.text('غير مدفوعة'), findsWidgets);
    expect(find.text('115.00'), findsWidgets);
  });

  testWidgets('invoice details keep document and payment status separate', (tester) async {
    await pumpScreen(tester, const InvoiceDetailScreen(id: 11));

    expect(find.text('INV-11'), findsWidgets);
    expect(find.textContaining('حالة المستند'), findsOneWidget);
    expect(find.textContaining('حالة التحصيل'), findsOneWidget);
    expect(find.text('تسجيل دفعة'), findsOneWidget);
    expect(find.text('إنشاء رابط الدفع'), findsOneWidget);
  });

  testWidgets('manual payment form submits to the API and refreshes paid state', (tester) async {
    await pumpScreen(tester, const InvoiceDetailScreen(id: 11));

    await tester.tap(find.text('تسجيل دفعة'));
    await tester.pumpAndSettle();
    expect(find.text('حفظ'), findsOneWidget);
    await tester.tap(find.text('حفظ'));
    await tester.pumpAndSettle();

    expect(api.invoiceRecord.paymentStatus, 'paid');
    expect(find.textContaining('paid'), findsWidgets);
  });

  testWidgets('quote details show outcome separately from document status', (tester) async {
    await pumpScreen(tester, const QuoteDetailScreen(id: 5));

    expect(find.text('Q-5'), findsWidgets);
    expect(find.textContaining('النتيجة التجارية'), findsOneWidget);
    expect(find.textContaining('pending'), findsWidgets);
    expect(find.text('قبول'), findsOneWidget);
  });

  testWidgets('customer details show server outstanding balance', (tester) async {
    await pumpScreen(tester, const CustomerDetailScreen(id: 7));

    expect(find.text('عميل الاختبار'), findsWidgets);
    expect(find.textContaining('115.00'), findsWidgets);
    await tester.tap(find.text('الفواتير'));
    await tester.pumpAndSettle();
    expect(find.text('INV-11'), findsOneWidget);
  });

  testWidgets('receipt details show posted status', (tester) async {
    await pumpScreen(tester, const ReceiptDetailScreen(id: 3));

    expect(find.text('R-3'), findsWidgets);
    expect(find.text('posted'), findsOneWidget);
  });

  testWidgets('permission gate hides invoice list without invoices.view', (tester) async {
    await pumpScreen(tester, const InvoicesScreen(), allowAll: false);

    expect(find.text('هذه الشاشة غير متاحة لصلاحياتك الحالية.'), findsOneWidget);
    expect(find.text('INV-11'), findsNothing);
  });

  testWidgets('quote compose submits multiple server lines without local totals', (tester) async {
    tester.view.physicalSize = const Size(1400, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      ProviderScope(
        overrides: financeOverrides(prefs: prefs, api: api),
        child: MaterialApp.router(
          locale: const Locale('ar'),
          supportedLocales: AppLocalizations.supportedLocales,
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          routerConfig: GoRouter(
            initialLocation: '/quotes/new',
            routes: [
              GoRoute(path: '/quotes/new', builder: (_, _) => const QuoteFormScreen()),
              GoRoute(
                path: '/quotes/:id',
                builder: (_, state) => Text('saved-${state.pathParameters['id']}'),
              ),
            ],
          ),
        ),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    await tester.tap(find.text('عميل الاختبار').last);
    await tester.pumpAndSettle();

    await tester.tap(find.text('إضافة بند'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('حفظ'));
    await tester.pumpAndSettle();

    expect(api.lastQuotePayload, isNotNull);
    expect(api.lastQuotePayload!['items'], hasLength(2));
    expect((api.lastQuotePayload!['items'] as List).first.containsKey('total'), isFalse);
    expect(find.text('saved-99'), findsOneWidget);
  });
}
