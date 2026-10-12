import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class AuthState {
  const AuthState({
    this.bootstrapping = true,
    this.loading = false,
    this.token,
    this.user,
    this.workspaces = const [],
    this.workspace,
    this.participant = const MobileParticipant(type: 'company_staff'),
    this.error,
  });

  final bool bootstrapping;
  final bool loading;
  final String? token;
  final UserModel? user;
  final List<WorkspaceModel> workspaces;
  final WorkspaceModel? workspace;
  final MobileParticipant participant;
  final String? error;

  bool get isAuthenticated => token != null && token!.isNotEmpty;

  bool get isIndependentWorker => participant.isIndependentWorker;

  /// Company/customer accounts use the shared inbox without private folders.
  bool get isCompanyUser => isAuthenticated && !isIndependentWorker;

  /// Private inbox folders belong only to the current workspace owner.
  bool get canSeeSpecialInboxSections =>
      isAuthenticated && workspace?.isOwner == true;

  String get authenticatedHome => isIndependentWorker
      ? '/conversations'
      : (workspace == null ? '/workspaces' : '/conversations');

  AuthState copyWith({
    bool? bootstrapping,
    bool? loading,
    String? token,
    UserModel? user,
    List<WorkspaceModel>? workspaces,
    WorkspaceModel? workspace,
    MobileParticipant? participant,
    String? error,
    bool clearError = false,
    bool clearSession = false,
    bool clearWorkspace = false,
  }) {
    return AuthState(
      bootstrapping: bootstrapping ?? this.bootstrapping,
      loading: loading ?? this.loading,
      token: clearSession ? null : (token ?? this.token),
      user: clearSession ? null : (user ?? this.user),
      workspaces: clearSession ? const [] : (workspaces ?? this.workspaces),
      workspace: clearSession || clearWorkspace ? null : (workspace ?? this.workspace),
      participant: clearSession
          ? const MobileParticipant(type: 'company_staff')
          : (participant ?? this.participant),
      error: clearError ? null : (error ?? this.error),
    );
  }

  /// Changes on login, logout, and workspace switch so settings providers can reset.
  String get isolationKey =>
      '${isAuthenticated ? 1 : 0}:${user?.id ?? 0}:${workspace?.id ?? 0}';
}

class AuthController extends StateNotifier<AuthState> {
  AuthController(this._ref) : super(const AuthState()) {
    _wireUnauthorized();
    bootstrap();
  }

  final Ref _ref;
  StreamSubscription? _unauthSub;
  bool _clearingUnauthorized = false;

  void _wireUnauthorized() {
    final api = _ref.read(apiClientProvider);
    api.setOnUnauthorized(() {
      unawaited(clearSessionFromUnauthorized());
    });
    _unauthSub = unauthorizedEvents.stream.listen((_) {
      unawaited(clearSessionFromUnauthorized());
    });
  }

  Future<void> clearSessionFromUnauthorized() async {
    if (_clearingUnauthorized || !state.isAuthenticated) return;
    _clearingUnauthorized = true;
    try {
      await _wipeLocalSession();
    } finally {
      _clearingUnauthorized = false;
    }
  }

  @override
  void dispose() {
    _unauthSub?.cancel();
    super.dispose();
  }

  /// Ends a hung bootstrap (secure storage / network) so splash can reach login.
  Future<void> abortBootstrapAsGuest() async {
    if (!state.bootstrapping) return;
    await _wipeLocalSession();
  }

  Future<void> bootstrap() async {
    String? token;
    try {
      token = await _ref
          .read(secureStoreProvider)
          .readToken()
          .timeout(const Duration(seconds: 8));
    } catch (_) {
      token = null;
    }
    if (token == null || token.isEmpty) {
      state = state.copyWith(bootstrapping: false, clearSession: true);
      return;
    }
    final prefs = _ref.read(prefsStoreProvider);
    final snapshot = await _ref.read(inboxLocalStoreProvider).loadAccountSnapshot();
    if (snapshot != null) {
      await _restoreSnapshot(token, snapshot);
      _startInboxServices();
      unawaited(_refreshAccount(allowWorkspaceReset: false));
      return;
    }
    final lastWorkspaceId = prefs.workspaceId;
    if (lastWorkspaceId != null) {
      _ref.read(inboxSessionProvider).beginWorkspace(lastWorkspaceId);
    }
    try {
      await _refreshAccount(allowWorkspaceReset: true);
      _startInboxServices();
    } catch (_) {
      await _wipeLocalSession();
    }
  }

  Future<void> _restoreSnapshot(String token, LocalAccountSnapshot snapshot) async {
    final prefs = _ref.read(prefsStoreProvider);
    WorkspaceModel? workspace;
    if (!snapshot.participant.isIndependentWorker) {
      final preferredId = prefs.workspaceId ?? snapshot.currentWorkspaceId;
      for (final item in snapshot.workspaces) {
        if (item.id == preferredId) {
          workspace = item;
          break;
        }
      }
      workspace ??= snapshot.workspaces.isNotEmpty ? snapshot.workspaces.first : null;
      if (workspace != null) {
        _ref.read(inboxSessionProvider).beginWorkspace(workspace.id);
        await prefs.setWorkspaceId(workspace.id);
      }
      await prefs.setCompanyPushAllowed(true);
    } else if (prefs.workspaceId != null) {
      _ref.read(inboxSessionProvider).beginWorkspace(prefs.workspaceId!);
      await prefs.setCompanyPushAllowed(false);
    } else {
      await prefs.setCompanyPushAllowed(false);
    }
    _ref.read(apiClientProvider).resetUnauthorizedGate();
    state = AuthState(
      bootstrapping: false,
      token: token,
      user: snapshot.user,
      workspaces: snapshot.participant.isIndependentWorker ? const [] : snapshot.workspaces,
      workspace: workspace,
      participant: snapshot.participant,
    );
    _syncWorkerProfileId();
  }

  Future<void> _refreshAccount({required bool allowWorkspaceReset}) async {
    final token = await _ref.read(secureStoreProvider).readToken();
    if (token == null || token.isEmpty) return;
    final me = await _ref.read(authRepositoryProvider).me().timeout(const Duration(seconds: 25));
    final prefs = _ref.read(prefsStoreProvider);
    WorkspaceModel? workspace;
    if (!me.participant.isIndependentWorker) {
      workspace = me.workspace;
      if (workspace == null && prefs.workspaceId != null) {
        for (final item in me.workspaces) {
          if (item.id == prefs.workspaceId) {
            workspace = item;
            break;
          }
        }
      }
      workspace ??= me.workspaces.isNotEmpty ? me.workspaces.first : null;
      if (workspace != null) {
        _ref.read(inboxSessionProvider).beginWorkspace(workspace.id);
        await prefs.setWorkspaceId(workspace.id);
      }
      await prefs.setCompanyPushAllowed(true);
    } else if (allowWorkspaceReset) {
      _ref.read(inboxSessionProvider).clear();
      await prefs.setWorkspaceId(null);
      await prefs.setCompanyPushAllowed(false);
    } else {
      await prefs.setCompanyPushAllowed(false);
    }
    await _ref.read(inboxLocalStoreProvider).saveAccountSnapshot(
      LocalAccountSnapshot(
        user: me.user,
        participant: me.participant,
        workspaces: me.participant.isIndependentWorker ? const [] : me.workspaces,
        currentWorkspaceId: me.participant.isIndependentWorker ? null : workspace?.id,
      ),
    );
    _ref.read(apiClientProvider).resetUnauthorizedGate();
    state = AuthState(
      bootstrapping: false,
      token: token,
      user: me.user,
      workspaces: me.workspaces,
      workspace: me.participant.isIndependentWorker ? state.workspace : workspace,
      participant: me.participant,
    );
    _syncWorkerProfileId();
  }

  void _startInboxServices() {
    try {
      unawaited(_ref.read(realtimeServiceProvider).start());
      _ref.read(outboxWorkerProvider).start();
      unawaited(_ref.read(pushServiceProvider).initializeAndRegister());
    } catch (_) {
      // Keep session; inbox works and polling can retry later.
    }
  }

  Future<void> _applyLogin(LoginResult result) async {
    await _ref.read(inboxLocalStoreProvider).clearAll();
    await _ref.read(secureStoreProvider).saveToken(result.token);
    final workspace = result.participant.isIndependentWorker
        ? null
        : result.workspace ??
              (result.workspaces.isNotEmpty ? result.workspaces.first : null);
    if (workspace != null) {
      _ref.read(inboxSessionProvider).beginWorkspace(workspace.id);
      await _ref.read(prefsStoreProvider).setWorkspaceId(workspace.id);
      await _ref.read(prefsStoreProvider).setCompanyPushAllowed(true);
    } else {
      _ref.read(inboxSessionProvider).clear();
      await _ref.read(prefsStoreProvider).setWorkspaceId(null);
      await _ref.read(prefsStoreProvider).setCompanyPushAllowed(
        !result.participant.isIndependentWorker,
      );
    }
    _ref.read(apiClientProvider).resetUnauthorizedGate();
    await _ref.read(inboxLocalStoreProvider).saveAccountSnapshot(
      LocalAccountSnapshot(
        user: result.user,
        participant: result.participant,
        workspaces: result.participant.isIndependentWorker ? const [] : result.workspaces,
        currentWorkspaceId: workspace?.id,
      ),
    );
    state = AuthState(
      bootstrapping: false,
      loading: false,
      token: result.token,
      user: result.user,
      workspaces: result.workspaces,
      workspace: workspace,
      participant: result.participant,
    );
    _syncWorkerProfileId();
    await _ref.read(realtimeServiceProvider).start();
    _ref.read(outboxWorkerProvider).start();
    await _ref.read(pushServiceProvider).initializeAndRegister();
  }

  Future<bool> login(String emailOrPhone, String password) async {
    state = state.copyWith(loading: true, clearError: true);
    try {
      final result = await _ref
          .read(authRepositoryProvider)
          .login(emailOrPhone: emailOrPhone, password: password);
      await _applyLogin(result);
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(loading: false, error: e.userMessage);
      return false;
    } catch (_) {
      state = state.copyWith(loading: false, error: 'تعذر تسجيل الدخول.');
      return false;
    }
  }

  Future<bool> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
    String? phone,
    String? workspaceName,
  }) async {
    state = state.copyWith(loading: true, clearError: true);
    try {
      final result = await _ref
          .read(authRepositoryProvider)
          .register(
            name: name,
            email: email,
            password: password,
            passwordConfirmation: passwordConfirmation,
            phone: phone,
            workspaceName: workspaceName,
          );
      await _applyLogin(result);
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(loading: false, error: e.userMessage);
      return false;
    } catch (_) {
      state = state.copyWith(loading: false, error: 'تعذر إنشاء الحساب.');
      return false;
    }
  }

  Future<bool> socialLogin({
    required String provider,
    required String accessToken,
  }) async {
    state = state.copyWith(loading: true, clearError: true);
    try {
      final result = await _ref
          .read(authRepositoryProvider)
          .socialLogin(provider: provider, accessToken: accessToken);
      await _applyLogin(result);
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(loading: false, error: e.userMessage);
      return false;
    } catch (_) {
      state = state.copyWith(
        loading: false,
        error: 'تعذر تسجيل الدخول عبر Google.',
      );
      return false;
    }
  }

  Future<String?> forgotPassword(String email) async {
    state = state.copyWith(loading: true, clearError: true);
    try {
      final msg = await _ref.read(authRepositoryProvider).forgotPassword(email);
      state = state.copyWith(loading: false);
      return msg;
    } on ApiException catch (e) {
      state = state.copyWith(loading: false, error: e.userMessage);
      return null;
    } catch (_) {
      state = state.copyWith(
        loading: false,
        error: 'تعذر إرسال رابط إعادة التعيين.',
      );
      return null;
    }
  }

  Future<bool> resetPassword({
    required String email,
    required String token,
    required String password,
    required String passwordConfirmation,
  }) async {
    state = state.copyWith(loading: true, clearError: true);
    try {
      await _ref
          .read(authRepositoryProvider)
          .resetPassword(
            email: email,
            token: token,
            password: password,
            passwordConfirmation: passwordConfirmation,
          );
      state = state.copyWith(loading: false);
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(loading: false, error: e.userMessage);
      return false;
    } catch (_) {
      state = state.copyWith(
        loading: false,
        error: 'تعذر إعادة تعيين كلمة المرور.',
      );
      return false;
    }
  }

  Future<bool> updateProfile({
    required String name,
    String? email,
    String? phone,
  }) async {
    try {
      final user = await _ref
          .read(authRepositoryProvider)
          .updateProfile(name: name, email: email, phone: phone);
      state = state.copyWith(user: user);
      await _persistCurrentAccount();
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(error: e.userMessage);
      return false;
    } catch (_) {
      state = state.copyWith(error: 'تعذر تحديث الملف الشخصي.');
      return false;
    }
  }

  void setUser(UserModel user) {
    state = state.copyWith(user: user);
    unawaited(_persistCurrentAccount());
  }

  Future<void> _persistCurrentAccount() async {
    final user = state.user;
    if (user == null || !state.isAuthenticated) return;
    await _ref.read(inboxLocalStoreProvider).saveAccountSnapshot(
      LocalAccountSnapshot(
        user: user,
        participant: state.participant,
        workspaces: state.isIndependentWorker ? const [] : state.workspaces,
        currentWorkspaceId: state.isIndependentWorker ? null : state.workspace?.id,
      ),
    );
  }

  Future<bool> switchWorkspace(WorkspaceModel workspace) async {
    if (state.workspace?.id == workspace.id) {
      return true;
    }
    final previous = state.workspace;
    await _ref.read(pushServiceProvider).revokeCurrent();
    await _ref.read(realtimeServiceProvider).stop();
    _ref.read(realtimeServiceProvider).watchConversation(null);
    _ref.read(inboxSessionProvider).beginWorkspace(workspace.id);
    await _ref.read(prefsStoreProvider).setWorkspaceId(workspace.id);
    await _ref.read(inboxLocalStoreProvider).setCurrentWorkspace(workspace.id);
    state = state.copyWith(workspace: workspace, clearError: true);

    try {
      await _ref.read(workspaceRepositoryProvider).switchTo(workspace.id);
    } on ApiException catch (e) {
      await _revertWorkspace(previous, e.userMessage);
      return false;
    } catch (_) {
      await _revertWorkspace(previous, 'تعذر تبديل مساحة العمل.');
      return false;
    }

    await _ref.read(realtimeServiceProvider).start();
    _ref.read(outboxWorkerProvider).start();
    await _ref.read(pushServiceProvider).initializeAndRegister();
    await _persistCurrentAccount();
    return true;
  }

  Future<void> _revertWorkspace(WorkspaceModel? previous, String error) async {
    if (previous == null) {
      _ref.read(inboxSessionProvider).clear();
      await _ref.read(prefsStoreProvider).setWorkspaceId(null);
      await _ref.read(inboxLocalStoreProvider).setCurrentWorkspace(null);
    } else {
      _ref.read(inboxSessionProvider).beginWorkspace(previous.id);
      await _ref.read(prefsStoreProvider).setWorkspaceId(previous.id);
      await _ref.read(inboxLocalStoreProvider).setCurrentWorkspace(previous.id);
    }
    state = previous == null
        ? state.copyWith(clearWorkspace: true, error: error)
        : state.copyWith(workspace: previous, error: error);
    await _ref.read(realtimeServiceProvider).start();
    _ref.read(outboxWorkerProvider).start();
    if (previous != null) {
      await _ref.read(pushServiceProvider).initializeAndRegister();
    }
  }

  Future<void> logout() async {
    try {
      await _ref.read(pushServiceProvider).revokeCurrent();
    } catch (_) {
      // Local logout still clears the session when revoke fails.
    }
    try {
      await _ref.read(authRepositoryProvider).logout();
    } catch (_) {
      // Local session is cleared even when the remote logout call fails.
    }
    await _wipeLocalSession();
  }

  Future<void> _wipeLocalSession() async {
    await _ref.read(realtimeServiceProvider).stop();
    _ref.read(realtimeServiceProvider).watchConversation(null);
    await _ref.read(outboxWorkerProvider).stop();
    _ref.read(inboxSessionProvider).clear();
    await _ref.read(inboxLocalStoreProvider).clearAll();
    await _ref.read(secureStoreProvider).clearToken();
    await _ref.read(prefsStoreProvider).clearPushDevice();
    await _ref.read(prefsStoreProvider).setCompanyPushAllowed(false);
    await _ref.read(prefsStoreProvider).setWorkspaceId(null);
    _ref.read(apiClientProvider).resetUnauthorizedGate();
    state = const AuthState(bootstrapping: false);
    _syncWorkerProfileId();
  }

  void _syncWorkerProfileId() {
    final id = state.isIndependentWorker ? state.participant.workerProfileId : null;
    _ref.read(activeWorkerProfileIdProvider.notifier).state = id;
  }
}

final authControllerProvider = StateNotifierProvider<AuthController, AuthState>(
  (ref) {
    return AuthController(ref);
  },
);
