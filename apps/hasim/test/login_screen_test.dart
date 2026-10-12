import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/auth/presentation/login_screen.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemorySecureStore extends SecureStore {
  String? _token;
  @override
  Future<void> saveToken(String token) async => _token = token;
  @override
  Future<String?> readToken() async => _token;
  @override
  Future<void> clearToken() async => _token = null;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('login shows HASIM Chat branding art', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          secureStoreProvider.overrideWithValue(_MemorySecureStore()),
          sharedPrefsProvider.overrideWithValue(prefs),
        ],
        child: const MaterialApp(home: LoginScreen()),
      ),
    );
    await tester.pump();

    expect(find.byKey(const Key('hasim-login-art')), findsOneWidget);
    expect(find.text('مرحباً بك مجدداً'), findsOneWidget);
    expect(find.text('سجّل دخولك إلى حسابك لمتابعة أعمالك'), findsOneWidget);
    expect(find.text('البريد الإلكتروني أو الجوال'), findsOneWidget);
    expect(find.text('كلمة المرور'), findsOneWidget);
    expect(find.text('نسيت كلمة المرور؟'), findsOneWidget);
    expect(find.text('أو'), findsOneWidget);
    expect(find.text('دخول'), findsOneWidget);
    expect(find.text('الدخول عبر Google'), findsOneWidget);
    expect(find.text('ليس لديك حساب؟'), findsOneWidget);
    expect(find.text('إنشاء حساب'), findsOneWidget);
    expect(find.text('إدارة المحادثات والحجوزات'), findsNothing);
  });
}
