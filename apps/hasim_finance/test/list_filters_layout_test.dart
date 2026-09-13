import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/features/dashboard/dashboard_screen.dart';
import 'package:hasim_finance/features/invoices/invoices_screens.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'helpers.dart';

class _RecordingApi extends FakeFinanceApi {
  _RecordingApi(super.client);

  final calls = <Map<String, Object?>>[];
  bool empty = false;
  String? failWith;

  @override
  Future<PagedResult<InvoiceRecord>> invoices({
    String? search,
    String? paymentStatus,
    String? invoiceStatus,
    String? lifecycle,
    int? customerId,
    String? from,
    String? to,
    String? currency,
    int? projectId,
    int? contractId,
    String? paymentMethod,
    String? sort,
    String? direction,
    int page = 1,
  }) async {
    calls.add({'search': search, 'lifecycle': lifecycle, 'paymentStatus': paymentStatus, 'page': page});
    final error = failWith;
    if (error != null) throw ApiException(error, statusCode: 500);
    if (empty) return const PagedResult(items: [], page: 1, lastPage: 1, total: 0);
    return super.invoices(search: search, page: page);
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late SharedPreferences prefs;
  late _RecordingApi api;

  setUp(() async {
    prefs = await mockPrefs();
    api = _RecordingApi(testClient(prefs));
  });

  Future<void> pumpAt(WidgetTester tester, Widget child, Size size) async {
    tester.view.physicalSize = size;
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

  testWidgets('invoices render without overflow on a short desktop viewport', (tester) async {
    await pumpAt(tester, const InvoicesScreen(), const Size(1280, 560));

    expect(tester.takeException(), isNull);
    expect(find.byKey(const Key('invoices-apply-filters')), findsOneWidget);
    // Filters live in the same scroll view as the rows, so the list is reachable.
    await tester.drag(find.byType(CustomScrollView), const Offset(0, -600));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.text('INV-11'), findsOneWidget);
  });

  testWidgets('invoices collapse advanced filters on mobile and stay overflow-free', (tester) async {
    await pumpAt(tester, const InvoicesScreen(), const Size(390, 720));

    expect(tester.takeException(), isNull);
    expect(find.byKey(const Key('invoices-apply-filters')), findsNothing);

    await tester.tap(find.byKey(const Key('invoices-advanced-toggle')));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.byKey(const Key('invoices-apply-filters')), findsOneWidget);

    // Every filter control must stay inside the viewport horizontally (RTL).
    for (final element in find.byType(TextField).evaluate()) {
      final rect = tester.getRect(find.byWidget(element.widget));
      expect(rect.left, greaterThanOrEqualTo(0));
      expect(rect.right, lessThanOrEqualTo(390));
    }
  });

  testWidgets('changing a filter reloads while keeping the search text', (tester) async {
    await pumpAt(tester, const InvoicesScreen(), const Size(1280, 900));

    await tester.enterText(find.byKey(const Key('paged-search')), 'INV');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pump();

    await tester.tap(find.widgetWithText(ChoiceChip, 'المدفوع'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(api.calls.last['lifecycle'], 'paid');
    expect(api.calls.last['search'], 'INV');
    expect(api.calls.last['page'], 1);
    expect(tester.widget<TextField>(find.byKey(const Key('paged-search'))).controller!.text, 'INV');

    await tester.tap(find.byKey(const Key('invoices-reset-filters')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(api.calls.last['lifecycle'], isNull);
  });

  testWidgets('invoices show a dedicated empty state with a create action', (tester) async {
    api.empty = true;
    await pumpAt(tester, const InvoicesScreen(), const Size(1280, 900));

    expect(find.text('لا توجد فواتير بعد'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'فاتورة جديدة'), findsOneWidget);

    // With an active filter the copy switches to "no results".
    await tester.tap(find.widgetWithText(ChoiceChip, 'المدفوع'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(find.text('لا توجد نتائج'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'فاتورة جديدة'), findsNothing);
  });

  testWidgets('invoices show API errors with a working retry', (tester) async {
    api.failWith = 'خطأ في الخادم';
    await pumpAt(tester, const InvoicesScreen(), const Size(1280, 900));

    expect(find.text('خطأ في الخادم'), findsOneWidget);
    api.failWith = null;
    await tester.tap(find.text('إعادة المحاولة'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text('خطأ في الخادم'), findsNothing);
    expect(find.text('INV-11'), findsOneWidget);
  });

  testWidgets('dashboard filter actions never overlap the filter fields', (tester) async {
    for (final width in [1024.0, 1366.0]) {
      await pumpAt(tester, const DashboardScreen(), Size(width, 900));
      expect(tester.takeException(), isNull);

      final apply = tester.getRect(find.widgetWithText(FilledButton, 'تطبيق التصفية'));
      final reset = tester.getRect(find.widgetWithText(OutlinedButton, 'إعادة تعيين التصفية'));
      for (final element in find.byType(DropdownButtonFormField<int?>).evaluate()) {
        final field = tester.getRect(find.byWidget(element.widget));
        expect(field.overlaps(apply), isFalse, reason: 'field overlaps apply at $width');
        expect(field.overlaps(reset), isFalse, reason: 'field overlaps reset at $width');
      }
    }
  });

  testWidgets('dashboard date filters open a date picker instead of free text', (tester) async {
    await pumpAt(tester, const DashboardScreen(), const Size(1280, 900));

    final from = find.byType(TextField).first;
    expect(tester.widget<TextField>(from).readOnly, isTrue);
    await tester.tap(from);
    await tester.pumpAndSettle();
    expect(find.byType(DatePickerDialog), findsOneWidget);
  });
}
