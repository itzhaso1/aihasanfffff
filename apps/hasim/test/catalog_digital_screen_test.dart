import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/catalog/data/chat_catalog_repository.dart';
import 'package:hasim/features/catalog/presentation/catalog_digital_screen.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _MemorySecureStore extends SecureStore {
  String? _token;
  @override
  Future<void> saveToken(String token) async => _token = token;
  @override
  Future<String?> readToken() async => _token;
  @override
  Future<void> clearToken() async => _token = null;
}

class _FakeDigitalRepository extends ChatCatalogRepository {
  _FakeDigitalRepository(super.api);

  String? reservedKey;
  int? reservedPoolId;
  String? releasedKey;
  int? releasedUnitId;
  String? consumedKey;
  int? consumedUnitId;

  @override
  Future<({List<Map<String, dynamic>> items, String? nextCursor})> digitalPools({
    String? cursor,
  }) async {
    return (
      items: [
        {'id': 7, 'name': 'حوض الأكواد', 'available_units_count': 2},
      ],
      nextCursor: null,
    );
  }

  @override
  Future<List<Map<String, dynamic>>> digitalUnits(int poolId) async {
    return [
      {'id': 11, 'status': 'available', 'unit_type': 'code'},
      {'id': 12, 'status': 'reserved', 'unit_type': 'code'},
    ];
  }

  @override
  Future<void> reserveDigitalUnit(
    int poolId, {
    required String reservationKey,
    int? ttlSeconds,
  }) async {
    reservedPoolId = poolId;
    reservedKey = reservationKey;
  }

  @override
  Future<void> releaseDigitalUnit(
    int unitId, {
    required String reservationKey,
  }) async {
    releasedUnitId = unitId;
    releasedKey = reservationKey;
  }

  @override
  Future<void> consumeDigitalUnit(
    int unitId, {
    required String reservationKey,
  }) async {
    consumedUnitId = unitId;
    consumedKey = reservationKey;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  Future<_FakeDigitalRepository> pumpDigital(WidgetTester tester) async {
    tester.view.physicalSize = const Size(400, 1200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    SharedPreferences.setMockInitialValues({});
    final preferences = await SharedPreferences.getInstance();
    final prefs = PrefsStore(preferences);
    final api = isolatedTestApiClient(
      secureStore: _MemorySecureStore(),
      prefsStore: prefs,
    );
    final repo = _FakeDigitalRepository(api);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(preferences),
          prefsStoreProvider.overrideWithValue(prefs),
          secureStoreProvider.overrideWithValue(_MemorySecureStore()),
          apiClientProvider.overrideWithValue(api),
          chatCatalogRepositoryProvider.overrideWithValue(repo),
          realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
        ],
        child: MaterialApp(
          theme: AppTheme.light(),
          locale: const Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: const CatalogDigitalScreen(embedded: true),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return repo;
  }

  testWidgets('digital inventory shows pool actions for the merchant', (
    tester,
  ) async {
    await pumpDigital(tester);

    expect(find.text('حوض الأكواد'), findsOneWidget);
    await tester.tap(find.byTooltip('خيارات الحوض'));
    await tester.pumpAndSettle();
    expect(find.text('إضافة أكواد'), findsOneWidget);
    expect(find.text('حجز وحدة'), findsOneWidget);
    expect(find.text('عرض الأكواد'), findsOneWidget);
    expect(find.text('حذف الحوض'), findsOneWidget);

    await tester.tap(find.text('عرض الأكواد'));
    await tester.pumpAndSettle();
    expect(find.text('وحدة #11'), findsOneWidget);
    await tester.tap(find.byTooltip('خيارات الوحدة').first);
    await tester.pumpAndSettle();
    expect(find.text('استبدال الكود'), findsOneWidget);
    expect(find.text('تعطيل'), findsOneWidget);
    expect(find.text('حذف'), findsOneWidget);
  });

  testWidgets('pool reserve asks for a reservation key before calling API', (
    tester,
  ) async {
    final repo = await pumpDigital(tester);

    await tester.tap(find.byTooltip('خيارات الحوض'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('حجز وحدة'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('digital-reservation-cancel')));
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    expect(repo.reservedKey, isNull);

    await tester.tap(find.byTooltip('خيارات الحوض'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('حجز وحدة'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('digital-reservation-key')),
      'hold-44',
    );
    await tester.tap(find.byKey(const Key('digital-reservation-confirm')));
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));

    expect(repo.reservedPoolId, 7);
    expect(repo.reservedKey, 'hold-44');
  });

  testWidgets('reserved units expose release and consume with confirmation key', (
    tester,
  ) async {
    final repo = await pumpDigital(tester);

    await tester.tap(find.byTooltip('خيارات الحوض'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('عرض الأكواد'));
    await tester.pumpAndSettle();
    expect(find.text('وحدة #12'), findsOneWidget);

    Future<void> openReservedMenu() async {
      final reservedTile = find.widgetWithText(ListTile, 'وحدة #12');
      await tester.ensureVisible(reservedTile);
      await tester.tap(
        find.descendant(
          of: reservedTile,
          matching: find.byTooltip('خيارات الوحدة'),
        ),
      );
      await tester.pumpAndSettle();
    }

    await openReservedMenu();
    expect(find.text('إلغاء الحجز'), findsOneWidget);
    expect(find.text('استهلاك'), findsOneWidget);
    expect(find.text('استبدال الكود'), findsNothing);

    await tester.tap(find.text('إلغاء الحجز'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('digital-reservation-key')),
      'hold-12',
    );
    await tester.tap(find.byKey(const Key('digital-reservation-confirm')));
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    expect(repo.releasedUnitId, 12);
    expect(repo.releasedKey, 'hold-12');

    await openReservedMenu();
    await tester.tap(find.text('استهلاك'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('digital-reservation-key')),
      'hold-12',
    );
    await tester.tap(find.byKey(const Key('digital-reservation-confirm')));
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    expect(repo.consumedUnitId, 12);
    expect(repo.consumedKey, 'hold-12');
  });
}
