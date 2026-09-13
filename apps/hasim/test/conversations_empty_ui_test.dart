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
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/conversations/presentation/conversations_screen.dart';
import 'package:hasim/features/conversations/presentation/widgets/inbox_empty_illustration.dart';
import 'package:hasim/realtime/realtime_service.dart';
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

class _FakeConversationRepository extends ConversationRepository {
  _FakeConversationRepository(super.api, {this.items = const []});

  List<ConversationModel> items;
  String lastFilter = 'all';
  String? lastChannel;
  String? lastSearch;

  @override
  Future<({List<ConversationModel> items, String? nextCursor})> list({
    String filter = 'all',
    String? channel,
    String? search,
    String? cursor,
    int? workspaceId,
  }) async {
    lastFilter = filter;
    lastChannel = channel;
    lastSearch = search;
    return (items: items, nextCursor: null);
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
    api = ApiClient(
      secureStore: _MemorySecureStore(),
      prefsStore: PrefsStore(prefs),
    );
  });

  Widget app({required _FakeConversationRepository repo}) {
    final router = GoRouter(
      routes: [
        GoRoute(path: '/', builder: (context, state) => const ConversationsScreen()),
        GoRoute(
          path: '/contacts',
          builder: (context, state) => const Scaffold(body: Text('شاشة جهات الاتصال')),
        ),
        GoRoute(
          path: '/conversations/:id',
          builder: (context, state) => Scaffold(body: Text('محادثة ${state.pathParameters['id']}')),
        ),
        GoRoute(path: '/profile', builder: (context, state) => const Scaffold(body: Text('الملف'))),
      ],
    );

    return ProviderScope(
      overrides: [
        sharedPrefsProvider.overrideWithValue(prefs),
        secureStoreProvider.overrideWithValue(_MemorySecureStore()),
        conversationRepositoryProvider.overrideWithValue(repo),
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

  testWidgets('empty conversations matches inbox chrome and CTA', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeConversationRepository(api);
    await tester.pumpWidget(app(repo: repo));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    expect(find.text('حاسم'), findsOneWidget);
    expect(find.text('ابحث في المحادثات'), findsOneWidget);
    expect(find.text('الكل'), findsOneWidget);
    expect(find.text('غير مقروءة'), findsOneWidget);
    expect(find.text('مؤرشفة'), findsOneWidget);
    expect(find.text('المفضلة'), findsNothing);
    expect(find.text('واتساب'), findsOneWidget);
    expect(find.text('تعذر تحميل المحادثات.'), findsNothing);
    expect(find.byType(InboxEmptyIllustration), findsOneWidget);
    expect(find.text('لا توجد محادثات حاليًا'), findsOneWidget);
    expect(find.text('ستظهر هنا رسائل واتساب والويب والقنوات الموحدة.'), findsOneWidget);
    expect(find.text('ابدأ محادثة جديدة'), findsOneWidget);

    await tester.tap(find.text('غير مقروءة'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(repo.lastFilter, 'unread');

    await tester.tap(find.text('واتساب'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(repo.lastChannel, 'whatsapp');

    await tester.tap(find.text('ابدأ محادثة جديدة'));
    await tester.pumpAndSettle();
    expect(find.text('شاشة جهات الاتصال'), findsOneWidget);
  });

  testWidgets('populated conversations list still opens a thread', (tester) async {
    final repo = _FakeConversationRepository(
      api,
      items: const [
        ConversationModel(
          id: 7,
          channel: 'whatsapp',
          status: 'open',
          externalId: 'أحمد',
          unreadCount: 2,
        ),
      ],
    );

    await tester.pumpWidget(app(repo: repo));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    expect(find.text('لا توجد محادثات حاليًا'), findsNothing);
    expect(find.text('أحمد'), findsOneWidget);
    await tester.tap(find.text('أحمد'));
    await tester.pumpAndSettle();
    expect(find.text('محادثة 7'), findsOneWidget);
  });
}
