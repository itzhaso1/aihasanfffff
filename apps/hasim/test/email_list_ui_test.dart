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
import 'package:hasim/core/widgets/hasim_message_card.dart';
import 'package:hasim/features/email/data/email_repository.dart';
import 'package:hasim/features/email/presentation/email_list_screen.dart';
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
  _FakeEmailRepository(super.api, {this.sentItems = const []});

  List<EmailMessageModel> sentItems;
  String lastFolder = 'inbox';

  @override
  Future<({List<EmailMessageModel> items, String? nextCursor})> inbox({String? cursor, String? search}) async {
    lastFolder = 'inbox';
    return (items: const <EmailMessageModel>[], nextCursor: null);
  }

  @override
  Future<({List<EmailMessageModel> items, String? nextCursor})> sent({String? cursor, String? search}) async {
    lastFolder = 'sent';
    return (items: sentItems, nextCursor: null);
  }

  @override
  Future<({List<EmailMessageModel> items, String? nextCursor})> drafts({String? cursor, String? search}) async {
    lastFolder = 'drafts';
    return (items: const <EmailMessageModel>[], nextCursor: null);
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

  Widget app({required _FakeEmailRepository repo}) {
    final router = GoRouter(
      routes: [
        GoRoute(path: '/', builder: (context, state) => const EmailListScreen()),
        GoRoute(path: '/email/compose', builder: (context, state) => const Scaffold(body: Text('شاشة الإنشاء'))),
        GoRoute(
          path: '/email/:id',
          builder: (context, state) => Scaffold(body: Text('رسالة ${state.pathParameters['id']}')),
        ),
        GoRoute(path: '/profile', builder: (context, state) => const Scaffold(body: Text('الملف'))),
      ],
    );

    return ProviderScope(
      overrides: [
        sharedPrefsProvider.overrideWithValue(prefs),
        secureStoreProvider.overrideWithValue(_MemorySecureStore()),
        apiClientProvider.overrideWithValue(api),
        emailRepositoryProvider.overrideWithValue(repo),
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

  testWidgets('email list chrome uses cards, tabs, and search', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeEmailRepository(
      api,
      sentItems: [
        EmailMessageModel(
          id: 22,
          sender: 'me@hasim.online',
          recipient: 'info@hasim.online',
          subject: 'استفسار بخصوص الخدمة',
          preview: 'نأمل منكم الرد في أقرب وقت ممكن...',
          createdAt: DateTime(2026, 9, 14, 11, 22),
        ),
      ],
    );

    await tester.pumpWidget(app(repo: repo));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    expect(find.text('حاسم'), findsOneWidget);
    expect(find.text('البريد'), findsOneWidget);
    expect(find.text('كل رسائلك في مكان واحد'), findsOneWidget);
    expect(find.text('الوارد'), findsOneWidget);
    expect(find.text('المرسل'), findsOneWidget);
    expect(find.text('المسودات'), findsOneWidget);
    expect(find.text('البحث في الرسائل الواردة...'), findsOneWidget);
    expect(find.byIcon(Icons.edit_outlined), findsWidgets);

    await tester.tap(find.text('المرسل'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 80));
    await tester.pumpAndSettle();

    expect(repo.lastFolder, 'sent');
    expect(find.text('البحث في الرسائل المرسلة...'), findsOneWidget);
    expect(find.byType(HasimMessageCard), findsOneWidget);
    expect(find.text('استفسار بخصوص الخدمة'), findsOneWidget);
    expect(find.text('info@hasim.online'), findsOneWidget);
    expect(find.text('نأمل منكم الرد في أقرب وقت ممكن...'), findsOneWidget);

    await tester.tap(find.text('استفسار بخصوص الخدمة'));
    await tester.pumpAndSettle();
    expect(find.text('رسالة 22'), findsOneWidget);
  });
}
