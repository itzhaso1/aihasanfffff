import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/contacts/data/contact_group_repository.dart';
import 'package:hasim/features/contacts/data/contact_repository.dart';
import 'package:hasim/features/email/data/email_repository.dart';
import 'package:hasim/features/email/presentation/email_compose_screen.dart';
import 'package:hasim/realtime/realtime_service.dart';
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

class _FakeContactRepository extends ContactRepository {
  _FakeContactRepository(super.api);

  @override
  Future<({List<EmailContactModel> items, String? nextCursor})> list({
    String? cursor,
    String? search,
    bool? favorite,
    int perPage = 20,
  }) async {
    return (
      items: const [
        EmailContactModel(id: 4, name: 'سارة', email: 'sara@hasim.online'),
      ],
      nextCursor: null,
    );
  }

  @override
  Future<List<RecentRecipientModel>> recentRecipients({int limit = 20}) async => const [];
}

class _FakeContactGroupRepository extends ContactGroupRepository {
  _FakeContactGroupRepository(super.api);

  @override
  Future<List<ContactGroupModel>> list() async => const [];
}

class _FakeEmailRepository extends EmailRepository {
  _FakeEmailRepository(super.api);

  bool didSend = false;

  @override
  Future<List<EmailAccountModel>> accounts() async {
    return const [
      EmailAccountModel(id: 1, name: 'الدعم', email: 'info@hasim.online'),
    ];
  }

  @override
  Future<EmailMessageModel> send({
    required int emailAccountId,
    required String to,
    required String subject,
    required String body,
    int? replyToMessageId,
  }) async {
    didSend = true;
    return EmailMessageModel(id: 9, sender: 'info@hasim.online', recipient: to, subject: subject, body: body);
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  late SharedPreferences prefs;
  late ApiClient api;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    api = isolatedTestApiClient(
      secureStore: _MemorySecureStore(),
      prefsStore: PrefsStore(prefs),
    );
  });

  Widget app({required _FakeEmailRepository repo, String? prefillTo}) {
    final router = GoRouter(
      initialLocation: '/compose',
      routes: [
        GoRoute(path: '/', builder: (context, state) => const Scaffold(body: Text('القائمة'))),
        GoRoute(
          path: '/compose',
          builder: (context, state) => EmailComposeScreen(prefillTo: prefillTo),
        ),
      ],
    );

    return ProviderScope(
      overrides: [
        sharedPrefsProvider.overrideWithValue(prefs),
        secureStoreProvider.overrideWithValue(_MemorySecureStore()),
        apiClientProvider.overrideWithValue(api),
        emailRepositoryProvider.overrideWithValue(repo),
        contactRepositoryProvider.overrideWithValue(_FakeContactRepository(api)),
        contactGroupRepositoryProvider.overrideWithValue(_FakeContactGroupRepository(api)),
        realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
      ],
      child: MaterialApp.router(
        theme: AppTheme.light(),
        locale: const Locale('ar'),
        builder: (context, child) => Directionality(
          textDirection: TextDirection.rtl,
          child: child ?? const SizedBox.shrink(),
        ),
        routerConfig: router,
      ),
    );
  }

  testWidgets('compose chrome keeps send logic and modern fields', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeEmailRepository(api);
    await tester.pumpWidget(app(repo: repo));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    expect(find.text('رسالة جديدة'), findsOneWidget);
    expect(find.text('حاسم'), findsOneWidget);
    expect(find.text('حساب الإرسال'), findsOneWidget);
    expect(find.text('info@hasim.online'), findsOneWidget);
    expect(find.text('إلى'), findsOneWidget);
    expect(find.text('اضغط لاختيار مستلمين'), findsOneWidget);
    expect(find.text('إظهار CC/BCC'), findsOneWidget);
    expect(find.text('الموضوع'), findsOneWidget);
    expect(find.text('أدخل موضوع الرسالة...'), findsOneWidget);
    expect(find.text('النص'), findsOneWidget);
    expect(find.text('اكتب رسالتك هنا...'), findsOneWidget);
    expect(find.text('إرسال'), findsOneWidget);
    expect(find.byIcon(Icons.send_rounded), findsOneWidget);
    expect(find.byIcon(Icons.attach_file_rounded), findsOneWidget);
    expect(find.byIcon(Icons.image_outlined), findsOneWidget);
    expect(find.byIcon(Icons.link_rounded), findsOneWidget);
    expect(find.byIcon(Icons.emoji_emotions_outlined), findsOneWidget);

    await tester.tap(find.text('إظهار CC/BCC'));
    await tester.pumpAndSettle();
    expect(find.text('إخفاء CC/BCC'), findsOneWidget);
    expect(find.text('CC'), findsOneWidget);
    expect(find.text('BCC'), findsOneWidget);

    await tester.tap(find.text('إرسال'));
    await tester.pump();
    expect(repo.didSend, isFalse);
    expect(find.text('تم إرسال الرسالة'), findsNothing);
  });

  testWidgets('compose send stays wired to current repository path', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeEmailRepository(api);
    await tester.pumpWidget(app(repo: repo, prefillTo: 'client@hasim.online'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    expect(find.text('client@hasim.online'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('compose-subject')), 'استفسار');
    await tester.enterText(find.byKey(const Key('compose-body')), 'السلام عليكم');
    await tester.pump();
    await tester.tap(find.text('إرسال'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();
    expect(repo.didSend, isTrue);
    expect(find.text('تم إرسال الرسالة'), findsOneWidget);
  });

  testWidgets('compose opens the existing recipient picker', (tester) async {
    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeEmailRepository(api);
    await tester.pumpWidget(app(repo: repo));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    await tester.tap(find.text('اضغط لاختيار مستلمين'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 80));
    await tester.pumpAndSettle();

    expect(find.text('اختيار المستلمين'), findsOneWidget);
    expect(find.text('سارة'), findsOneWidget);
    await tester.tap(find.text('سارة'));
    await tester.pump();
    await tester.tap(find.text('تم'));
    await tester.pumpAndSettle();
    expect(find.text('سارة'), findsOneWidget);
  });
}
