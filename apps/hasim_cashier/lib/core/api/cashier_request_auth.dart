import '../auth/cloud_link_store.dart';
import '../pos/pos_mode.dart';

/// PIN sessions keep a `standalone:` token. Sanctum lives on the cloud link.
/// HTTP and takeaway push must use the cloud credentials, not the PIN token.
class CashierRequestAuth {
  const CashierRequestAuth._();

  static String? bearerToken({
    required String? sessionToken,
    required CloudLinkSnapshot? cloud,
  }) {
    final cloudToken = cloud?.token.trim();
    if (cloudToken != null &&
        cloudToken.isNotEmpty &&
        !PosMode.isStandaloneToken(cloudToken)) {
      return cloudToken;
    }
    return sessionToken;
  }

  static int? workspaceId({
    required int? sessionWorkspaceId,
    required CloudLinkSnapshot? cloud,
  }) {
    final cloudId = cloud?.workspaceId;
    if (cloudId != null &&
        cloudId > 0 &&
        !PosMode.isReservedStandaloneWorkspace(cloudId)) {
      return cloudId;
    }
    return sessionWorkspaceId;
  }

  static String? deviceId({
    required String? sessionDeviceId,
    required CloudLinkSnapshot? cloud,
  }) {
    final id = cloud?.deviceId?.trim();
    if (id != null && id.isNotEmpty) return id;
    return sessionDeviceId;
  }

  static CloudLinkSnapshot? activeLink(CloudLinkSnapshot? cloud) {
    if (cloud == null || !cloud.isLinked) return null;
    if (PosMode.isStandaloneToken(cloud.token)) return null;
    return cloud;
  }

  static bool canSync({
    required String? sessionToken,
    required CloudLinkSnapshot? cloud,
  }) {
    if (activeLink(cloud) != null) return true;
    return sessionToken != null &&
        sessionToken.isNotEmpty &&
        !PosMode.isStandaloneToken(sessionToken);
  }
}
