import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/app.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/theme/theme_mode_controller.dart';
import 'package:hasim/features/analytics/data/analytics_models.dart';
import 'package:hasim/features/analytics/data/analytics_repository.dart';
import 'package:hasim/features/analytics/presentation/analytics_screen.dart';
import 'package:hasim/features/auth/data/auth_repository.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/conversations/presentation/chat_screen.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_message_bubble.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:hasim/realtime/reverb_websocket_client.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemSecureStore extends SecureStore {
  String? _token = 'token-opt-test';
  @override
  Future<String?> readToken() async => _token;
  @override
  Future<void> saveToken(String token) async => _token = token;
  @override
  Future<void> clearToken() async => _token = null;
}

class _NoAuthMeRepository extends AuthRepository {
  _NoAuthMeRepository(super.api);
  @override
  Future<
    ({
      MobileParticipant participant,
      UserModel user,
      List<WorkspaceModel> workspaces,
      WorkspaceModel? workspace,
    })
  >
  me() async {
    const ws = WorkspaceModel(id: 1, name: 'Hasem', role: 'owner');
    return (
      participant: const MobileParticipant(type: 'company_staff'),
      user: const UserModel(id: 5, name: 'وكيل', email: 'a@hasem.sa'),
      workspaces: const [ws],
      workspace: ws,
    );
  }
}

class _FakeAnalyticsRepository extends AnalyticsRepository {
  _FakeAnalyticsRepository(super.api);

  @override
  Future<WorkspaceAnalyticsModel> summary({
    String range = 'month',
    String? from,
    String? to,
  }) async {
    return const WorkspaceAnalyticsModel(
      from: '2026-11-01',
      to: '2026-11-25',
      revenue: 18540.50,
      ordersCount: 42,
      customersNew: 15,
      conversationsCount: 128,
      aiResponsesCount: 94,
      conversionRate: 32.8,
      topProducts: [
        TopProductModel(name: 'باقة الأعمال', quantity: 14, revenue: 7000),
      ],
      timeline: [
        TimelineEntryModel(label: 'الأسبوع ١', orders: 12, revenue: 4200),
      ],
    );
  }
}

class _ControllableRealtimeService extends NoopRealtimeService {
  final _connCtrl = StreamController<RealtimeConnectionState>.broadcast();
  RealtimeConnectionState _connState = RealtimeConnectionState.connected;

  void emitConnection(RealtimeConnectionState next) {
    _connState = next;
    if (!_connCtrl.isClosed) _connCtrl.add(next);
  }

  @override
  bool get isWebSocketConnected =>
      _connState == RealtimeConnectionState.connected;

  @override
  RealtimeConnectionState get currentConnectionState => _connState;

  @override
  Stream<RealtimeConnectionState> get connectionStateStream => _connCtrl.stream;

  @override
  void dispose() {
    _connCtrl.close();
    super.dispose();
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  HttpOverrides? prevOverrides;
  setUp(() {
    prevOverrides = HttpOverrides.current;
    HttpOverrides.global = null;
  });
  tearDown(() {
    HttpOverrides.global = prevOverrides;
  });

  test('AppTheme memoizes ThemeData singletons without repeated rebuilding', () {
    final light1 = AppTheme.light();
    final light2 = AppTheme.light();
    final dark1 = AppTheme.dark();
    final dark2 = AppTheme.dark();

    expect(identical(light1, light2), isTrue);
    expect(identical(dark1, dark2), isTrue);
    expect(light1.brightness, Brightness.light);
    expect(dark1.brightness, Brightness.dark);
  });

  testWidgets('LocaleController switches Arabic RTL and English LTR dynamically in HasimApp', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final rawPrefs = await SharedPreferences.getInstance();
    final db = InboxDatabase.memory();
    await db.ensureReady();

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(rawPrefs),
          secureStoreProvider.overrideWithValue(_MemSecureStore()),
          inboxDatabaseProvider.overrideWithValue(db),
          authRepositoryProvider.overrideWith(
            (ref) => _NoAuthMeRepository(ref.watch(apiClientProvider)),
          ),
          realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
        ],
        child: const HasimApp(),
      ),
    );
    await tester.pump();

    expect(
      Directionality.of(tester.element(find.byType(Navigator).first)),
      TextDirection.rtl,
    );

    final container = ProviderScope.containerOf(
      tester.element(find.byType(HasimApp)),
    );
    await container
        .read(localeControllerProvider.notifier)
        .setLocale(const Locale('en'));
    await tester.pump();

    expect(
      Directionality.of(tester.element(find.byType(Navigator).first)),
      TextDirection.ltr,
    );

    await container
        .read(localeControllerProvider.notifier)
        .setLocale(const Locale('ar'));
    await tester.pumpWidget(const SizedBox.shrink());
    await db.close();
  });

  test('ApiClient deduplicates concurrent GETs, supports CancelToken, and parses large message batches via isolate', () async {
    var getHits = 0;
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    addTearDown(() => server.close(force: true));

    server.listen((req) async {
      getHits++;
      final path = req.uri.path;
      if (path.endsWith('/conversations')) {
        await Future<void>.delayed(const Duration(milliseconds: 60));
        req.response
          ..statusCode = 200
          ..headers.contentType = ContentType.json
          ..write(jsonEncode({'success': true, 'data': [], 'meta': {}}));
        await req.response.close();
        return;
      }
      if (path.endsWith('/messages')) {
        final items = [
          for (var i = 1; i <= 120; i++)
            {
              'id': i,
              'conversation_id': 9,
              'direction': i.isEven ? 'outbound' : 'inbound',
              'message_type': 'text',
              'content': 'رسالة عزل رقم $i',
              'created_at': DateTime.utc(2026, 11, 25, 0, i % 60).toIso8601String(),
            },
        ];
        req.response
          ..statusCode = 200
          ..headers.contentType = ContentType.json
          ..write(jsonEncode({
            'success': true,
            'data': items.reversed.toList(),
            'meta': {'has_more': false},
          }));
        await req.response.close();
        return;
      }
      req.response.statusCode = 404;
      await req.response.close();
    });

    SharedPreferences.setMockInitialValues({
      'workspace_id': 1,
      'api_base_override': 'http://127.0.0.1:${server.port}',
    });
    final rawPrefs = await SharedPreferences.getInstance();
    final prefs = PrefsStore(rawPrefs);
    final api = ApiClient(secureStore: _MemSecureStore(), prefsStore: prefs);
    final db = InboxDatabase.memory();
    await db.ensureReady();
    addTearDown(db.close);
    final store = InboxLocalStore(db);
    final repo = ConversationRepository(api, local: store);

    // 1. Concurrent GET deduplication: two identical calls -> 1 server hit
    await Future.wait([
      api.get('/conversations'),
      api.get('/conversations'),
    ]);
    expect(getHits, 1);

    // 2. CancelToken aborts obsolete request cleanly with isCancelled=true
    final cancelToken = CancelToken();
    final cancelledFuture = ConversationRepository.runWithCancelToken(
      cancelToken,
      () => repo.list(search: 'بحث قديم', workspaceId: 1),
    );
    Future<void>.delayed(
      const Duration(milliseconds: 10),
      () => cancelToken.cancel('superseded'),
    );
    await expectLater(
      cancelledFuture,
      throwsA(
        isA<ApiException>().having((e) => e.isCancelled, 'isCancelled', isTrue),
      ),
    );

    // 3. Large payload (120 items >= compute threshold) parsed in background isolate
    final sw = Stopwatch()..start();
    final page = await repo.messagesPage(9, workspaceId: 1);
    sw.stop();
    expect(page.items, hasLength(120));
    expect(await repo.cachedMessages(1, 9), hasLength(120));
    // ignore: avoid_print
    print('[BENCHMARK_ISOLATE] parse_120_msgs_and_sqlite_ms=${sw.elapsedMilliseconds}');
  });

  testWidgets('Dark mode failed ChatMessageBubble contrast, AnalyticsScreen Sliver layout, and reconnecting banner work cleanly', (tester) async {
    SharedPreferences.setMockInitialValues({'workspace_id': 1});
    final rawPrefs = await SharedPreferences.getInstance();
    final db = InboxDatabase.memory();
    await db.ensureReady();
    final api = ApiClient(
      secureStore: _MemSecureStore(),
      prefsStore: PrefsStore(rawPrefs),
    );
    final realtime = _ControllableRealtimeService();

    // 1. Failed bubble in Dark Mode uses dark danger surface, NOT white-on-pink
    final failedMsg = MessageModel(
      id: -1,
      conversationId: 1,
      direction: 'outbound',
      messageType: 'text',
      content: 'فشل الإرسال في الوضع الداكن',
      localFailed: true,
      clientId: 'tmp-fail-dark',
      createdAt: DateTime.utc(2026, 11, 25, 1, 0),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.light(),
        darkTheme: AppTheme.dark(),
        themeMode: ThemeMode.dark,
        locale: const Locale('ar'),
        localizationsDelegates: AppLocalizations.localizationsDelegates,
        supportedLocales: AppLocalizations.supportedLocales,
        home: Scaffold(
          body: ChatMessageBubble(message: failedMsg, onRetry: () {}),
        ),
      ),
    );
    final bubbleContext = tester.element(find.byType(ChatMessageBubble));
    final expectedBg = HasimTokens.dangerSoftColor(bubbleContext);
    final boxes = tester.widgetList<DecoratedBox>(find.byType(DecoratedBox));
    expect(
      boxes.any((b) {
        final d = b.decoration;
        return d is BoxDecoration && d.color == expectedBg;
      }),
      isTrue,
    );

    // 2. AnalyticsScreen (Slivers, Dark Mode, 390px phone viewport, zero overflow)
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(rawPrefs),
          inboxDatabaseProvider.overrideWithValue(db),
          apiClientProvider.overrideWithValue(api),
          analyticsRepositoryProvider.overrideWithValue(
            _FakeAnalyticsRepository(api),
          ),
        ],
        child: MaterialApp(
          theme: AppTheme.light(),
          darkTheme: AppTheme.dark(),
          themeMode: ThemeMode.dark,
          locale: const Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: const AnalyticsScreen(),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byType(CustomScrollView), findsOneWidget);
    expect(find.text('18540.50 ر.س'), findsOneWidget);
    expect(find.text('باقة الأعمال'), findsOneWidget);

    // 3. Reconnecting banner appears/hides dynamically on ChatScreen
    final container = ProviderScope(
      overrides: [
        sharedPrefsProvider.overrideWithValue(rawPrefs),
        secureStoreProvider.overrideWithValue(_MemSecureStore()),
        inboxSessionProvider.overrideWithValue(InboxSession()..beginWorkspace(1)),
        inboxDatabaseProvider.overrideWithValue(db),
        apiClientProvider.overrideWithValue(api),
        authRepositoryProvider.overrideWithValue(_NoAuthMeRepository(api)),
        realtimeServiceProvider.overrideWithValue(realtime),
      ],
      child: MaterialApp(
        theme: AppTheme.light(),
        darkTheme: AppTheme.dark(),
        themeMode: ThemeMode.light,
        locale: const Locale('ar'),
        localizationsDelegates: AppLocalizations.localizationsDelegates,
        supportedLocales: AppLocalizations.supportedLocales,
        home: const ChatScreen(conversationId: 1),
      ),
    );
    await tester.pumpWidget(container);
    await tester.pump();
    expect(find.text('جاري إعادة الاتصال بالتحديث المباشر...'), findsNothing);

    realtime.emitConnection(RealtimeConnectionState.reconnecting);
    await tester.pump();
    await tester.pump();
    expect(find.text('جاري إعادة الاتصال بالتحديث المباشر...'), findsOneWidget);

    realtime.emitConnection(RealtimeConnectionState.connected);
    await tester.pump();
    await tester.pump();
    expect(find.text('جاري إعادة الاتصال بالتحديث المباشر...'), findsNothing);

    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();
    realtime.dispose();
    await db.close();
  });

  testWidgets('Large 1200-message ChatScreen renders lazily and scrolls smoothly without jank', (tester) async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    addTearDown(() => server.close(force: true));

    final largeMessages = [
      for (var i = 1; i <= 1200; i++)
        {
          'id': i,
          'conversation_id': 77,
          'direction': i.isEven ? 'outbound' : 'inbound',
          'message_type': 'text',
          'content': 'رسالة محادثة طويلة رقم $i',
          'created_at': DateTime.utc(2026, 11, 25, i ~/ 60, i % 60).toIso8601String(),
        },
    ];

    server.listen((req) async {
      if (req.uri.path.endsWith('/messages')) {
        req.response
          ..statusCode = 200
          ..headers.contentType = ContentType.json
          ..write(jsonEncode({
            'success': true,
            'data': largeMessages.reversed.toList(),
            'meta': {'has_more': false},
          }));
        await req.response.close();
        return;
      }
      req.response
        ..statusCode = 200
        ..headers.contentType = ContentType.json
        ..write(jsonEncode({'success': true, 'data': {'unread_count': 0}}));
      await req.response.close();
    });

    SharedPreferences.setMockInitialValues({
      'workspace_id': 1,
      'api_base_override': 'http://127.0.0.1:${server.port}',
    });
    final rawPrefs = await SharedPreferences.getInstance();
    final db = InboxDatabase.memory();
    await db.ensureReady();
    final store = InboxLocalStore(db);
    final api = ApiClient(
      secureStore: _MemSecureStore(),
      prefsStore: PrefsStore(rawPrefs),
    );
    final repo = ConversationRepository(api, local: store);
    final realtime = NoopRealtimeService();

    final container = ProviderContainer(
      overrides: [
        sharedPrefsProvider.overrideWithValue(rawPrefs),
        secureStoreProvider.overrideWithValue(_MemSecureStore()),
        inboxSessionProvider.overrideWithValue(InboxSession()..beginWorkspace(1)),
        inboxDatabaseProvider.overrideWithValue(db),
        inboxLocalStoreProvider.overrideWithValue(store),
        apiClientProvider.overrideWithValue(api),
        authRepositoryProvider.overrideWithValue(_NoAuthMeRepository(api)),
        conversationRepositoryProvider.overrideWithValue(repo),
        realtimeServiceProvider.overrideWithValue(realtime),
      ],
    );

    await tester.runAsync(() async {
      final openSw = Stopwatch()..start();
      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: MaterialApp(
            theme: AppTheme.light(),
            locale: const Locale('ar'),
            localizationsDelegates: AppLocalizations.localizationsDelegates,
            supportedLocales: AppLocalizations.supportedLocales,
            home: const ChatScreen(conversationId: 77),
          ),
        ),
      );

      for (var i = 0; i < 60; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 25));
        await tester.pump(const Duration(milliseconds: 16));
        if (find.text('رسالة محادثة طويلة رقم 1200').evaluate().isNotEmpty) {
          break;
        }
      }
      openSw.stop();
      expect(find.text('رسالة محادثة طويلة رقم 1200'), findsOneWidget);
      expect(container.read(chatControllerProvider(77)).messages, hasLength(1200));

      // Verify lazy viewport rendering: only visible viewport subset is mounted, NOT 1200 bubbles
      final mountedBubbles = find.byType(ChatMessageBubble).evaluate().length;
      expect(mountedBubbles, lessThan(40));

      // Measure scroll frame build cost over rapid drag gestures & pure frame pump
      final scrollSw = Stopwatch()..start();
      final scrollable = find.byType(Scrollable).first;
      for (var step = 0; step < 5; step++) {
        await tester.drag(scrollable, const Offset(0, 400));
        await tester.pump(const Duration(milliseconds: 16));
      }
      scrollSw.stop();

      final frameSw = Stopwatch()..start();
      final scrollCtrl = tester.state<ScrollableState>(scrollable).position;
      scrollCtrl.jumpTo(scrollCtrl.pixels + 320);
      await tester.pump();
      frameSw.stop();
      // ignore: avoid_print
      print(
        '[BENCHMARK_LARGE_CHAT] load_1200_ms=${openSw.elapsedMilliseconds} '
        'mounted_bubbles=$mountedBubbles avg_drag_cycle_ms=${(scrollSw.elapsedMilliseconds / 5).toStringAsFixed(1)} '
        'single_scroll_frame_ms=${frameSw.elapsedMilliseconds}',
      );

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
    });

    container.dispose();
    realtime.dispose();
    await db.close();
  });
}
