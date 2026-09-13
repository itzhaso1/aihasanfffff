import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/cashier_api.dart';
import '../api/cashier_request_auth.dart';
import '../auth/auth_controller.dart';
import '../local_db/local_db_providers.dart';
import '../offline/pending_order.dart';
import '../pos/pos_mode.dart';
import 'kitchen_sync_copy.dart';
import 'pos_sync_coordinator.dart';

class AutoSyncStatus {
  const AutoSyncStatus({
    this.syncing = false,
    this.eligible = false,
    this.online = true,
    this.lastSuccessAt,
  });

  final bool syncing;
  final bool eligible;
  final bool online;
  final DateTime? lastSuccessAt;

  String label(DateTime now) {
    if (syncing) return KitchenSyncCopy.syncing;
    if (!eligible || !online) return KitchenSyncCopy.disconnected;
    final at = lastSuccessAt;
    if (at != null) {
      return KitchenSyncCopy.lastSyncSeconds(now.difference(at).inSeconds);
    }
    return KitchenSyncCopy.connected;
  }
}

class AutoSyncCycleResult {
  const AutoSyncCycleResult({
    required this.flush,
    this.ran = false,
  });

  const AutoSyncCycleResult.skippedUnlinked()
      : flush = const SyncFlushResult(skippedUnlinked: true),
        ran = false;

  const AutoSyncCycleResult.skippedStandalone()
      : flush = const SyncFlushResult(skippedStandalone: true),
        ran = false;

  final SyncFlushResult flush;
  final bool ran;

  String get kitchenMessage {
    if (flush.skippedStandalone ||
        flush.skippedUnlinked ||
        flush.networkError) {
      return KitchenSyncCopy.offline;
    }
    if (flush.authRequired || flush.failed > 0 || flush.pullFailed) {
      return KitchenSyncCopy.failed;
    }
    return KitchenSyncCopy.done;
  }
}

class AutoSyncController extends StateNotifier<AutoSyncStatus> {
  AutoSyncController(this._ref) : super(const AutoSyncStatus());

  final Ref _ref;

  Future<AutoSyncCycleResult> runCycle({
    bool manual = false,
    bool refreshAfterBusy = false,
  }) async {
    final session = _ref.read(authControllerProvider).valueOrNull;
    final cloud = _ref.read(cloudLinkSessionProvider);
    if (!CashierRequestAuth.canSync(
      sessionToken: session?.token,
      cloud: cloud,
    )) {
      state = AutoSyncStatus(
        lastSuccessAt: state.lastSuccessAt,
      );
      return const AutoSyncCycleResult.skippedUnlinked();
    }
    final workspaceId = CashierRequestAuth.workspaceId(
      sessionWorkspaceId: _ref.read(workspaceIdProvider),
      cloud: cloud,
    );
    if (workspaceId == null || workspaceId <= 0) {
      state = AutoSyncStatus(lastSuccessAt: state.lastSuccessAt);
      return const AutoSyncCycleResult.skippedUnlinked();
    }
    if (PosMode.isReservedStandaloneWorkspace(workspaceId)) {
      state = AutoSyncStatus(lastSuccessAt: state.lastSuccessAt);
      return const AutoSyncCycleResult.skippedStandalone();
    }
    final coordinator = _ref.read(posSyncCoordinatorProvider);
    if (!coordinator.allowNetwork) {
      state = AutoSyncStatus(lastSuccessAt: state.lastSuccessAt);
      return const AutoSyncCycleResult.skippedUnlinked();
    }

    state = AutoSyncStatus(
      syncing: true,
      eligible: true,
      online: state.online,
      lastSuccessAt: state.lastSuccessAt,
    );
    try {
      final deviceId =
          CashierRequestAuth.deviceId(
            sessionDeviceId: _ref.read(deviceIdHeaderProvider),
            cloud: cloud,
          ) ??
          await _ref.read(deviceIdentityProvider).getOrCreateDeviceId();
      final flush = await coordinator.flushPendingOrders(
        workspaceId: workspaceId,
        deviceId: deviceId,
        refreshAfterBusy: true,
      );
      final offline = flush.networkError ||
          flush.skippedUnlinked ||
          flush.skippedStandalone;
      final success = !offline &&
          !flush.authRequired &&
          !flush.pullFailed &&
          flush.failed == 0;
      state = AutoSyncStatus(
        syncing: false,
        eligible: true,
        online: !offline,
        lastSuccessAt: success ? DateTime.now() : state.lastSuccessAt,
      );
      return AutoSyncCycleResult(flush: flush, ran: true);
    } on ApiException catch (e) {
      final offline = e.isNetwork || e.isUnavailable;
      state = AutoSyncStatus(
        syncing: false,
        eligible: true,
        online: !offline,
        lastSuccessAt: state.lastSuccessAt,
      );
      return AutoSyncCycleResult(
        flush: SyncFlushResult(
          failed: 1,
          networkError: offline,
          authRequired: e.isUnauthorized,
          pullFailed: true,
        ),
        ran: true,
      );
    } catch (_) {
      state = AutoSyncStatus(
        syncing: false,
        eligible: true,
        online: state.online,
        lastSuccessAt: state.lastSuccessAt,
      );
      return const AutoSyncCycleResult(
        flush: SyncFlushResult(failed: 1, pullFailed: true),
        ran: true,
      );
    }
  }
}

final autoSyncStatusProvider =
    StateNotifierProvider<AutoSyncController, AutoSyncStatus>((ref) {
  return AutoSyncController(ref);
});
