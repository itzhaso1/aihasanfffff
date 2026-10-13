import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/features/catalog/presentation/catalog_digital_screen.dart';
import 'package:hasim/l10n/app_localizations.dart';

void main() {
  testWidgets('digital inventory screen is retired', (tester) async {
    await tester.pumpWidget(
      const ProviderScope(
        child: MaterialApp(
          locale: Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: CatalogDigitalScreen(embedded: true),
        ),
      ),
    );

    expect(find.byKey(const Key('digital-inventory-retired')), findsOneWidget);
    expect(find.textContaining('المخزون الرقمي وتسليم الملفات عبر الشات ملغيان'), findsOneWidget);
    expect(find.byKey(const Key('digital-reservation-key')), findsNothing);
    expect(find.text('حوض الأكواد'), findsNothing);
  });
}
