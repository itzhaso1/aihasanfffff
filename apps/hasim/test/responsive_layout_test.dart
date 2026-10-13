import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/widgets/hasim_adaptive_scaffold.dart';
import 'package:hasim/core/widgets/hasim_bottom_nav.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/presentation/widgets/catalog_variants_editor.dart';
import 'package:hasim/l10n/app_localizations.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  final items = hasimBottomNavItems(
    chatBadge: 0,
    emailBadge: 0,
    updatesBadge: 0,
  );

  Widget host({required Widget child}) {
    return MaterialApp(
      theme: AppTheme.light(),
      locale: const Locale('ar'),
      localizationsDelegates: AppLocalizations.localizationsDelegates,
      supportedLocales: AppLocalizations.supportedLocales,
      builder: (context, child) => Directionality(
        textDirection: TextDirection.rtl,
        child: child ?? const SizedBox.shrink(),
      ),
      home: child,
    );
  }

  Future<void> pumpAt(
    WidgetTester tester,
    Size size,
    Widget child,
  ) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(host(child: child));
    await tester.pumpAndSettle();
  }

  test('catalog grid columns follow available width', () {
    expect(HasimLayout.catalogGridCount(320), 1);
    expect(HasimLayout.catalogGridCount(360), 2);
    expect(HasimLayout.catalogGridCount(390), 2);
    expect(HasimLayout.catalogGridCount(430), 2);
    expect(HasimLayout.catalogGridCount(600), 3);
    expect(HasimLayout.catalogGridCount(768), 3);
    expect(HasimLayout.catalogGridCount(1024), 4);
    expect(HasimLayout.catalogGridCount(1200), 5);
  });

  test('form fields stay stacked until there is room for a pair', () {
    expect(HasimLayout.pairFormFields(320), isFalse);
    expect(HasimLayout.pairFormFields(430), isFalse);
    expect(HasimLayout.pairFormFields(559), isFalse);
    expect(HasimLayout.pairFormFields(560), isTrue);
    expect(HasimLayout.pairFormFields(768), isTrue);
    expect(HasimLayout.pairFormFields(1024), isTrue);
  });

  test('emoji and attachment grids shrink on compact phones', () {
    expect(HasimLayout.emojiGridCount(320), 8);
    expect(HasimLayout.emojiGridCount(200), 5);
    expect(HasimLayout.attachmentGridCount(320), 3);
    expect(HasimLayout.attachmentGridCount(390), 4);
    expect(HasimLayout.attachmentPanelHeight(320), 248);
    expect(HasimLayout.attachmentPanelHeight(390), 188);
  });

  for (final width in [320.0, 360.0, 390.0, 430.0]) {
    testWidgets('bottom nav stays usable at $width', (tester) async {
      await pumpAt(
        tester,
        Size(width, 720),
        HasimAdaptiveScaffold(
          selectedIndex: 0,
          onDestinationSelected: (_) {},
          onSettingsTap: () {},
          items: items,
          body: const SizedBox.expand(child: Text('محتوى')),
        ),
      );

      expect(find.byType(HasimBottomNav), findsOneWidget);
      expect(find.text('محتوى'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  for (final width in [320.0, 360.0, 390.0, 430.0, 600.0, 768.0, 1024.0]) {
    testWidgets('variant editor with Arabic names fits $width', (tester) async {
      await pumpAt(
        tester,
        Size(width, 1400),
        Scaffold(
          body: SingleChildScrollView(
          child: CatalogVariantsEditor(
            variants: const [
              ChatCatalogVariantModel(
                id: 1,
                name: 'مقاس كبير جداً مع اسم عربي طويل للمنتج',
                sku: 'SKU-AR-VERY-LONG-CODE-0001',
                price: 120,
                currency: 'SAR',
                inventoryPolicy: 'finite_stock',
                isActive: true,
              ),
            ],
            onChanged: (_) {},
          ),
          ),
        ),
      );

      expect(find.textContaining('مقاس كبير'), findsWidgets);
      expect(find.byKey(const Key('catalog-variant-remove-0')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('page header ellipsizes long Arabic titles on a 320 phone', (
    tester,
  ) async {
    await pumpAt(
      tester,
      const Size(320, 720),
      const Scaffold(
        body: HasimPageHeader(
        title: 'عنوان إعدادات طويل جداً يجب ألا يخرج من الحاوية على الهاتف',
        subtitle: 'وصف عربي طويل يوضح أن النص يلتف أو يُقطع دون كسر التخطيط',
        icon: Icons.settings_outlined,
      ),
      ),
    );

    expect(find.byType(HasimPageHeader), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('dialogs keep phone inset from the theme', (tester) async {
    await pumpAt(
      tester,
      const Size(320, 720),
      Scaffold(
        body: Builder(
        builder: (context) {
          return TextButton(
            onPressed: () {
              showDialog<void>(
                context: context,
                builder: (dialogContext) => const AlertDialog(
                  title: Text('حوار ضيق'),
                  content: Text(
                    'نص عربي طويل داخل الحوار للتأكد أنه لا يلامس حافة الشاشة',
                  ),
                ),
              );
            },
            child: const Text('افتح'),
          );
        },
        ),
      ),
    );

    await tester.tap(find.text('افتح'));
    await tester.pumpAndSettle();

    expect(find.text('حوار ضيق'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
