import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/local_db/local_db_providers.dart';
import 'package:hasim_cashier/core/pos/application/local_auth_service.dart';
import 'package:hasim_cashier/core/pos/pos_mode.dart';
import 'package:hasim_cashier/features/auth/login_screen.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late AppDatabase db;
  late LocalAuthService auth;

  setUp(() {
    db = AppDatabase.memory();
    auth = LocalAuthService(db);
  });

  tearDown(() async {
    await db.close();
  });

  testWidgets('first run opens Hasim login instead of standalone setup', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(800, 1200);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final router = GoRouter(
      initialLocation: '/login',
      routes: [
        GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
        GoRoute(
          path: '/standalone-setup',
          builder: (_, __) => const Scaffold(body: Text('setup-screen')),
        ),
      ],
    );
    await tester.pumpWidget(
      ProviderScope(
        overrides: [appDatabaseProvider.overrideWith((ref) => db)],
        child: MaterialApp.router(
          locale: const Locale('ar'),
          supportedLocales: const [Locale('ar'), Locale('en')],
          localizationsDelegates: const [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          routerConfig: router,
        ),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pump();

    expect(find.text('setup-screen'), findsNothing);
    expect(find.text('حساب حاسم / Laravel'), findsOneWidget);
    expect(find.text('ربط الجهاز'), findsOneWidget);
    expect(find.text('الدخول عبر Google'), findsOneWidget);
    expect(find.byKey(const ValueKey('hasim-smart-logo')), findsOneWidget);
    expect(find.text('إعداد مستقل بدون حساب حاسم'), findsNothing);
    expect(find.text('العودة لتسجيل الدخول المحلي'), findsNothing);
    expect(find.text('دخول الكاشير'), findsNothing);
  });

  test('Hasim bind creates a local unlock user without a third identity table', () async {
    expect(await auth.anyStore(), isNull);

    final created = await auth.bootstrapUnlockUserFromHasim(
      email: 'owner@hasim.test',
      displayName: 'مالك حاسم',
      password: 'secret-pass',
      storeName: 'مطعم حاسم',
    );

    expect(created, isNotNull);
    expect(created!.store.workspaceId, PosMode.standaloneWorkspaceId);
    expect(created.user.username, 'owner@hasim.test');
    expect(created.user.role, 'admin');

    final unlocked = await auth.login(
      workspaceId: created.store.workspaceId,
      username: 'owner@hasim.test',
      pin: 'secret-pass',
    );
    expect(unlocked.localId, created.user.localId);

    final again = await auth.bootstrapUnlockUserFromHasim(
      email: 'owner@hasim.test',
      displayName: 'اسم جديد',
      password: 'other-pass',
      storeName: 'مطعم حاسم',
    );
    expect(again!.user.localId, created.user.localId);

    final stillOldPin = await auth.login(
      workspaceId: created.store.workspaceId,
      username: 'owner@hasim.test',
      pin: 'secret-pass',
    );
    expect(stillOldPin.localId, created.user.localId);
  });

  test('existing standalone store gets a Hasim unlock user on the same store', () async {
    final stand = await auth.bootstrapStore(
      storeName: 'محلي',
      adminName: 'قديم',
      username: 'old@local.test',
      pin: '1234',
    );

    final linked = await auth.bootstrapUnlockUserFromHasim(
      email: 'owner@hasim.test',
      displayName: 'مالك حاسم',
      password: 'secret-pass',
    );

    expect(linked!.store.localId, stand.store.localId);
    expect(linked.user.username, 'owner@hasim.test');
    expect(
      (await auth.login(
        workspaceId: stand.store.workspaceId,
        username: 'old@local.test',
        pin: '1234',
      )).localId,
      stand.user.localId,
    );
  });
}
