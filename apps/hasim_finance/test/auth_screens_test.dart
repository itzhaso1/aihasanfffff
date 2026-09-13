import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/auth/google_auth.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/features/auth/forgot_password_screen.dart';
import 'package:hasim_finance/features/auth/login_screen.dart';
import 'package:hasim_finance/features/auth/workspace_gate_screens.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';
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

  Future<void> pumpAuth(
    WidgetTester tester,
    Widget child, {
    List<Override> extra = const [],
  }) async {
    tester.view.physicalSize = const Size(1200, 1800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(prefs),
          financeApiProvider.overrideWithValue(api),
          secureStoreProvider.overrideWithValue(secure),
          ...extra,
        ],
        child: MaterialApp(
          locale: const Locale('ar'),
          supportedLocales: AppLocalizations.supportedLocales,
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          home: child,
        ),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
  }

  testWidgets('login screen shows Arabic labels, Google, and forgot password', (tester) async {
    await pumpAuth(tester, const LoginScreen());

    expect(find.text('حاسم للمالية'), findsOneWidget);
    expect(find.text('دخول'), findsOneWidget);
    expect(find.text('الدخول باستخدام Google'), findsOneWidget);
    expect(find.text('نسيت كلمة المرور؟'), findsOneWidget);
    expect(find.text('أو'), findsOneWidget);
    expect(Directionality.of(tester.element(find.text('دخول'))), TextDirection.rtl);
  });

  testWidgets('password login submits credentials to Laravel', (tester) async {
    await pumpAuth(tester, const LoginScreen());
    await tester.enterText(find.byType(TextField).first, 'owner@example.com');
    await tester.enterText(find.byType(TextField).at(1), 'password');
    await tester.tap(find.text('دخول'));
    await tester.pumpAndSettle();
    expect(api.loggedIn, isTrue);
  });

  testWidgets('invalid password shows server error', (tester) async {
    await pumpAuth(tester, const LoginScreen());
    await tester.enterText(find.byType(TextField).first, 'owner@example.com');
    await tester.enterText(find.byType(TextField).at(1), 'wrong');
    await tester.tap(find.text('دخول'));
    await tester.pumpAndSettle();
    expect(find.text('بيانات الدخول غير صحيحة.'), findsOneWidget);
    expect(api.loggedIn, isFalse);
  });

  testWidgets('Google loading state then successful social login', (tester) async {
    final google = FakeGoogleSource(delay: const Duration(milliseconds: 80));
    await pumpAuth(
      tester,
      const LoginScreen(),
      extra: [googleAccessTokenSourceProvider.overrideWithValue(google)],
    );
    await tester.tap(find.text('الدخول باستخدام Google'));
    await tester.pump();
    expect(find.text('جار تسجيل الدخول...'), findsOneWidget);
    await tester.pumpAndSettle();
    expect(google.called, isTrue);
    expect(api.lastSocialToken, 'ya29.test');
    expect(api.loggedIn, isTrue);
  });

  testWidgets('Google cancellation returns to login without a fatal error', (tester) async {
    final google = FakeGoogleSource(error: GoogleSignInCancelled());
    await pumpAuth(
      tester,
      const LoginScreen(),
      extra: [googleAccessTokenSourceProvider.overrideWithValue(google)],
    );
    await tester.tap(find.text('الدخول باستخدام Google'));
    await tester.pumpAndSettle();
    expect(find.text('الدخول باستخدام Google'), findsOneWidget);
    expect(find.text('تعذر تسجيل الدخول عبر Google.'), findsNothing);
    expect(api.loggedIn, isFalse);
  });

  testWidgets('Google authentication error shows useful message', (tester) async {
    final google = FakeGoogleSource(error: ApiException('يحتاج إعداد Google'));
    await pumpAuth(
      tester,
      const LoginScreen(),
      extra: [googleAccessTokenSourceProvider.overrideWithValue(google)],
    );
    await tester.tap(find.text('الدخول باستخدام Google'));
    await tester.pumpAndSettle();
    expect(find.text('يحتاج إعداد Google'), findsOneWidget);
  });

  testWidgets('Google account linking conflict shows server-driven message', (tester) async {
    api.socialError = ApiException('conflict', statusCode: 409);
    final google = FakeGoogleSource();
    await pumpAuth(
      tester,
      const LoginScreen(),
      extra: [googleAccessTokenSourceProvider.overrideWithValue(google)],
    );
    await tester.tap(find.text('الدخول باستخدام Google'));
    await tester.pumpAndSettle();
    expect(find.textContaining('سجّل الدخول بكلمة المرور أولاً'), findsOneWidget);
    expect(api.loggedIn, isFalse);
  });

  testWidgets('forgot password submits email to Laravel', (tester) async {
    await pumpAuth(tester, const ForgotPasswordScreen());
    await tester.enterText(find.byType(TextField), 'owner@example.com');
    await tester.tap(find.text('إرسال الرابط'));
    await tester.pumpAndSettle();
    expect(api.lastForgotEmail, 'owner@example.com');
    expect(find.textContaining('تم إرسال رابط'), findsOneWidget);
  });

  testWidgets('reset password submits token to Laravel', (tester) async {
    await pumpAuth(tester, const ResetPasswordScreen());
    await tester.enterText(find.byType(TextField).at(0), 'owner@example.com');
    await tester.enterText(find.byType(TextField).at(1), 'reset-token');
    await tester.enterText(find.byType(TextField).at(2), 'NewPassword123!');
    await tester.enterText(find.byType(TextField).at(3), 'NewPassword123!');
    await tester.tap(find.text('حفظ'));
    await tester.pumpAndSettle();
    expect(api.lastResetEmail, 'owner@example.com');
    expect(find.textContaining('تم إعادة تعيين كلمة المرور'), findsOneWidget);
  });

  testWidgets('workspace selector lists server workspaces', (tester) async {
    await tester.pumpWidget(financeHarness(
      overrides: financeOverrides(
        prefs: prefs,
        api: api,
        workspaces: const [
          WorkspaceInfo(id: 1, name: 'منشأة أ', financeEnabled: true),
          WorkspaceInfo(id: 2, name: 'منشأة ب', financeEnabled: false),
        ],
      ),
      child: const WorkspaceSelectScreen(),
    ));
    await tester.pump();
    expect(find.text('منشأة أ'), findsOneWidget);
    expect(find.text('منشأة ب'), findsOneWidget);
    expect(find.text('المالية غير مفعّلة'), findsOneWidget);
  });

  testWidgets('finance disabled screen explains server eligibility', (tester) async {
    await tester.pumpWidget(financeHarness(
      overrides: financeOverrides(prefs: prefs, api: api),
      child: const FinanceUnavailableScreen(),
    ));
    await tester.pump();
    expect(find.textContaining('لا تملك منتج المالية'), findsOneWidget);
  });

  testWidgets('English Google label is localized', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(prefs),
          financeApiProvider.overrideWithValue(api),
        ],
        child: MaterialApp(
          locale: const Locale('en'),
          supportedLocales: AppLocalizations.supportedLocales,
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          home: const LoginScreen(),
        ),
      ),
    );
    await tester.pump();
    expect(find.text('Continue with Google'), findsOneWidget);
    expect(find.text('Forgot password?'), findsOneWidget);
  });
}