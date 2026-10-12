import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/auth/data/auth_repository.dart';
import 'package:hasim/features/auth/presentation/login_screen.dart';
import 'package:hasim/features/auth/presentation/register_screen.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:hasim/router/auth_redirect.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _MemorySecureStore extends SecureStore {
  String? _token;
  @override
  Future<void> saveToken(String token) async => _token = token;
  @override
  Future<String?> readToken() async => _token;
  @override
  Future<void> clearToken() async => _token = null;
}

LoginResult _successResult() {
  const workspace = WorkspaceModel(
    id: 4,
    name: 'متجر نورة',
    type: 'company',
    isOwner: true,
  );
  return LoginResult(
    token: 'reg-token',
    user: const UserModel(
      id: 9,
      name: 'نورة العتيبي',
      email: 'noura.register@example.test',
    ),
    participant: const MobileParticipant(type: 'company_staff'),
    workspace: workspace,
    workspaces: const [workspace],
  );
}

class _FakeAuthRepository extends AuthRepository {
  _FakeAuthRepository(super.api);

  int registerCalls = 0;
  Completer<LoginResult>? holdRegister;
  Object? registerError;
  LoginResult result = _successResult();
  Map<String, String?> lastRegister = {};

  @override
  Future<LoginResult> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
    String? phone,
    String? workspaceName,
    String deviceName = 'حاسم Flutter',
    String deviceType = 'mobile',
  }) async {
    registerCalls++;
    lastRegister = {
      'name': name,
      'email': email,
      'password': password,
      'password_confirmation': passwordConfirmation,
      'phone': phone,
      'workspace_name': workspaceName,
    };
    if (holdRegister != null) {
      return holdRegister!.future;
    }
    final error = registerError;
    if (error != null) {
      if (error is Exception) {
        throw error;
      }
      throw Exception(error);
    }
    return result;
  }
}

Future<
  ({
    List<Override> overrides,
    _FakeAuthRepository authRepo,
    _MemorySecureStore secure,
    SharedPreferences prefs,
  })
>
_overrides() async {
  SharedPreferences.setMockInitialValues({});
  final rawPrefs = await SharedPreferences.getInstance();
  final prefs = PrefsStore(rawPrefs);
  final secure = _MemorySecureStore();
  final api = isolatedTestApiClient(secureStore: secure, prefsStore: prefs);
  final authRepo = _FakeAuthRepository(api);
  return (
    overrides: [
      secureStoreProvider.overrideWithValue(secure),
      sharedPrefsProvider.overrideWithValue(rawPrefs),
      apiClientProvider.overrideWithValue(api),
      authRepositoryProvider.overrideWithValue(authRepo),
      realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
    ],
    authRepo: authRepo,
    secure: secure,
    prefs: rawPrefs,
  );
}

Widget _app(List<Override> overrides, {String location = '/register'}) {
  return ProviderScope(
    overrides: overrides,
    child: MaterialApp.router(
      routerConfig: GoRouter(
        initialLocation: location,
        routes: [
          GoRoute(
            path: '/login',
            builder: (_, _) => const LoginScreen(),
          ),
          GoRoute(
            path: '/register',
            builder: (_, _) => const RegisterScreen(),
          ),
          GoRoute(
            path: '/conversations',
            builder: (_, _) => const Scaffold(body: Text('inbox-home')),
          ),
        ],
      ),
    ),
  );
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    TestWidgetsFlutterBinding.ensureInitialized();
  });

  test('guest can open register and authenticated users leave it', () {
    expect(
      resolveHasimRedirect(
        bootstrapping: false,
        isAuthenticated: false,
        isIndependentWorker: false,
        hasWorkspace: false,
        location: '/register',
      ),
      isNull,
    );
    expect(
      resolveHasimRedirect(
        bootstrapping: false,
        isAuthenticated: true,
        isIndependentWorker: false,
        hasWorkspace: true,
        location: '/register',
      ),
      '/conversations',
    );
  });

  testWidgets('login opens the register screen', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final setup = await _overrides();
    await tester.pumpWidget(_app(setup.overrides, location: '/login'));
    await tester.pump();

    expect(find.text('إنشاء حساب'), findsOneWidget);
    await tester.tap(find.byKey(const Key('open-register')));
    await tester.pumpAndSettle();

    expect(find.byType(RegisterScreen), findsOneWidget);
    expect(find.text('أنشئ حساب حاسم من التطبيق مباشرة.'), findsOneWidget);
  });

  testWidgets('register validates required fields and password match', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final setup = await _overrides();
    await tester.pumpWidget(_app(setup.overrides));
    await tester.pump();

    expect(find.text('التسجيل عبر Google'), findsOneWidget);
    await tester.ensureVisible(find.byKey(const Key('register-submit')));
    await tester.tap(find.byKey(const Key('register-submit')));
    await tester.pump();

    expect(find.text('مطلوب'), findsWidgets);
    expect(setup.authRepo.registerCalls, 0);

    await tester.enterText(find.byKey(const Key('register-name')), 'نورة');
    await tester.enterText(
      find.byKey(const Key('register-email')),
      'noura.register@example.test',
    );
    await tester.enterText(
      find.byKey(const Key('register-password')),
      'password',
    );
    await tester.enterText(
      find.byKey(const Key('register-password-confirmation')),
      'different',
    );
    await tester.ensureVisible(find.byKey(const Key('register-submit')));
    await tester.tap(find.byKey(const Key('register-submit')));
    await tester.pump();

    expect(find.text('تأكيد كلمة المرور غير مطابق'), findsOneWidget);
    expect(setup.authRepo.registerCalls, 0);
  });

  testWidgets('successful registration saves the session and opens inbox', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final setup = await _overrides();
    await tester.pumpWidget(_app(setup.overrides));
    await tester.pump();

    await tester.enterText(
      find.byKey(const Key('register-name')),
      'نورة العتيبي',
    );
    await tester.enterText(
      find.byKey(const Key('register-email')),
      'noura.register@example.test',
    );
    await tester.enterText(
      find.byKey(const Key('register-workspace-name')),
      'متجر نورة',
    );
    await tester.enterText(
      find.byKey(const Key('register-password')),
      'password',
    );
    await tester.enterText(
      find.byKey(const Key('register-password-confirmation')),
      'password',
    );
    await tester.ensureVisible(find.byKey(const Key('register-submit')));
    await tester.tap(find.byKey(const Key('register-submit')));
    await tester.pumpAndSettle();

    expect(setup.authRepo.registerCalls, 1);
    expect(setup.authRepo.lastRegister['password'], 'password');
    expect(setup.prefs.getString('password'), isNull);
    expect(await setup.secure.readToken(), 'reg-token');
    expect(find.text('inbox-home'), findsOneWidget);

    final container = ProviderScope.containerOf(
      tester.element(find.text('inbox-home')),
    );
    final auth = container.read(authControllerProvider);
    expect(auth.isAuthenticated, isTrue);
    expect(auth.workspace?.id, 4);
    expect(auth.authenticatedHome, '/conversations');
    expect(find.text('تحقق من بريدك'), findsNothing);
  });

  testWidgets('duplicate email shows the server validation error', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final setup = await _overrides();
    setup.authRepo.registerError = ApiException(
      'The email has already been taken.',
      statusCode: 422,
      errors: {
        'email': ['The email has already been taken.'],
      },
    );
    await tester.pumpWidget(_app(setup.overrides));
    await tester.pump();

    await tester.enterText(find.byKey(const Key('register-name')), 'نورة');
    await tester.enterText(
      find.byKey(const Key('register-email')),
      'taken@example.test',
    );
    await tester.enterText(
      find.byKey(const Key('register-password')),
      'password',
    );
    await tester.enterText(
      find.byKey(const Key('register-password-confirmation')),
      'password',
    );
    await tester.ensureVisible(find.byKey(const Key('register-submit')));
    await tester.tap(find.byKey(const Key('register-submit')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('register-error')), findsOneWidget);
    expect(find.textContaining('البريد الإلكتروني'), findsWidgets);
    expect(find.text('inbox-home'), findsNothing);
    expect(await setup.secure.readToken(), isNull);
  });

  testWidgets('a second submit while loading is ignored', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final setup = await _overrides();
    setup.authRepo.holdRegister = Completer<LoginResult>();
    await tester.pumpWidget(_app(setup.overrides));
    await tester.pump();

    await tester.enterText(find.byKey(const Key('register-name')), 'نورة');
    await tester.enterText(
      find.byKey(const Key('register-email')),
      'noura.register@example.test',
    );
    await tester.enterText(
      find.byKey(const Key('register-password')),
      'password',
    );
    await tester.enterText(
      find.byKey(const Key('register-password-confirmation')),
      'password',
    );
    await tester.ensureVisible(find.byKey(const Key('register-submit')));
    await tester.tap(find.byKey(const Key('register-submit')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('register-submit')));
    await tester.pump();

    expect(setup.authRepo.registerCalls, 1);
    setup.authRepo.holdRegister!.complete(_successResult());
    await tester.pumpAndSettle();
    expect(find.text('inbox-home'), findsOneWidget);
  });
}
