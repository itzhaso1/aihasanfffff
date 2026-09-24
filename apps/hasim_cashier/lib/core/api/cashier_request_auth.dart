import '../auth/cloud_link_store.dart';
import '../pos/pos_mode.dart';

/// PIN sessions keep a `standalone:` token. Sanctum lives on the cloud link.
/// A live Hasim login token wins over a stored cloud token so a new sign-in
/// is not sent with an expired link.
class CashierRequestAuth {
  const CashierRequestAuth._();

  static bool isLiveSanctumToken(String? token) {
    final value = token?.trim() ?? '';
    return value.isNotEmpty && !PosMode.isStandaloneToken(value);
  }

  static String? bearerToken({
    required String? sessionToken,
    required CloudLinkSnapshot? cloud,
  }) {
    final session = sessionToken?.trim();
    if (isLiveSanctumToken(session)) return session;
    final cloudToken = cloud?.token.trim();
    if (cloudToken != null &&
        cloudToken.isNotEmpty &&
        !PosMode.isStandaloneToken(cloudToken)) {
      return cloudToken;
    }
    return session;
  }

  static int? workspaceId({
    required int? sessionWorkspaceId,
    required CloudLinkSnapshot? cloud,
    String? sessionToken,
  }) {
    if (isLiveSanctumToken(sessionToken) &&
        sessionWorkspaceId != null &&
        sessionWorkspaceId > 0 &&
        !PosMode.isReservedStandaloneWorkspace(sessionWorkspaceId)) {
      return sessionWorkspaceId;
    }
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
