import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/widgets/hasim_adaptive_scaffold.dart';
import 'package:hasim/core/widgets/hasim_bottom_nav.dart';
import 'package:hasim/core/widgets/hasim_nav_rail.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  final items = hasimBottomNavItems(chatBadge: 0, emailBadge: 0, updatesBadge: 0);

  Widget host({required Widget child}) {
    return MaterialApp(
      theme: AppTheme.light(),
      locale: const Locale('ar'),
      builder: (context, child) => Directionality(
        textDirection: TextDirection.rtl,
        child: child ?? const SizedBox.shrink(),
      ),
      home: child,
    );
  }

  testWidgets('compact width keeps the bottom navigation bar', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      host(
        child: HasimAdaptiveScaffold(
          selectedIndex: 0,
          onDestinationSelected: (_) {},
          items: items,
          body: const SizedBox.expand(child: Text('محتوى')),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(HasimBottomNav), findsOneWidget);
    expect(find.byType(HasimNavRail), findsNothing);
    expect(HasimLayout.isCompact(tester.element(find.text('محتوى'))), isTrue);
  });

  testWidgets('wide width uses a trailing nav rail and not a stretched bottom bar', (tester) async {
    tester.view.physicalSize = const Size(1280, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      host(
        child: HasimAdaptiveScaffold(
          selectedIndex: 0,
          onDestinationSelected: (_) {},
          onSettingsTap: () {},
          items: items,
          body: const SizedBox.expand(child: Text('محتوى')),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(HasimNavRail), findsOneWidget);
    expect(find.byType(HasimBottomNav), findsNothing);
    expect(find.text('المحادثات'), findsOneWidget);
    expect(find.text('البريد'), findsOneWidget);
    expect(find.text('التحديثات'), findsOneWidget);
    expect(find.text('الإعدادات'), findsOneWidget);
    expect(find.text('المزيد'), findsNothing);

    final rail = tester.getRect(find.byType(HasimNavRail));
    final body = tester.getRect(find.text('محتوى'));
    final scaffold = tester.getRect(find.byType(HasimAdaptiveScaffold));
    // RTL: السكة ملتصقة بأقصى اليمين، بارتفاع النافذة كاملًا، والمحتوى على يسارها.
    expect(rail.center.dx, greaterThan(body.center.dx));
    expect(body.width, greaterThan(rail.width * 2));
    expect(rail.width, HasimLayout.navRailWidth);
    expect(rail.top, scaffold.top);
    expect(rail.bottom, scaffold.bottom);
    expect(rail.right, scaffold.right);
  });

  testWidgets('tablet width uses a nav rail without stretching the bottom bar', (tester) async {
    tester.view.physicalSize = const Size(800, 1024);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      host(
        child: HasimAdaptiveScaffold(
          selectedIndex: 0,
          onDestinationSelected: (_) {},
          onSettingsTap: () {},
          items: items,
          body: const SizedBox.expand(child: Text('محتوى')),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(HasimNavRail), findsOneWidget);
    expect(find.byType(HasimBottomNav), findsNothing);
    expect(find.text('الإعدادات'), findsOneWidget);
    expect(HasimLayout.useConversationSplit(tester.element(find.text('محتوى'))), isTrue);
    expect(HasimLayout.useSettingsSplit(tester.element(find.text('محتوى'))), isFalse);
  });
}
