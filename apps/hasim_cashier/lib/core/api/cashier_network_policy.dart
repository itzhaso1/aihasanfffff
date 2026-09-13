import '../pos/pos_mode.dart';

/// Phase 1A+1B+2B network gate.
///
/// `offlineOnly` still blocks invoices / payments / `/orders` writes.
/// First-connect may call password or Google `/auth/social`, workspace,
/// device registration, then snapshot endpoints and `/sync/push`.
class CashierNetworkPolicy {
  const CashierNetworkPolicy._();

  static const cloudSetupPaths = <String>{
    '/auth/login',
    '/auth/social',
    '/auth/google/start',
    '/auth/google/status',
    '/auth/logout',
    '/auth/me',
    '/workspaces',
    '/workspaces/current',
    '/workspaces/switch',
    '/devices/register',
  };

  static const snapshotGetPaths = <String>{
    '/bootstrap',
    '/catalog/categories',
    '/catalog/items',
    '/tables',
  };

  static const snapshotPostPaths = <String>{
    '/sync/pull',
    '/sync/push',
  };

  static String normalizePath(String path) {
    var normalized = path.split('?').first.trim();
    const markers = ['/api/cashier/v1', '/cashier/v1'];
    for (final marker in markers) {
      final index = normalized.indexOf(marker);
      if (index >= 0) {
        normalized = normalized.substring(index + marker.length);
        break;
      }
    }
    if (normalized.isEmpty) return '/';
    if (!normalized.startsWith('/')) normalized = '/$normalized';
    if (normalized.length > 1 && normalized.endsWith('/')) {
      normalized = normalized.substring(0, normalized.length - 1);
    }
    return normalized;
  }

  static bool isCloudSetupPath(String path) {
    final normalized = normalizePath(path);
    return cloudSetupPaths.contains(normalized);
  }

  static bool isSnapshotPath(String path, [String? method]) {
    final normalized = normalizePath(path);
    final verb = (method ?? '').trim().toUpperCase();
    if (snapshotGetPaths.contains(normalized)) {
      return verb.isEmpty || verb == 'GET';
    }
    if (snapshotPostPaths.contains(normalized)) {
      return verb.isEmpty || verb == 'POST';
    }
    return false;
  }

  static bool allowRequest({
    required bool offlineOnly,
    required String? token,
    required String path,
    String? method,
  }) {
    if (PosMode.isStandaloneToken(token)) return false;
    if (!offlineOnly) return true;
    if (isCloudSetupPath(path)) return true;
    return isSnapshotPath(path, method);
  }
}
