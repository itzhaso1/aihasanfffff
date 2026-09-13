import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/auth/google_auth.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_client.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'helpers.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late SharedPreferences prefs;
  late FakeFinanceApi api;
  late MemorySecureStore secure;

  setUp(() async {
    prefs = await mockPrefs();
    api = FakeFinanceApi(testClient(prefs));
    secure = MemorySecureStore();
  });

  ProviderContainer container({GoogleAccessTokenSource? google}) {
    return ProviderContainer(
      overrides: [
        sharedPrefsProvider.overrideWithValue(prefs),
        financeApiProvider.overrideWithValue(api),
        secureStoreProvider.overrideWithValue(secure),
        if (google != null) googleAccessTokenSourceProvider.overrideWithValue(google),
      ],
    );
  }

  test('session restore loads /auth/me when a Sanctum token exists', () async {
    await secure.saveToken('sanctum-token');
    final c = container();
    addTearDown(c.dispose);
    await c.read(authControllerProvider.notifier).restore();
    final state = c.read(authControllerProvider);
    expect(state.isAuthenticated, isTrue);
    expect(state.user?.email, 'owner@example.com');
    expect(state.financeEnabled, isTrue);
    expect(state.needsWorkspaceSelection, isFalse);
  });

  test('restore without token stays on login', () async {
    final c = container();
    addTearDown(c.dispose);
    await c.read(authControllerProvider.notifier).restore();
    expect(c.read(authControllerProvider).isAuthenticated, isFalse);
  });

  test('expired token clears the session', () async {
    await secure.saveToken('expired');
    api.meThrowsUnauthorized = true;
    final c = container();
    addTearDown(c.dispose);
    await c.read(authControllerProvider.notifier).restore();
    expect(c.read(authControllerProvider).isAuthenticated, isFalse);
    expect(await secure.readToken(), isNull);
  });

  test('401 event logs the user out', () async {
    final c = container();
    addTearDown(c.dispose);
    await c.read(authControllerProvider.notifier).login('owner@example.com', 'password');
    expect(c.read(authControllerProvider).isAuthenticated, isTrue);
    unauthorizedEvents.add(null);
    await Future<void>.delayed(const Duration(milliseconds: 20));
    expect(c.read(authControllerProvider).isAuthenticated, isFalse);
  });

  test('logout clears the secure token', () async {
    final c = container();
    addTearDown(c.dispose);
    await c.read(authControllerProvider.notifier).login('owner@example.com', 'password');
    await c.read(authControllerProvider.notifier).logout();
    expect(api.loggedOut, isTrue);
    expect(c.read(authControllerProvider).isAuthenticated, isFalse);
    expect(await secure.readToken(), isNull);
  });

  test('multiple workspaces require explicit selection after login', () async {
    api.sessionWorkspaces = const [
      WorkspaceInfo(id: 1, name: 'A', financeEnabled: true),
      WorkspaceInfo(id: 2, name: 'B', financeEnabled: true),
    ];
    final c = container();
    addTearDown(c.dispose);
    await c.read(authControllerProvider.notifier).login('owner@example.com', 'password');
    final state = c.read(authControllerProvider);
    expect(state.needsWorkspaceSelection, isTrue);
    expect(state.canEnterFinance, isFalse);
  });

  test('finance disabled keeps the session but blocks the shell', () async {
    api.financeEnabledFlag = false;
    final c = container();
    addTearDown(c.dispose);
    await c.read(authControllerProvider.notifier).login('owner@example.com', 'password');
    final state = c.read(authControllerProvider);
    expect(state.isAuthenticated, isTrue);
    expect(state.financeEnabled, isFalse);
    expect(state.canEnterFinance, isFalse);
  });

  test('Google login uses the server session and permissions', () async {
    final google = FakeGoogleSource(token: 'ya29.from-sdk');
    final c = container(google: google);
    addTearDown(c.dispose);
    await c.read(authControllerProvider.notifier).loginWithGoogle();
    final state = c.read(authControllerProvider);
    expect(api.lastSocialToken, 'ya29.from-sdk');
    expect(state.isAuthenticated, isTrue);
    expect(state.permissions.invoicesView, isTrue);
  });

  test('Google cancellation is not treated as a fatal auth error', () {
    expect(isGoogleCancellation(GoogleSignInCancelled()), isTrue);
    expect(isGoogleCancellation(ApiException('تم إلغاء تسجيل الدخول عبر Google.')), isTrue);
    expect(isGoogleCancellation(ApiException('يحتاج إعداد Google')), isFalse);
  });
}
