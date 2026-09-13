import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/api/cashier_api.dart';
import 'package:hasim_cashier/core/auth/auth_controller.dart';
import 'package:hasim_cashier/core/auth/cloud_link_store.dart';
import 'package:hasim_cashier/core/config/app_config.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/local_db/local_db_providers.dart';
import 'package:hasim_cashier/core/offline/offline_store.dart';
import 'package:hasim_cashier/core/offline/pending_order.dart';
import 'package:hasim_cashier/core/offline/sync_engine.dart';
import 'package:hasim_cashier/core/pos/pos_mode.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';
import 'package:hasim_cashier/core/sync/auto_sync_controller.dart';
import 'package:hasim_cashier/core/sync/auto_sync_host.dart';
import 'package:hasim_cashier/core/sync/kitchen_sync_copy.dart';
import 'package:hasim_cashier/core/sync/pos_sync_coordinator.dart';
import 'package:hasim_cashier/core/sync/single_flight.dart';
import 'package:hasim_cashier/core/sync/sync_engine_v2.dart';
import 'package:hasim_cashier/core/sync/sync_pull_applier.dart';
import 'package:hasim_cashier/features/kitchen/kitchen_board.dart';
import 'package:drift/drift.dart' hide isNull, isNotNull;

class _HiveSpy extends SyncEngine {
  _HiveSpy() : super(OfflineStore.instance);

  var flushCalls = 0;

  @override
  Future<SyncFlushResult> flushPendingOrders({int? workspaceId}) async {
    flushCalls++;
    return const SyncFlushResult(keptPending: 54);
  }
}

class _CountEngine extends SyncEngineV2 {
  _CountEngine(AppDatabase db, {this.delay = Duration.zero})
      : super(db, SyncQueueRepository(db));

  int calls = 0;
  Duration delay;
  var gate = false;
  final holds = <Completer<void>>[];

  @override
  Future<SyncEngineV2Result> syncBidirectional({
    required int workspaceId,
    String? deviceId,
  }) async {
    calls++;
    if (gate) {
      final hold = Completer<void>();
      holds.add(hold);
      await hold.future;
    } else if (delay > Duration.zero) {
      await Future<void>.delayed(delay);
    }
    return const SyncEngineV2Result(pulled: 1);
  }
}

class _ThrowNetworkEngine extends SyncEngineV2 {
  _ThrowNetworkEngine(AppDatabase db)
      : super(db, SyncQueueRepository(db));

  @override
  Future<SyncEngineV2Result> syncBidirectional({
    required int workspaceId,
    String? deviceId,
  }) async {
    throw ApiException('socket', statusCode: 0);
  }
}

class _CountingAutoSync extends AutoSyncController {
  _CountingAutoSync(super.ref, {required this.onCycle});

  final VoidCallback onCycle;

  @override
  Future<AutoSyncCycleResult> runCycle({
    bool manual = false,
    bool refreshAfterBusy = false,
  }) async {
    onCycle();
    return const AutoSyncCycleResult(flush: SyncFlushResult(), ran: true);
  }
}

class _FixedAutoSync extends AutoSyncController {
  _FixedAutoSync(super.ref, this.result);

  final AutoSyncCycleResult result;

  @override
  Future<AutoSyncCycleResult> runCycle({
    bool manual = false,
    bool refreshAfterBusy = false,
  }) async {
    return result;
  }
}

class _SilentAuthRepository extends AuthRepository {
  _SilentAuthRepository(super.api, super.storage);

  @override
  Future<AuthSession?> restore() async => null;

  @override
  Future<void> logout() async {}
}

const _linked = CloudLinkSnapshot(
  token: 'sanctum-live',
  workspaceId: 12,
  userId: 4,
  deviceId: 'dev-1',
  posEnabled: true,
  deviceRegistered: true,
);

void main() {
  setUp(() {
    AppConfig.autoSyncEnabled = true;
  });

  tearDown(() {
    AppConfig.autoSyncEnabled = true;
  });

  test('auto sync interval is 5 seconds', () {
    expect(AppConfig.autoSyncSeconds, 5);
    expect(AppConfig.autoSyncSeconds >= 5, isTrue);
  });

  test('single-flight callers join the same future', () async {
    final flight = SingleFlight<int>();
    var runs = 0;
    final gate = Completer<void>();
    Future<int> job() async {
      runs++;
      await gate.future;
      return 7;
    }

    final first = flight.run(job);
    final second = flight.run(job);
    expect(flight.isInFlight, isTrue);
    expect(runs, 1);
    gate.complete();
    expect(await first, 7);
    expect(await second, 7);
    expect(runs, 1);
    expect(flight.isInFlight, isFalse);

    expect(await flight.run(job), 7);
    expect(runs, 2);
  });

  test('coordinator skips reserved standalone workspace 900001', () async {
    final db = AppDatabase.memory();
    addTearDown(db.close);
    final engine = _CountEngine(db);
    final coordinator = PosSyncCoordinator(
      hiveEngine: _HiveSpy(),
      sqliteEngine: engine,
    );
    final result = await coordinator.flushPendingOrders(
      workspaceId: PosMode.standaloneWorkspaceId,
      deviceId: 'dev-1',
    );
    expect(result.skippedStandalone, isTrue);
    expect(engine.calls, 0);
  });

  test('coordinator skips unlinked allowNetwork=false', () async {
    final db = AppDatabase.memory();
    addTearDown(db.close);
    final engine = _CountEngine(db);
    final coordinator = PosSyncCoordinator(
      hiveEngine: _HiveSpy(),
      sqliteEngine: engine,
      allowNetwork: false,
    );
    final result = await coordinator.flushPendingOrders(
      workspaceId: 12,
      deviceId: 'dev-1',
    );
    expect(result.skippedUnlinked, isTrue);
    expect(engine.calls, 0);
  });

  test('concurrent flushPendingOrders do not start a second engine job', () async {
    final db = AppDatabase.memory();
    addTearDown(db.close);
    final engine = _CountEngine(db, delay: const Duration(milliseconds: 80));
    final coordinator = PosSyncCoordinator(
      hiveEngine: _HiveSpy(),
      sqliteEngine: engine,
    );
    final first = coordinator.flushPendingOrders(workspaceId: 12, deviceId: 'd');
    final second = coordinator.flushPendingOrders(workspaceId: 12, deviceId: 'd');
    final results = await Future.wait([first, second]);
    expect(engine.calls, 1);
    expect(results[0].pulled, 1);
    expect(results[1].pulled, 1);
  });

  test('manual refreshAfterBusy runs one more cycle after the in-flight job',
      () async {
    final db = AppDatabase.memory();
    addTearDown(db.close);
    final engine = _CountEngine(db, delay: const Duration(milliseconds: 40));
    final coordinator = PosSyncCoordinator(
      hiveEngine: _HiveSpy(),
      sqliteEngine: engine,
    );
    final auto = coordinator.flushPendingOrders(workspaceId: 12, deviceId: 'd');
    await Future<void>.delayed(Duration.zero);
    final manual = coordinator.flushPendingOrders(
      workspaceId: 12,
      deviceId: 'd',
      refreshAfterBusy: true,
    );
    await Future.wait([auto, manual]);
    expect(engine.calls, 2);
  });

  test('network errors stay silent and do not throw from the coordinator',
      () async {
    final db = AppDatabase.memory();
    addTearDown(db.close);
    final coordinator = PosSyncCoordinator(
      hiveEngine: _HiveSpy(),
      sqliteEngine: _ThrowNetworkEngine(db),
    );
    final result = await coordinator.flushPendingOrders(
      workspaceId: 12,
      deviceId: 'dev-1',
    );
    expect(result.networkError, isTrue);
    expect(result.pullFailed, isTrue);
  });

  test('engine pull network failure sets networkError without dropping queue',
      () async {
    final db = AppDatabase.memory();
    addTearDown(db.close);
    final queue = SyncQueueRepository(db);
    await queue.enqueue(
      workspaceId: 12,
      deviceId: 'dev',
      entityType: 'invoice',
      entityId: 'inv-1',
      operation: 'create',
      payload: {'order_local_id': 'missing'},
      clientReference: 'inv-1',
    );
    final engine = SyncEngineV2(
      db,
      queue,
      fetchChanges: (since, limit) async {
        throw ApiException('offline', statusCode: 0);
      },
    );
    final result = await engine.syncBidirectional(workspaceId: 12);
    expect(result.networkError, isTrue);
    expect(result.pullFailed, isTrue);
    expect(await queue.pendingCount(12), 1);
  });

  test('kitchen pull upserts the same order by client_reference then server_id',
      () async {
    final db = AppDatabase.memory();
    addTearDown(db.close);
    final applier = SyncPullApplier(db);
    const change = {
      'version': 4,
      'entity': 'order',
      'operation': 'create',
      'id': 44,
      'data': {
        'id': 44,
        'client_reference': 'ord-kitchen-1',
        'order_type': 'takeaway',
        'pos_status': 'new',
        'payment_status': 'paid',
        'items': [
          {'id': 1, 'name': 'برجر', 'quantity': 1, 'unit_price': 10},
        ],
      },
    };
    await applier.applyBatch(
      workspaceId: 12,
      fromCursor: 0,
      responseCursor: 4,
      changes: [change],
    );
    await applier.applyBatch(
      workspaceId: 12,
      fromCursor: 4,
      responseCursor: 5,
      changes: [
        {
          ...change,
          'version': 5,
          'data': {
            ...change['data']! as Map<String, dynamic>,
            'pos_status': 'preparing',
          },
        },
      ],
    );
    final rows = await (db.select(db.localOrders)
          ..where((t) => t.workspaceId.equals(12)))
        .get();
    expect(rows, hasLength(1));
    expect(rows.first.clientReference, 'ord-kitchen-1');
    expect(rows.first.serverId, 44);
    expect(rows.first.posStatus, 'preparing');
  });

  test('kitchen copy matches the required Arabic statuses', () {
    expect(KitchenSyncCopy.button, 'مزامنة الآن');
    expect(KitchenSyncCopy.syncing, 'جاري المزامنة...');
    expect(KitchenSyncCopy.done, 'تمت المزامنة');
    expect(KitchenSyncCopy.failed, 'تعذر المزامنة');
    expect(
      KitchenSyncCopy.offline,
      'لا يوجد اتصال بالإنترنت — تم الاحتفاظ بالبيانات محليًا.',
    );
    expect(KitchenSyncCopy.connected, 'متصل');
    expect(KitchenSyncCopy.disconnected, 'غير متصل');
    expect(
      KitchenSyncCopy.lastSyncSeconds(8),
      'آخر مزامنة: قبل 8 ثواني',
    );
  });

  testWidgets('auto sync ticks immediately then every 5 seconds', (tester) async {
    var calls = 0;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          autoSyncStatusProvider.overrideWith(
            (ref) => _CountingAutoSync(ref, onCycle: () => calls++),
          ),
        ],
        child: const AutoSyncHost(child: SizedBox.expand()),
      ),
    );
    await tester.pump();
    expect(calls, 1);
    await tester.pump(const Duration(seconds: 4));
    expect(calls, 1);
    await tester.pump(const Duration(seconds: 1));
    expect(calls, 2);
    await tester.pump(const Duration(seconds: 5));
    expect(calls, 3);
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(milliseconds: 20));
  });

  testWidgets('auto sync timer stops while the app is paused', (tester) async {
    var calls = 0;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          autoSyncStatusProvider.overrideWith(
            (ref) => _CountingAutoSync(ref, onCycle: () => calls++),
          ),
        ],
        child: const AutoSyncHost(child: SizedBox.expand()),
      ),
    );
    await tester.pump();
    expect(calls, 1);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    await tester.pump();
    await tester.pump(const Duration(seconds: 15));
    expect(calls, 1);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();
    expect(calls, 2);
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(milliseconds: 20));
  });

  testWidgets('kitchen board shows مزامنة الآن without starting auto sync',
      (tester) async {
    final db = AppDatabase.memory();
    addTearDown(db.close);
    AppConfig.autoSyncEnabled = false;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [appDatabaseProvider.overrideWith((ref) => db)],
        child: const MaterialApp(
          locale: Locale('ar'),
          supportedLocales: [Locale('ar'), Locale('en')],
          localizationsDelegates: [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          home: Scaffold(body: KitchenBoard()),
        ),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    expect(find.text(KitchenSyncCopy.button), findsOneWidget);
    expect(find.text(KitchenSyncCopy.disconnected), findsOneWidget);
    expect(find.byKey(const ValueKey('kitchen-sync-now')), findsOneWidget);
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(milliseconds: 20));
  });

  testWidgets('kitchen manual sync shows offline copy when unlinked',
      (tester) async {
    final db = AppDatabase.memory();
    addTearDown(db.close);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          appDatabaseProvider.overrideWith((ref) => db),
          autoSyncStatusProvider.overrideWith(
            (ref) => _FixedAutoSync(
              ref,
              const AutoSyncCycleResult.skippedUnlinked(),
            ),
          ),
        ],
        child: const MaterialApp(
          locale: Locale('ar'),
          supportedLocales: [Locale('ar'), Locale('en')],
          localizationsDelegates: [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          home: Scaffold(body: KitchenBoard()),
        ),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    await tester.tap(find.byKey(const ValueKey('kitchen-sync-now')));
    await tester.pump();
    await tester.pump();
    expect(find.text(KitchenSyncCopy.offline), findsOneWidget);
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(milliseconds: 20));
  });

  testWidgets('kitchen manual sync shows done after a successful cycle',
      (tester) async {
    final db = AppDatabase.memory();
    addTearDown(db.close);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          appDatabaseProvider.overrideWith((ref) => db),
          autoSyncStatusProvider.overrideWith(
            (ref) => _FixedAutoSync(
              ref,
              const AutoSyncCycleResult(
                flush: SyncFlushResult(pulled: 2),
                ran: true,
              ),
            ),
          ),
        ],
        child: const MaterialApp(
          locale: Locale('ar'),
          supportedLocales: [Locale('ar'), Locale('en')],
          localizationsDelegates: [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          home: Scaffold(body: KitchenBoard()),
        ),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    await tester.tap(find.byKey(const ValueKey('kitchen-sync-now')));
    await tester.pump();
    await tester.pump();
    expect(find.text(KitchenSyncCopy.done), findsOneWidget);
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(milliseconds: 20));
  });

  testWidgets(
    'auto sync + manual press share one engine job until follow-up',
    (tester) async {
      final db = AppDatabase.memory();
      addTearDown(db.close);
      final store = CloudLinkStore.memory();
      await store.save(_linked);
      final engine = _CountEngine(db)..gate = true;
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            appDatabaseProvider.overrideWith((ref) => db),
            cloudLinkStoreProvider.overrideWithValue(store),
            cloudLinkSessionProvider.overrideWith((ref) => _linked),
            workspaceIdProvider.overrideWith((ref) => 12),
            deviceIdHeaderProvider.overrideWith((ref) => 'dev-1'),
            authRepositoryProvider.overrideWith(
              (ref) => _SilentAuthRepository(
                ref.watch(cashierApiProvider),
                ref.watch(secureStorageProvider),
              ),
            ),
            posSyncCoordinatorProvider.overrideWith((ref) {
              return PosSyncCoordinator(
                hiveEngine: _HiveSpy(),
                sqliteEngine: engine,
                flight: ref.watch(syncFlightProvider),
                allowNetwork: true,
              );
            }),
          ],
          child: const AutoSyncHost(child: SizedBox.expand()),
        ),
      );
      await tester.pump();
      expect(engine.calls, 1);
      expect(engine.holds, hasLength(1));
      final container = ProviderScope.containerOf(
        tester.element(find.byType(AutoSyncHost)),
      );
      final manual = container
          .read(autoSyncStatusProvider.notifier)
          .runCycle(manual: true);
      await tester.pump();
      expect(engine.calls, 1);
      engine.holds[0].complete();
      await tester.pump();
      expect(engine.calls, 2);
      expect(engine.holds, hasLength(2));
      engine.holds[1].complete();
      await tester.pump();
      await manual;
      expect(engine.calls, 2);
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(milliseconds: 20));
    },
  );

  testWidgets('paused auto sync does not hit the engine', (tester) async {
    final db = AppDatabase.memory();
    addTearDown(db.close);
    final store = CloudLinkStore.memory();
    await store.save(_linked);
    final engine = _CountEngine(db);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          appDatabaseProvider.overrideWith((ref) => db),
          cloudLinkStoreProvider.overrideWithValue(store),
          cloudLinkSessionProvider.overrideWith((ref) => _linked),
          workspaceIdProvider.overrideWith((ref) => 12),
          deviceIdHeaderProvider.overrideWith((ref) => 'dev-1'),
          authRepositoryProvider.overrideWith(
            (ref) => _SilentAuthRepository(
              ref.watch(cashierApiProvider),
              ref.watch(secureStorageProvider),
            ),
          ),
          posSyncCoordinatorProvider.overrideWith((ref) {
            return PosSyncCoordinator(
              hiveEngine: _HiveSpy(),
              sqliteEngine: engine,
              flight: ref.watch(syncFlightProvider),
              allowNetwork: true,
            );
          }),
        ],
        child: const AutoSyncHost(child: SizedBox.expand()),
      ),
    );
    await tester.pump();
    expect(engine.calls, 1);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    await tester.pump();
    await tester.pump(const Duration(seconds: 12));
    expect(engine.calls, 1);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();
    expect(engine.calls, 2);
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(milliseconds: 20));
  });
}
