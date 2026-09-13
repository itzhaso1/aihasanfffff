import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/cashier_api.dart';
import '../api/cashier_request_auth.dart';
import '../auth/auth_controller.dart';
import '../config/app_config.dart';
import '../local_db/local_db_providers.dart';
import '../offline/pending_order.dart';
import '../offline/sync_engine.dart';
import '../pos/pos_mode.dart';
import 'single_flight.dart';
import 'sync_engine_v2.dart';

/// Primary sync path is SyncEngineV2. Legacy Hive flush runs only to drain
/// leftover pending rows until migration completes (no POS dual-write).
class PosSyncCoordinator {
  PosSyncCoordinator({
    required SyncEngine hiveEngine,
    required SyncEngineV2 sqliteEngine,
    this.allowNetwork = true,
    SingleFlight<SyncFlushResult>? flight,
  }) : _hive = hiveEngine,
       _sqlite = sqliteEngine,
       _flight = flight ?? SingleFlight<SyncFlushResult>();

  final SyncEngine _hive;
  final SyncEngineV2 _sqlite;
  final SingleFlight<SyncFlushResult> _flight;
  final bool allowNetwork;

  bool get isFlushInFlight => _flight.isInFlight;

  /// Push in-contract queue rows then pull. Concurrent callers join the same
  /// in-flight cycle instead of starting a second job.
  ///
  /// [refreshAfterBusy] is for a manual "sync now": if a cycle is already
  /// running, wait for it, then run one more so the user is not served a
  /// pull that already finished before they pressed the button.
  Future<SyncFlushResult> flushPendingOrders({
    int? workspaceId,
    String? deviceId,
    bool refreshAfterBusy = false,
  }) async {
    final wasBusy = _flight.isInFlight;
    final result = await _flight.run(
      () => _flushOnce(workspaceId: workspaceId, deviceId: deviceId),
    );
    if (refreshAfterBusy && wasBusy) {
      return _flight.run(
        () => _flushOnce(workspaceId: workspaceId, deviceId: deviceId),
      );
    }
    return result;
  }

  Future<SyncFlushResult> _flushOnce({
    int? workspaceId,
    String? deviceId,
  }) async {
    if (!allowNetwork) {
      return const SyncFlushResult(skippedUnlinked: true);
    }
    if (workspaceId == null || workspaceId <= 0) {
      return const SyncFlushResult(skippedUnlinked: true);
    }
    if (PosMode.isReservedStandaloneWorkspace(workspaceId)) {
      return const SyncFlushResult(skippedStandalone: true);
    }
    try {
      // Hive leftover drain posts `/orders`, which offlineOnly forbids.
      // SQLite SyncEngineV2 /sync/push is the only POS envelope.
      final hive = AppConfig.offlineOnly
          ? const SyncFlushResult()
          : await _hive.flushPendingOrders(workspaceId: workspaceId);
      final sqlite = await _sqlite.syncBidirectional(
        workspaceId: workspaceId,
        deviceId: deviceId,
      );
      return SyncFlushResult(
        synced: hive.synced + sqlite.synced,
        failed: hive.failed + sqlite.failed,
        keptPending: hive.keptPending + sqlite.keptPending,
        authRequired: hive.authRequired || sqlite.authRequired,
        skippedInFlight: hive.skippedInFlight || sqlite.skippedInFlight,
        pulled: sqlite.pulled,
        pullFailed: sqlite.pullFailed,
        networkError: sqlite.networkError,
      );
    } on ApiException catch (e) {
      return SyncFlushResult(
        failed: 1,
        authRequired: e.isUnauthorized,
        networkError: e.isNetwork || e.isUnavailable,
        pullFailed: true,
      );
    } catch (_) {
      return const SyncFlushResult(failed: 1, pullFailed: true);
    }
  }

  Future<bool> retryOne(
    String localId, {
    int? workspaceId,
    String? deviceId,
  }) async {
    if (!allowNetwork) return false;
    if (workspaceId != null &&
        PosMode.isReservedStandaloneWorkspace(workspaceId)) {
      return false;
    }
    final hiveOk = AppConfig.offlineOnly
        ? false
        : await _hive.retryOne(localId, workspaceId: workspaceId);
    if (workspaceId == null || workspaceId <= 0) return hiveOk;
    final sqlite = await flushPendingOrders(
      workspaceId: workspaceId,
      deviceId: deviceId,
    );
    return hiveOk || sqlite.synced > 0;
  }
}

final syncEngineV2Provider = Provider<SyncEngineV2>((ref) {
  return SyncEngineV2(
    ref.watch(appDatabaseProvider),
    ref.watch(syncQueueRepositoryProvider),
    api: ref.watch(cashierApiProvider),
    pullApplier: ref.watch(syncPullApplierProvider),
  );
});

/// Shared lock so auto-sync, Kitchen, Settings, and checkout flush join one job.
final syncFlightProvider = Provider<SingleFlight<SyncFlushResult>>((ref) {
  return SingleFlight<SyncFlushResult>();
});

final posSyncCoordinatorProvider = Provider<PosSyncCoordinator>((ref) {
  final session = ref.watch(authControllerProvider).valueOrNull;
  final cloud = ref.watch(cloudLinkSessionProvider);
  return PosSyncCoordinator(
    hiveEngine: ref.watch(syncEngineProvider),
    sqliteEngine: ref.watch(syncEngineV2Provider),
    flight: ref.watch(syncFlightProvider),
    // Phase 2B: Sanctum + registered device may push takeaway order.created
    // even while checkout stays SQLite-first / offlineOnly / PIN session.
    allowNetwork: CashierRequestAuth.canSync(
      sessionToken: session?.token,
      cloud: cloud,
    ),
  );
});
