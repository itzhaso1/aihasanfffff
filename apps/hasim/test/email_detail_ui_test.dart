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
import 'package:hasim/features/contacts/data/contact_repository.dart';
import 'package:hasim/features/email/data/email_repository.dart';
import 'package:hasim/features/email/presentation/email_detail_screen.dart';
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

class _FakeEmailRepository extends EmailRepository {
  _FakeEmailRepository(super.api, {required this.mail});

  EmailMessageModel mail;
  bool starred = false;
  bool markedRead = false;

  @override
  Future<EmailMessageModel> show(int id) async => mail.copyWith(isStarred: starred);

  @override
  Future<List<EmailAccountModel>> accounts() async {
    return const [
      EmailAccountModel(id: 2, name: 'الدعم', email: 'workspace@hasim.online'),
    ];
  }

  @override
  Future<void> markRead(int id) async => markedRead = true;

  @override
  Future<void> star(int id) async {
    starred = !starred;
    mail = mail.copyWith(isStarred: starred);
  }
}

class _FakeContactRepository extends ContactRepository {
  _FakeContactRepository(super.api, {this.exists = false});

  bool exists;
  bool created = false;

  @override
  Future<List<EmailContactModel>> findByEmail(String email) async {
    if (!exists) return const [];
    return [EmailContactModel(id: 1, name: 'مرسل', email: email)];
  }

  @override
  Future<EmailContactModel> create({
    required String name,
    required String email,
    String? phone,
    String? company,
    String? jobTitle,
    String? notes,
    bool isFavorite = false,
  }) async {
    created = true;
    exists = true;
    return EmailContactModel(id: 8, name: name, email: email);
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

  Widget app({
    required _FakeEmailRepository emails,
    required _FakeContactRepository contacts,
  }) {
    final router = GoRouter(
      initialLocation: '/email/22',
      routes: [
        GoRoute(path: '/', builder: (context, state) => const Scaffold(body: Text('القائمة'))),
        GoRoute(path: '/email/compose', builder: (context, state) => const Scaffold(body: Text('شاشة الإنشاء'))),
        GoRoute(
          path: '/email/:id',
          builder: (context, state) => EmailDetailScreen(id: int.parse(state.pathParameters['id']!)),
        ),
        GoRoute(path: '/contacts/:id', builder: (context, state) => const Scaffold(body: Text('جهة الاتصال'))),
      ],
    );

    return ProviderScope(
      overrides: [
        sharedPrefsProvider.overrideWithValue(prefs),
        secureStoreProvider.overrideWithValue(_MemorySecureStore()),
        apiClientProvider.overrideWithValue(api),
        emailRepositoryProvider.overrideWithValue(emails),
        contactRepositoryProvider.overrideWithValue(contacts),
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

  EmailMessageModel sampleMail() => EmailMessageModel(
        id: 22,
        sender: 'info@hasim.online',
        recipient: 'hasan.baara2333@gmail.com',
        subject: '222',
        body: 'نص الرسالة الفعلي للتحقق',
        emailAccountId: 2,
        isRead: false,
        createdAt: DateTime(2026, 9, 14, 11, 0),
      );

  testWidgets('email detail shows live message data and existing actions', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final emails = _FakeEmailRepository(api, mail: sampleMail());
    final contacts = _FakeContactRepository(api);
    await tester.pumpWidget(app(emails: emails, contacts: contacts));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 80));
    await tester.pumpAndSettle();

    expect(find.text('تفاصيل الرسالة'), findsOneWidget);
    expect(find.text('222'), findsOneWidget);
    expect(find.text('info@hasim.online'), findsOneWidget);
    expect(find.text('hasan.baara2333@gmail.com'), findsOneWidget);
    expect(find.text('workspace@hasim.online'), findsOneWidget);
    expect(find.text('نص الرسالة الفعلي للتحقق'), findsOneWidget);
    expect(find.text('رد'), findsOneWidget);
    expect(find.text('إضافة إلى جهات الاتصال'), findsOneWidget);
    expect(find.text('رسالة جديدة'), findsOneWidget);
    expect(emails.markedRead, isTrue);

    await tester.tap(find.byTooltip('تمييز بنجمة'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 80));
    await tester.pumpAndSettle();
    expect(emails.starred, isTrue);

    await tester.tap(find.text('إضافة إلى جهات الاتصال'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 80));
    await tester.pumpAndSettle();
    expect(contacts.created, isTrue);
    expect(find.text('تمت الإضافة إلى جهات الاتصال'), findsOneWidget);

    await tester.tap(find.text('رسالة جديدة'));
    await tester.pumpAndSettle();
    expect(find.text('شاشة الإنشاء'), findsOneWidget);
  });

  testWidgets('email detail reply uses existing compose route', (tester) async {
    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final emails = _FakeEmailRepository(api, mail: sampleMail());
    final contacts = _FakeContactRepository(api, exists: true);
    await tester.pumpWidget(app(emails: emails, contacts: contacts));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 80));
    await tester.pumpAndSettle();

    expect(find.text('إضافة إلى جهات الاتصال'), findsNothing);
    await tester.tap(find.text('رد'));
    await tester.pumpAndSettle();
    expect(find.text('رد'), findsWidgets);
    expect(find.text('Re: 222'), findsOneWidget);
  });
}
