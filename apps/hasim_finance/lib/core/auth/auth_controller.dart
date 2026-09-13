import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim_finance/core/api/finance_api.dart';
import 'package:hasim_finance/core/auth/google_access_token.dart';
import 'package:hasim_finance/core/auth/google_auth.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_client.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/permissions/finance_permissions.dart';
import 'package:hasim_finance/core/storage/prefs_store.dart';
import 'package:hasim_finance/core/storage/secure_store.dart';
import 'package:shared_preferences/shared_preferences.dart';

final sharedPrefsProvider = Provider<SharedPreferences>((ref) {
  throw UnimplementedError('SharedPreferences must be overridden in main');
});

final secureStoreProvider = Provider<SecureStore>((ref) => SecureStore());

final prefsStoreProvider = Provider<PrefsStore>((ref) {
  return PrefsStore(ref.watch(sharedPrefsProvider));
});

final apiClientProvider = Provider<ApiClient>((ref) {
  return ApiClient(
    secureStore: ref.watch(secureStoreProvider),
    prefsStore: ref.watch(prefsStoreProvider),
  );
});

final financeApiProvider = Provider<FinanceApi>((ref) => FinanceApi(ref.watch(apiClientProvider)));

final googleAccessTokenSourceProvider = Provider<GoogleAccessTokenSource>((ref) {
  return FinanceGoogleAccessTokenClient(ref.watch(financeApiProvider));
});

class AuthState {
  const AuthState({
    this.bootstrapping = true,
    this.user,
    this.workspace,
    this.workspaces = const [],
    this.permissions = const FinancePermissions({}),
    this.financeEnabled = false,
    this.needsWorkspaceSelection = false,
    this.error,
  });

  final bool bootstrapping;
  final AuthUser? user;
  final WorkspaceInfo? workspace;
  final List<WorkspaceInfo> workspaces;
  final FinancePermissions permissions;
  final bool financeEnabled;
  final bool needsWorkspaceSelection;
  final String? error;

  bool get isAuthenticated => user != null;
  bool get isLoading => bootstrapping;
  bool get canEnterFinance => isAuthenticated && financeEnabled && !needsWorkspaceSelection;

  AuthState copyWith({
    bool? bootstrapping,
    AuthUser? user,
    WorkspaceInfo? workspace,
    List<WorkspaceInfo>? workspaces,
    FinancePermissions? permissions,
    bool? financeEnabled,
    bool? needsWorkspaceSelection,
    String? error,
    bool clearError = false,
    bool clearUser = false,
  }) {
    return AuthState(
      bootstrapping: bootstrapping ?? this.bootstrapping,
      user: clearUser ? null : (user ?? this.user),
      workspace: clearUser ? null : (workspace ?? this.workspace),
      workspaces: clearUser ? const [] : (workspaces ?? this.workspaces),
      permissions: clearUser ? const FinancePermissions({}) : (permissions ?? this.permissions),
      financeEnabled: clearUser ? false : (financeEnabled ?? this.financeEnabled),
      needsWorkspaceSelection: clearUser ? false : (needsWorkspaceSelection ?? this.needsWorkspaceSelection),
      error: clearError ? null : (error ?? this.error),
    );
  }
}

class AuthController extends Notifier<AuthState> {
  bool _clearingUnauthorized = false;

  @override
  AuthState build() {
    final sub = unauthorizedEvents.stream.listen((_) {
      unawaited(_onUnauthorized());
    });
    ref.onDispose(sub.cancel);
    Future.microtask(restore);
    return const AuthState();
  }

  FinanceApi get _api => ref.read(financeApiProvider);
  ApiClient get _client => ref.read(apiClientProvider);
  SecureStore get _secure => ref.read(secureStoreProvider);
  PrefsStore get _prefs => ref.read(prefsStoreProvider);

  Future<void> restore() async {
    try {
      final token = await _secure.readToken();
      if (token == null || token.isEmpty) {
        state = const AuthState(bootstrapping: false);
        return;
      }
      _client.resetUnauthorizedGate();
      final session = await _api.me();
      await _applySession(session);
    } on ApiException catch (e) {
      if (e.isUnauthorized) {
        await _secure.clearToken();
      }
      state = AuthState(bootstrapping: false, error: e.message);
    } catch (_) {
      state = const AuthState(bootstrapping: false);
    }
  }

  Future<void> login(String emailOrPhone, String password) async {
    state = state.copyWith(error: null, clearError: true);
    try {
      _client.resetUnauthorizedGate();
      final session = await _api.login(emailOrPhone: emailOrPhone, password: password);
      await _completeLogin(session, pickWorkspaceIfMany: true);
    } on ApiException catch (e) {
      state = state.copyWith(bootstrapping: false, error: e.message);
      rethrow;
    }
  }

  Future<void> loginWithGoogle() async {
    state = state.copyWith(error: null, clearError: true);
    final accessToken = await ref.read(googleAccessTokenSourceProvider).obtainAccessToken();
    await socialLogin(accessToken: accessToken);
  }

  Future<void> socialLogin({required String accessToken}) async {
    try {
      _client.resetUnauthorizedGate();
      final session = await _api.socialLogin(accessToken: accessToken);
      await _completeLogin(session, pickWorkspaceIfMany: true);
    } on ApiException catch (e) {
      state = state.copyWith(bootstrapping: false, error: e.message);
      rethrow;
    }
  }

  Future<String> requestPasswordReset(String email) {
    return _api.forgotPassword(email);
  }

  Future<String> confirmPasswordReset({
    required String email,
    required String token,
    required String password,
    required String passwordConfirmation,
  }) {
    return _api.resetPassword(
      email: email,
      token: token,
      password: password,
      passwordConfirmation: passwordConfirmation,
    );
  }

  Future<void> switchWorkspace(int id) async {
    await _api.switchWorkspace(id);
    await _prefs.setWorkspaceId(id);
    final session = await _api.me();
    await _applySession(session, pickWorkspaceIfMany: false);
    await _maybeBootstrap();
  }

  Future<void> logout() async {
    try {
      await _api.logout();
    } catch (_) {}
    await _clearLocalSession();
  }

  void requestWorkspaceSelection() {
    if (state.workspaces.length > 1) {
      state = state.copyWith(needsWorkspaceSelection: true);
    }
  }

  Future<void> _completeLogin(SessionPayload session, {required bool pickWorkspaceIfMany}) async {
    if (session.token != null) {
      await _secure.saveToken(session.token!);
    }
    await _applySession(session, pickWorkspaceIfMany: pickWorkspaceIfMany);
    await _maybeBootstrap();
  }

  Future<void> _maybeBootstrap() async {
    if (!state.canEnterFinance) return;
    try {
      await _api.bootstrap();
    } catch (_) {}
  }

  Future<void> _onUnauthorized() async {
    if (_clearingUnauthorized || !state.isAuthenticated) return;
    _clearingUnauthorized = true;
    try {
      await _clearLocalSession();
    } finally {
      _clearingUnauthorized = false;
    }
  }

  Future<void> _clearLocalSession() async {
    await _secure.clearToken();
    await _prefs.setWorkspaceId(null);
    _client.resetUnauthorizedGate();
    state = const AuthState(bootstrapping: false);
  }

  Future<void> _applySession(SessionPayload session, {bool pickWorkspaceIfMany = false}) async {
    if (session.workspace != null) {
      await _prefs.setWorkspaceId(session.workspace!.id);
    }
    state = AuthState(
      bootstrapping: false,
      user: session.user,
      workspace: session.workspace,
      workspaces: session.workspaces,
      permissions: FinancePermissions(session.permissions),
      financeEnabled: session.financeEnabled,
      needsWorkspaceSelection: pickWorkspaceIfMany && session.workspaces.length > 1,
    );
  }
}

final authControllerProvider = NotifierProvider<AuthController, AuthState>(AuthController.new);
