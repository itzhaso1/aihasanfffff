import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/core/routing/finance_shell.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';
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

  Future<void> pumpNav(WidgetTester tester, {String location = '/dashboard'}) async {
    tester.view.physicalSize = const Size(1440, 1100);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final router = GoRouter(
      initialLocation: location,
      routes: [
        ShellRoute(
          builder: (context, state, child) => FinanceShell(child: child),
          routes: [
            GoRoute(path: '/dashboard', builder: (_, _) => const Text('dashboard-body')),
            GoRoute(path: '/invoices', builder: (_, _) => const Text('invoices-body')),
            GoRoute(path: '/quotes', builder: (_, _) => const Text('quotes-body')),
            GoRoute(path: '/payments', builder: (_, _) => const Text('payments-body')),
            GoRoute(path: '/people', builder: (_, _) => const Text('people-body')),
            GoRoute(path: '/settings', builder: (_, _) => const Text('settings-body')),
            GoRoute(path: '/sales', builder: (_, _) => const Text('sales-body')),
            GoRoute(path: '/billing', builder: (_, _) => const Text('billing-body')),
          ],
        ),
      ],
    );

    await tester.pumpWidget(
      ProviderScope(
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
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 80));
  }

  testWidgets('sales children stay hidden until the group is expanded', (tester) async {
    await pumpNav(tester);
    expect(find.text('المبيعات'), findsWidgets);
    expect(find.text('الفواتير'), findsNothing);
    expect(find.text('عروض الأسعار'), findsNothing);

    await tester.tap(find.text('المبيعات').first);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 250));

    expect(find.text('الفواتير'), findsOneWidget);
    expect(find.text('عروض الأسعار'), findsOneWidget);
  });

  testWidgets('active invoice route auto-expands sales and marks the child', (tester) async {
    await pumpNav(tester, location: '/invoices');
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 80));

    expect(find.text('invoices-body'), findsOneWidget);
    expect(find.text('الفواتير'), findsOneWidget);
    expect(find.text('عروض الأسعار'), findsOneWidget);
  });

  testWidgets('collapsing a group hides its children again', (tester) async {
    await pumpNav(tester);
    await tester.tap(find.text('المبيعات').first);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 250));
    expect(find.text('الفواتير'), findsOneWidget);

    await tester.tap(find.text('المبيعات').first);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 250));
    expect(find.text('الفواتير'), findsNothing);
  });

  testWidgets('leaf settings and dashboard remain direct links', (tester) async {
    await pumpNav(tester);
    expect(find.text('لوحة المالية'), findsWidgets);
    expect(find.text('الإعدادات'), findsWidgets);
    await tester.tap(find.text('الإعدادات').last);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 80));
    expect(find.text('settings-body'), findsOneWidget);
  });
}
