import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/api/cashier_api.dart';
import 'package:hasim_cashier/core/api/cashier_network_policy.dart';
import 'package:hasim_cashier/core/api/cashier_request_auth.dart';
import 'package:hasim_cashier/core/auth/auth_controller.dart';
import 'package:hasim_cashier/core/auth/cloud_link_store.dart';
import 'package:hasim_cashier/core/config/app_config.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/local_db/local_db_providers.dart';
import 'package:hasim_cashier/core/pos/pos_mode.dart';
import 'package:hasim_cashier/core/sync/pos_sync_coordinator.dart';

class _SilentAuthRepository extends AuthRepository {
  _SilentAuthRepository(super.api, super.storage);

  @override
  Future<AuthSession?> restore() async => null;

  @override
  Future<void> logout() async {}
}

void main() {
  const linked = CloudLinkSnapshot(
    token: 'sanctum-live',
    workspaceId: 12,
    userId: 4,
    deviceId: 'dev-1',
    posEnabled: true,
    deviceRegistered: true,
  );

  test('PIN session + cloud link uses Sanctum for takeaway push', () {
    expect(AppConfig.offlineOnly, isTrue);
    expect(
      CashierRequestAuth.canSync(
        sessionToken: 'standalone:user-1',
        cloud: linked,
      ),
      isTrue,
    );
    expect(
      CashierRequestAuth.bearerToken(
        sessionToken: 'standalone:user-1',
        cloud: linked,
      ),
      'sanctum-live',
    );
    expect(
      CashierRequestAuth.workspaceId(
        sessionWorkspaceId: PosMode.standaloneWorkspaceId,
        cloud: linked,
      ),
      12,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: CashierRequestAuth.bearerToken(
          sessionToken: 'standalone:user-1',
          cloud: linked,
        ),
        path: '/sync/push',
        method: 'POST',
      ),
      isTrue,
    );
  });

  test('PIN session without cloud link still cannot push', () {
    expect(
      CashierRequestAuth.canSync(
        sessionToken: 'standalone:user-1',
        cloud: null,
      ),
      isFalse,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: CashierRequestAuth.bearerToken(
          sessionToken: 'standalone:user-1',
          cloud: null,
        ),
        path: '/sync/push',
        method: 'POST',
      ),
      isFalse,
    );
  });

  test('incomplete cloud snapshot is not treated as linked', () {
    const half = CloudLinkSnapshot(
      token: 'sanctum-only',
      workspaceId: 12,
      posEnabled: true,
    );
    expect(half.isLinked, isFalse);
    expect(CashierRequestAuth.activeLink(half), isNull);
    expect(
      CashierRequestAuth.canSync(
        sessionToken: 'standalone:user-1',
        cloud: half,
      ),
      isFalse,
    );
  });

  test('bootstrap hydrates cloud link while PIN session stays logged out', () async {
    final db = AppDatabase.memory();
    addTearDown(db.close);
    final store = CloudLinkStore.memory();
    await store.save(linked);

    final container = ProviderContainer(
      overrides: [
        appDatabaseProvider.overrideWith((ref) => db),
        cloudLinkStoreProvider.overrideWithValue(store),
        authRepositoryProvider.overrideWith(
          (ref) => _SilentAuthRepository(
            ref.watch(cashierApiProvider),
            ref.watch(secureStorageProvider),
          ),
        ),
      ],
    );
    addTearDown(container.dispose);

    container.read(authControllerProvider);
    for (var i = 0; i < 40; i++) {
      if (!container.read(authControllerProvider).isLoading) break;
      await Future<void>.delayed(const Duration(milliseconds: 15));
    }

    expect(container.read(authControllerProvider).valueOrNull, isNull);
    expect(container.read(cloudLinkSessionProvider)?.token, 'sanctum-live');
    expect(container.read(deviceIdHeaderProvider), 'dev-1');
    expect(container.read(posSyncCoordinatorProvider).allowNetwork, isTrue);
  });
}
