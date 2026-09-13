import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../api/cashier_api.dart';
import '../config/app_config.dart';
import '../local_db/app_database.dart';
import '../local_db/local_db_providers.dart';
import '../offline/offline_store.dart';
import '../permissions/permissions_provider.dart';
import '../permissions/staff_permissions.dart';
import '../pos/application/local_auth_service.dart';
import '../pos/application/pos_providers.dart';
import '../pos/pos_errors.dart';
import '../pos/pos_mode.dart';
import 'cashier_cloud_link_service.dart';
import 'cloud_link_store.dart';

const _tokenKey = 'cashier_token';
const _workspaceKey = 'cashier_workspace_id';

class AuthSession {
  const AuthSession({
    required this.token,
    required this.user,
    required this.workspaces,
    this.workspace,
    this.permissions = const {},
    this.posEnabled = false,
    this.entitlements,
    this.isLocalMode = false,
    this.isCloudSetup = false,
    this.needsLocalUnlockPin = false,
  });

  final String token;
  final Map<String, dynamic> user;
  final Map<String, dynamic>? workspace;
  final List<Map<String, dynamic>> workspaces;
  final Map<String, dynamic> permissions;
  final bool posEnabled;
  final Map<String, dynamic>? entitlements;
  final bool isLocalMode;
  final bool isCloudSetup;
  final bool needsLocalUnlockPin;

  String get userName => (user['name'] as String?) ?? '';

  String get email {
    final value = user['email'] ?? user['username'] ?? user['phone'];
    return value?.toString().trim() ?? '';
  }

  bool get isKitchenSession =>
      LocalAuthService.isKitchenRole(user['role']?.toString());

  bool get canUsePos =>
      StaffPermissions.can(permissions, StaffPermissions.posUse);

  bool get canUseKitchen =>
      StaffPermissions.can(permissions, StaffPermissions.kitchenUse);

  bool get canViewReports =>
      StaffPermissions.can(permissions, StaffPermissions.reportsView);

  String get landingRoute {
    if (canUseKitchen && (isKitchenSession || !canUsePos)) {
      return '/kitchen';
    }
    if (canViewReports && !canUsePos) return '/reports';
    if (canUsePos) return '/home';
    if (canViewReports) return '/reports';
    if (canUseKitchen) return '/kitchen';
    return '/home';
  }
}

class AuthRepository {
  AuthRepository(this._api, this._storage);

  final CashierApiClient _api;
  final FlutterSecureStorage _storage;

  Future<AuthSession?> restore() async {
    final token = await _storage.read(key: _tokenKey);
    if (token == null || token.isEmpty) return null;
    final workspaceRaw = await _storage.read(key: _workspaceKey);
    return AuthSession(
      token: token,
      user: const {},
      workspace: workspaceRaw == null
          ? null
          : {'id': int.tryParse(workspaceRaw)},
      workspaces: const [],
      posEnabled: true,
      isLocalMode: PosMode.isStandaloneToken(token),
    );
  }

  Future<AuthSession> _sessionFromLoginPayload(
    Map<String, dynamic> data,
  ) async {
    final token = data['token'] as String? ?? '';
    // Sanctum lives in the cloud-link keys. cashier_token stays the local PIN
    // session and must not be overwritten by Laravel login.
    await _storage.write(key: CloudLinkStore.tokenKey, value: token);

    final workspace = data['workspace'] is Map
        ? Map<String, dynamic>.from(data['workspace'] as Map)
        : null;
    if (workspace?['id'] != null) {
      await _storage.write(
        key: CloudLinkStore.workspaceKey,
        value: workspace!['id'].toString(),
      );
    }
    final user = data['user'] is Map
        ? Map<String, dynamic>.from(data['user'] as Map)
        : null;
    if (user?['id'] != null) {
      await _storage.write(
        key: CloudLinkStore.userKey,
        value: user!['id'].toString(),
      );
    }

    final workspaces = <Map<String, dynamic>>[];
    final rawWorkspaces = data['workspaces'];
    if (rawWorkspaces is List) {
      for (final item in rawWorkspaces) {
        if (item is Map) workspaces.add(Map<String, dynamic>.from(item));
      }
    }

    return AuthSession(
      token: token,
      user: data['user'] is Map
          ? Map<String, dynamic>.from(data['user'] as Map)
          : {},
      workspace: workspace,
      workspaces: workspaces,
      permissions: data['permissions'] is Map
          ? Map<String, dynamic>.from(data['permissions'] as Map)
          : {},
      posEnabled: data['pos_enabled'] == true,
      entitlements: data['entitlements'] is Map
          ? Map<String, dynamic>.from(data['entitlements'] as Map)
          : null,
    );
  }

  Future<AuthSession> login({
    required String emailOrPhone,
    required String password,
  }) async {
    final data = await _api.post(
      '/auth/login',
      data: {
        'email_or_phone': emailOrPhone,
        'password': password,
        'device_name': 'كاشير حاسم',
        'device_type': 'cashier',
      },
    );
    return _sessionFromLoginPayload(data);
  }

  Future<AuthSession> socialLogin({
    required String provider,
    required String accessToken,
  }) async {
    final data = await _api.post(
      '/auth/social',
      data: {
        'provider': provider,
        'access_token': accessToken,
        'device_name': 'كاشير حاسم',
        'device_type': 'cashier',
      },
    );
    return _sessionFromLoginPayload(data);
  }

  Future<String> forgotPassword(String email) async {
    final data = await _api.post(
      '/auth/forgot-password',
      data: {'email': email},
    );
    final message = data['message'];
    if (message is String && message.trim().isNotEmpty) return message;
    return 'تم إرسال رابط إعادة تعيين كلمة المرور إلى بريدك الإلكتروني.';
  }

  Future<String> resetPassword({
    required String email,
    required String token,
    required String password,
    required String passwordConfirmation,
  }) async {
    final data = await _api.post(
      '/auth/reset-password',
      data: {
        'email': email,
        'token': token,
        'password': password,
        'password_confirmation': passwordConfirmation,
      },
    );
    final message = data['message'];
    if (message is String && message.trim().isNotEmpty) return message;
    return 'تم إعادة تعيين كلمة المرور بنجاح.';
  }

  Future<void> logout() async {
    final token = await _storage.read(key: _tokenKey);
    if (!AppConfig.offlineOnly && !PosMode.isStandaloneToken(token)) {
      try {
        await _api.post('/auth/logout');
      } catch (_) {
        // Still clear local session.
      }
    }
    await _storage.delete(key: _tokenKey);
    await _storage.delete(key: _workspaceKey);
    // Cloud link keys survive local PIN logout so restart-offline still works.
  }

  Future<void> persistWorkspace(int workspaceId) async {
    await _storage.write(key: _workspaceKey, value: workspaceId.toString());
  }
}

final authRepositoryProvider = Provider<AuthRepository>((ref) {
  return AuthRepository(
    ref.watch(cashierApiProvider),
    ref.watch(secureStorageProvider),
  );
});

class AuthController extends StateNotifier<AsyncValue<AuthSession?>> {
  AuthController(this._ref) : super(const AsyncValue.loading()) {
    _bootstrap();
  }

  /// Test helper: skip restore so widget tests can pin a session.
  AuthController.ready(this._ref, AuthSession? session)
    : super(AsyncValue.data(session));

  final Ref _ref;
  String? _pendingHasimPassword;
  String? _pendingHasimLogin;

  Future<void> hydrateCloudLinkSession() => _hydrateCloudLinkSession();

  Future<void> _hydrateCloudLinkSession() async {
    final link = await _ref.read(cloudLinkStoreProvider).read();
    final active = link != null && link.isLinked ? link : null;
    _ref.read(cloudLinkSessionProvider.notifier).state = active;
    final deviceId = active?.deviceId?.trim();
    if (deviceId != null && deviceId.isNotEmpty) {
      _ref.read(deviceIdHeaderProvider.notifier).state = deviceId;
    }
  }

  Future<void> _bootstrap() async {
    try {
      await _hydrateCloudLinkSession();
      final restored = await _ref.read(authRepositoryProvider).restore();
      if (restored == null) {
        state = const AsyncValue.data(null);
        return;
      }
      _ref.read(authTokenProvider.notifier).state = restored.token;
      final wid = restored.workspace?['id'];
      if (wid is int) {
        _ref.read(workspaceIdProvider.notifier).state = wid;
      }
      if (!PosMode.admitRestoredSession(restored.token)) {
        final localAuth = _ref.read(localAuthServiceProvider);
        final store = await localAuth.anyStore();
        if (store != null) {
          _ref.read(currentStoreIdProvider.notifier).state = store.localId;
          _ref.read(posConnectedModeProvider.notifier).state =
              AppConfig.offlineOnly ? false : store.connectedMode;
          _ref.read(workspaceIdProvider.notifier).state = store.workspaceId;
        }
        // Cold start must require PIN. Token stays on disk for next restore.
        _ref.read(authTokenProvider.notifier).state = null;
        _ref.read(currentLocalUserIdProvider.notifier).state = null;
        state = const AsyncValue.data(null);
        return;
      }
      final cached = _sessionFromCache(restored.token, restored.workspace);
      // Leave splash immediately — never block startup on /auth/me.
      state = AsyncValue.data(cached ?? restored);
      if (PosMode.isStandaloneToken(restored.token) ||
          (cached?.isLocalMode ?? restored.isLocalMode)) {
        // Local mode — never call Laravel /auth/me (would 401 and wipe session).
        if (cached != null && !cached.isLocalMode) {
          state = AsyncValue.data(
            AuthSession(
              token: cached.token,
              user: cached.user,
              workspace: cached.workspace,
              workspaces: cached.workspaces,
              permissions: cached.permissions.isNotEmpty
                  ? cached.permissions
                  : const {
                      'pos.use': true,
                      'orders.create': true,
                      'orders.manage': true,
                      'tables.manage': true,
                      'reports.view': true,
                    },
              posEnabled: true,
              entitlements: cached.entitlements,
              isLocalMode: true,
            ),
          );
        }
        return;
      }
      try {
        final me = await _ref.read(cashierApiProvider).get('/auth/me');
        final session = _sessionFromMe(restored.token, restored.workspace, me);
        await OfflineStore.instance.cacheSession(_sessionToCache(session));
        state = AsyncValue.data(session);
      } on ApiException catch (e) {
        if (e.isUnauthorized) {
          await _ref.read(authRepositoryProvider).logout();
          _ref.read(authTokenProvider.notifier).state = null;
          _ref.read(workspaceIdProvider.notifier).state = null;
          state = const AsyncValue.data(null);
          return;
        }
        // Timeout / DNS / 5xx: keep local session. Network ≠ logout.
      } catch (_) {
        // Keep hydrated local session.
      }
    } catch (e, st) {
      state = AsyncValue.error(e, st);
    }
  }

  Future<void> _applySession(AuthSession session) async {
    _ref.read(authTokenProvider.notifier).state = session.token;
    final wid = session.workspace?['id'];
    if (wid is int) {
      _ref.read(workspaceIdProvider.notifier).state = wid;
    }
    if (session.permissions.isNotEmpty) {
      _ref.read(cashierPermissionsProvider.notifier).state =
          Map<String, dynamic>.from(session.permissions);
    }
    await OfflineStore.instance.cacheSession(_sessionToCache(session));
    state = AsyncValue.data(session);
  }

  AuthSession _sessionFromMe(
    String token,
    Map<String, dynamic>? fallbackWorkspace,
    Map<String, dynamic> me,
  ) {
    final workspaces = <Map<String, dynamic>>[];
    if (me['workspaces'] is List) {
      for (final item in me['workspaces'] as List) {
        if (item is Map) workspaces.add(Map<String, dynamic>.from(item));
      }
    }
    return AuthSession(
      token: token,
      user: me['user'] is Map
          ? Map<String, dynamic>.from(me['user'] as Map)
          : {},
      workspace: me['workspace'] is Map
          ? Map<String, dynamic>.from(me['workspace'] as Map)
          : fallbackWorkspace,
      workspaces: workspaces,
      permissions: me['permissions'] is Map
          ? Map<String, dynamic>.from(me['permissions'] as Map)
          : {},
      posEnabled: me['pos_enabled'] == true,
      entitlements: me['entitlements'] is Map
          ? Map<String, dynamic>.from(me['entitlements'] as Map)
          : null,
    );
  }

  AuthSession? _sessionFromCache(
    String token,
    Map<String, dynamic>? fallbackWorkspace,
  ) {
    final cached = OfflineStore.instance.readSession();
    if (cached == null) return null;
    final workspaces = <Map<String, dynamic>>[];
    if (cached['workspaces'] is List) {
      for (final item in cached['workspaces'] as List) {
        if (item is Map) workspaces.add(Map<String, dynamic>.from(item));
      }
    }
    return AuthSession(
      token: token,
      user: cached['user'] is Map
          ? Map<String, dynamic>.from(cached['user'] as Map)
          : {},
      workspace: cached['workspace'] is Map
          ? Map<String, dynamic>.from(cached['workspace'] as Map)
          : fallbackWorkspace,
      workspaces: workspaces,
      permissions: cached['permissions'] is Map
          ? Map<String, dynamic>.from(cached['permissions'] as Map)
          : {},
      posEnabled: cached['pos_enabled'] == true,
      entitlements: cached['entitlements'] is Map
          ? Map<String, dynamic>.from(cached['entitlements'] as Map)
          : null,
      isLocalMode:
          cached['is_local_mode'] == true || PosMode.isStandaloneToken(token),
    );
  }

  Map<String, dynamic> _sessionToCache(AuthSession session) => {
    'user': session.user,
    'workspace': session.workspace,
    'workspaces': session.workspaces,
    'permissions': session.permissions,
    'pos_enabled': session.posEnabled,
    'entitlements': session.entitlements,
    'is_local_mode': session.isLocalMode,
  };

  Future<void> login(String emailOrPhone, String password) async {
    try {
      final session = await _ref
          .read(authRepositoryProvider)
          .login(emailOrPhone: emailOrPhone, password: password);
      _pendingHasimPassword = password;
      _pendingHasimLogin = emailOrPhone.trim();
      await _beginCloudSetup(session);
    } catch (e, st) {
      _pendingHasimPassword = null;
      _pendingHasimLogin = null;
      if (state.valueOrNull?.isLocalMode != true) {
        await _clearCloudSetupSession();
      }
      Error.throwWithStackTrace(e, st);
    }
  }

  Future<void> socialLogin({
    required String provider,
    required String accessToken,
  }) async {
    try {
      final session = await _ref
          .read(authRepositoryProvider)
          .socialLogin(provider: provider, accessToken: accessToken);
      _pendingHasimPassword = null;
      _pendingHasimLogin = session.email;
      await _beginCloudSetup(session);
    } catch (e, st) {
      _pendingHasimPassword = null;
      _pendingHasimLogin = null;
      if (state.valueOrNull?.isLocalMode != true) {
        await _clearCloudSetupSession();
      }
      Error.throwWithStackTrace(e, st);
    }
  }

  Future<void> _beginCloudSetup(AuthSession session) async {
    _ref.read(authTokenProvider.notifier).state = session.token;
    final deviceId = await _ref
        .read(deviceIdentityProvider)
        .getOrCreateDeviceId();
    _ref.read(deviceIdHeaderProvider.notifier).state = deviceId;
    final loginWorkspaceId = _asInt(session.workspace?['id']);
    if (loginWorkspaceId != null) {
      _ref.read(workspaceIdProvider.notifier).state = loginWorkspaceId;
    }

    var workspaces = session.workspaces;
    workspaces = await _ref
        .read(cashierCloudLinkServiceProvider)
        .loadWorkspaces();
    if (workspaces.isEmpty) {
      workspaces = session.workspaces;
    }

    final posWorkspaces = CashierCloudLinkService.posEnabledWorkspaces(
      workspaces,
    );
    if (posWorkspaces.isEmpty) {
      await _clearCloudSetupSession();
      throw ApiException('الكاشير غير متاح في باقتك الحالية', statusCode: 403);
    }

    final setup = AuthSession(
      token: session.token,
      user: session.user,
      workspace: session.workspace,
      workspaces: workspaces,
      permissions: session.permissions,
      posEnabled: session.posEnabled,
      entitlements: session.entitlements,
      isCloudSetup: true,
    );
    state = AsyncValue.data(setup);

    if (workspaces.length == 1 && posWorkspaces.length == 1) {
      await selectWorkspace(posWorkspaces.first);
    }
  }

  Future<void> loginStandalonePin({
    required String username,
    required String pin,
  }) async {
    final auth = _ref.read(localAuthServiceProvider);
    final store = await auth.anyStore();
    if (store == null) {
      throw const StoreNotFound();
    }
    final user = await auth.login(
      workspaceId: store.workspaceId,
      username: username,
      pin: pin,
    );
    await _applyStandaloneUser(user, store);
  }

  Future<void> _applyStandaloneUser(dynamic user, dynamic store) async {
    final token = 'standalone:${user.localId}';
    final auth = _ref.read(localAuthServiceProvider);
    final permissions = user is LocalUser
        ? await auth.effectivePermissions(user)
        : LocalAuthService.permissionsFor(user.role as String);
    _ref.read(currentLocalUserIdProvider.notifier).state =
        user.localId as String;
    _ref.read(currentStoreIdProvider.notifier).state = store.localId as String;
    // Offline-only: never enable Laravel connected mode.
    _ref.read(posConnectedModeProvider.notifier).state = AppConfig.offlineOnly
        ? false
        : store.connectedMode == true;
    var link = await _ref.read(cloudLinkStoreProvider).read();
    if (link?.isLinked == true) {
      await auth.updateStore(
        storeId: store.localId as String,
        connectedMode: true,
      );
      try {
        await _ref
            .read(cashierCloudLinkServiceProvider)
            .ensureCatalogSnapshot(link!);
        link = await _ref.read(cloudLinkStoreProvider).read();
      } catch (_) {
        // PIN login still works offline; Settings can retry the snapshot.
      }
    }
    await _hydrateCloudLinkSession();
    final catalogWorkspaceId = await CashierCloudLinkService.catalogWorkspaceId(
      localStoreWorkspaceId: store.workspaceId as int,
      link: link,
      db: _ref.read(appDatabaseProvider),
    );
    final session = AuthSession(
      token: token,
      user: {
        'id': user.localId,
        'name': user.name,
        'username': user.username,
        'email': user.username,
        'role': user.role,
      },
      workspace: {
        'id': catalogWorkspaceId,
        'name': store.name,
        'pos_enabled': true,
        'store_id': store.localId,
        'tax_rate': store.taxRate,
        'currency': store.currency,
        'allow_negative_stock': store.allowNegativeStock,
        'local_store_workspace_id': store.workspaceId,
      },
      workspaces: [
        {'id': catalogWorkspaceId, 'name': store.name, 'pos_enabled': true},
      ],
      permissions: permissions,
      posEnabled: true,
      isLocalMode: true,
    );
    await _ref
        .read(authRepositoryProvider)
        .persistWorkspace(catalogWorkspaceId);
    await _ref
        .read(secureStorageProvider)
        .write(key: 'cashier_token', value: token);
    await _applySession(session);
  }

  Future<String> forgotPassword(String email) {
    if (AppConfig.offlineOnly) {
      throw ApiException(
        'إعادة تعيين كلمة المرور غير متاحة في الوضع الأوفلاين.',
      );
    }
    return _ref.read(authRepositoryProvider).forgotPassword(email);
  }

  Future<String> resetPassword({
    required String email,
    required String token,
    required String password,
    required String passwordConfirmation,
  }) {
    if (AppConfig.offlineOnly) {
      throw ApiException(
        'إعادة تعيين كلمة المرور غير متاحة في الوضع الأوفلاين.',
      );
    }
    return _ref
        .read(authRepositoryProvider)
        .resetPassword(
          email: email,
          token: token,
          password: password,
          passwordConfirmation: passwordConfirmation,
        );
  }

  Future<void> selectWorkspace(Map<String, dynamic> workspace) async {
    final current = state.valueOrNull;
    if (current == null || current.token.isEmpty) {
      throw ApiException('سجّل الدخول أولاً.', statusCode: 401);
    }
    if (PosMode.isStandaloneToken(current.token) || current.isLocalMode) {
      throw ApiException('الوضع المحلي لا يتصل بـ Laravel.');
    }

    final id = CashierCloudLinkService.requireId(
      workspace['id'],
      'مساحة العمل غير متاحة.',
    );
    if (!CashierCloudLinkService.isPosEnabled(workspace)) {
      throw ApiException('الكاشير غير متاح في باقتك الحالية', statusCode: 403);
    }

    _ref.read(workspaceIdProvider.notifier).state = id;
    final deviceId = await _ref
        .read(deviceIdentityProvider)
        .getOrCreateDeviceId();
    _ref.read(deviceIdHeaderProvider.notifier).state = deviceId;

    await _ref
        .read(cashierCloudLinkServiceProvider)
        .bindWorkspace(
          workspace: workspace,
          token: current.token,
          user: current.user,
        );
    await _finishCloudSetup();
  }

  Future<void> abortCloudSetup() {
    _pendingHasimPassword = null;
    _pendingHasimLogin = null;
    return _clearCloudSetupSession();
  }

  Future<void> _finishCloudSetup() async {
    await _hydrateCloudLinkSession();
    var unlocked = await _ensureUnlockUserFromHasim();
    unlocked ??= await _existingUnlockUserForHasimEmail();
    if (unlocked != null) {
      _pendingHasimPassword = null;
      _pendingHasimLogin = null;
      await _clearCloudSetupSession();
      await _applyStandaloneUser(unlocked.user, unlocked.store);
      return;
    }

    final current = state.valueOrNull;
    final email = (current != null && current.email.isNotEmpty)
        ? current.email
        : (_pendingHasimLogin ?? '');
    if (current != null &&
        current.token.isNotEmpty &&
        email.trim().isNotEmpty) {
      state = AsyncValue.data(
        AuthSession(
          token: current.token,
          user: current.user,
          workspace: current.workspace,
          workspaces: current.workspaces,
          permissions: current.permissions,
          posEnabled: current.posEnabled,
          entitlements: current.entitlements,
          isCloudSetup: true,
          needsLocalUnlockPin: true,
        ),
      );
      return;
    }

    _pendingHasimPassword = null;
    _pendingHasimLogin = null;
    await _clearCloudSetupSession();
  }

  Future<void> completeLocalUnlockPin(String pin) async {
    final current = state.valueOrNull;
    if (current == null ||
        !current.isCloudSetup ||
        !current.needsLocalUnlockPin) {
      throw ApiException('سجّل الدخول بحساب حاسم أولاً.', statusCode: 401);
    }
    if (pin.trim().length < 4) {
      throw ApiException('كلمة المرور المحلية يجب أن تكون 4 أحرف على الأقل.');
    }
    _pendingHasimPassword = pin.trim();
    if ((_pendingHasimLogin ?? '').trim().isEmpty) {
      _pendingHasimLogin = current.email;
    }
    final unlocked = await _ensureUnlockUserFromHasim();
    if (unlocked == null) {
      throw ApiException('تعذر إنشاء مستخدم الفتح المحلي.');
    }
    _pendingHasimPassword = null;
    _pendingHasimLogin = null;
    await _clearCloudSetupSession();
    await _applyStandaloneUser(unlocked.user, unlocked.store);
  }

  Future<({LocalStore store, LocalUser user})?>
  _existingUnlockUserForHasimEmail() async {
    final session = state.valueOrNull;
    final email = (session != null && session.email.isNotEmpty)
        ? session.email
        : (_pendingHasimLogin ?? '');
    if (email.trim().isEmpty) return null;
    final auth = _ref.read(localAuthServiceProvider);
    final store = await auth.anyStore();
    if (store == null) return null;
    final user = await auth.findUserByEmail(store.workspaceId, email);
    if (user == null) return null;
    return (store: store, user: user);
  }

  Future<({LocalStore store, LocalUser user})?>
  _ensureUnlockUserFromHasim() async {
    final password = _pendingHasimPassword;
    final session = state.valueOrNull;
    final email = (session != null && session.email.isNotEmpty)
        ? session.email
        : (_pendingHasimLogin ?? '');
    if (password == null || email.trim().isEmpty) return null;
    final display = session?.userName.trim() ?? '';
    final storeName = '${session?.workspace?['name'] ?? ''}'.trim();
    try {
      return await _ref
          .read(localAuthServiceProvider)
          .bootstrapUnlockUserFromHasim(
            email: email,
            displayName: display.isEmpty ? email : display,
            password: password,
            storeName: storeName.isEmpty ? 'حاسم' : storeName,
          );
    } catch (_) {
      return null;
    }
  }

  Future<void> _clearCloudSetupSession() async {
    _ref.read(authTokenProvider.notifier).state = null;
    final store = await _ref.read(localAuthServiceProvider).anyStore();
    if (store != null) {
      _ref.read(workspaceIdProvider.notifier).state = store.workspaceId;
      _ref.read(currentStoreIdProvider.notifier).state = store.localId;
      _ref.read(posConnectedModeProvider.notifier).state = false;
    }
    if (state.valueOrNull?.isLocalMode != true) {
      state = const AsyncValue.data(null);
    }
  }

  int? _asInt(Object? raw) {
    if (raw is int) return raw;
    return int.tryParse('$raw');
  }

  /// Keep session permissions aligned with `/bootstrap` (source of truth).
  /// No-op when nothing meaningful changed — prevents auth refresh storms.
  void applyBootstrapSnapshot({
    required Map<String, dynamic> permissions,
    Map<String, dynamic>? workspace,
    Map<String, dynamic>? entitlements,
    bool? posEnabled,
  }) {
    final current = state.valueOrNull;
    if (current == null) return;

    final nextPerms = permissions.isNotEmpty
        ? permissions
        : current.permissions;
    final nextWorkspace = workspace ?? current.workspace;
    final nextEntitlements = entitlements ?? current.entitlements;
    final nextPos = posEnabled ?? current.posEnabled;

    if (_sameMap(current.permissions, nextPerms) &&
        _sameWorkspaceId(current.workspace, nextWorkspace) &&
        current.posEnabled == nextPos) {
      return;
    }

    final next = AuthSession(
      token: current.token,
      user: current.user,
      workspace: nextWorkspace,
      workspaces: current.workspaces,
      permissions: nextPerms,
      posEnabled: nextPos,
      entitlements: nextEntitlements,
      isLocalMode: current.isLocalMode || AppConfig.offlineOnly,
    );
    OfflineStore.instance.cacheSession(_sessionToCache(next));
    state = AsyncValue.data(next);
  }

  bool _sameWorkspaceId(Map<String, dynamic>? a, Map<String, dynamic>? b) {
    return a?['id'] == b?['id'];
  }

  bool _sameMap(Map<String, dynamic> a, Map<String, dynamic> b) {
    if (identical(a, b)) return true;
    if (a.length != b.length) return false;
    for (final entry in a.entries) {
      if (b[entry.key] != entry.value) return false;
    }
    return true;
  }

  Future<void> logout() async {
    final token = _ref.read(authTokenProvider);
    final standalone = PosMode.isStandaloneToken(token);
    await _ref.read(authRepositoryProvider).logout();
    _ref.read(authTokenProvider.notifier).state = null;
    _ref.read(currentShiftIdProvider.notifier).state = null;
    _ref.read(currentLocalUserIdProvider.notifier).state = null;
    if (!standalone) {
      _ref.read(workspaceIdProvider.notifier).state = null;
      _ref.read(currentStoreIdProvider.notifier).state = null;
      _ref.read(posConnectedModeProvider.notifier).state = false;
    }
    state = const AsyncValue.data(null);
  }
}

final authControllerProvider =
    StateNotifierProvider<AuthController, AsyncValue<AuthSession?>>((ref) {
      return AuthController(ref);
    });
