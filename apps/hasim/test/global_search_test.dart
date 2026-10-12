import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/widgets/hasim_shell_header.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/search/data/search_repository.dart';
import 'package:hasim/features/search/presentation/global_search_screen.dart';
import 'package:hasim/features/search/providers/search_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _MemorySecureStore extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}

  @override
  Future<String?> readToken() async => 'token';

  @override
  Future<void> clearToken() async {}
}

class _MutableAuthController extends AuthController {
  _MutableAuthController(super.ref, this.seed);

  final AuthState seed;

  @override
  Future<void> bootstrap() async {
    state = seed;
  }

  void seedWorkspace(WorkspaceModel workspace) {
    state = state.copyWith(workspace: workspace);
  }
}

class _FakeSearchRepository extends SearchRepository {
  _FakeSearchRepository(super.api);

  final queries = <String>[];
  final limits = <int>[];
  SearchPayload payload = const SearchPayload();
  final Map<String, SearchPayload> byQuery = {};
  Object? error;
  Completer<void>? gate;

  @override
  Future<SearchPayload> search(String query, {int limit = 10}) async {
    queries.add(query);
    limits.add(limit);
    final hold = gate;
    if (hold != null) {
      await hold.future;
    }
    final thrown = error;
    if (thrown != null) {
      if (thrown is Exception) {
        throw thrown;
      }
      throw ApiException(thrown.toString());
    }
    return byQuery[query] ?? payload;
  }
}

const _companyAuth = AuthState(
  bootstrapping: false,
  token: 'company-token',
  user: UserModel(id: 1, name: 'شركة'),
  workspace: WorkspaceModel(id: 4, name: 'مساحة'),
  participant: MobileParticipant(type: 'company_staff'),
);

const _workerAuth = AuthState(
  bootstrapping: false,
  token: 'worker-token',
  user: UserModel(id: 3, name: 'عامل'),
  participant: MobileParticipant(type: 'independent_worker'),
);

const _conversation = ConversationModel(
  id: 7,
  channel: 'whatsapp',
  status: 'open',
  customer: CustomerModel(id: 2, name: 'أحمد السالم'),
  lastMessage: MessageModel(
    id: 1,
    conversationId: 7,
    direction: 'inbound',
    messageType: 'text',
    content: 'مرحبا',
  ),
);

const _customer = CustomerModel(
  id: 2,
  name: 'أحمد السالم',
  phone: '+966500000000',
  email: 'ahmed@example.com',
);

const _email = EmailMessageModel(
  id: 9,
  sender: 'from@example.com',
  recipient: 'to@example.com',
  subject: 'فاتورة أكتوبر',
  preview: 'مقطف ظاهر فقط',
  body: 'سر داخلي يجب ألا يظهر في البحث',
);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  late SharedPreferences prefs;
  late _FakeSearchRepository repo;

  setUp(() async {
    SharedPreferences.setMockInitialValues({'workspace_id': 4});
    prefs = await SharedPreferences.getInstance();
    repo = _FakeSearchRepository(
      isolatedTestApiClient(
        secureStore: _MemorySecureStore(),
        prefsStore: PrefsStore(prefs),
      ),
    );
    repo.payload = const SearchPayload(
      conversations: [_conversation],
      customers: [_customer],
      emails: [_email],
    );
  });

  Widget app({
    AuthState auth = _companyAuth,
    _MutableAuthController? Function(Ref ref)? authFactory,
    String initialLocation = '/search',
  }) {
    final router = GoRouter(
      initialLocation: initialLocation,
      routes: [
        GoRoute(
          path: '/conversations',
          builder: (context, state) => const Scaffold(
            appBar: HasimShellHeader(),
            body: Text('صندوق الوارد'),
          ),
        ),
        GoRoute(
          path: '/search',
          builder: (context, state) => const GlobalSearchScreen(),
        ),
        GoRoute(
          path: '/conversations/:id',
          builder: (context, state) => Scaffold(
            appBar: AppBar(),
            body: Text('محادثة ${state.pathParameters['id']}'),
          ),
        ),
        GoRoute(
          path: '/customers/:id',
          builder: (context, state) => Scaffold(
            appBar: AppBar(),
            body: Text('عميل ${state.pathParameters['id']}'),
          ),
        ),
        GoRoute(
          path: '/email/:id',
          builder: (context, state) => Scaffold(
            appBar: AppBar(),
            body: Text('بريد ${state.pathParameters['id']}'),
          ),
        ),
        GoRoute(
          path: '/profile',
          builder: (context, state) => const Scaffold(body: Text('الملف')),
        ),
      ],
    );

    return ProviderScope(
      overrides: [
        authControllerProvider.overrideWith((ref) {
          if (authFactory != null) {
            return authFactory(ref) ?? _MutableAuthController(ref, auth);
          }
          return _MutableAuthController(ref, auth);
        }),
        sharedPrefsProvider.overrideWithValue(prefs),
        secureStoreProvider.overrideWithValue(_MemorySecureStore()),
        apiClientProvider.overrideWithValue(
          isolatedTestApiClient(
            secureStore: _MemorySecureStore(),
            prefsStore: PrefsStore(prefs),
          ),
        ),
        searchRepositoryProvider.overrideWithValue(repo),
        realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
      ],
      child: MaterialApp.router(
        theme: AppTheme.light(),
        locale: const Locale('ar'),
        supportedLocales: AppLocalizations.supportedLocales,
        localizationsDelegates: AppLocalizations.localizationsDelegates,
        builder: (context, child) => Directionality(
          textDirection: TextDirection.rtl,
          child: child ?? const SizedBox.shrink(),
        ),
        routerConfig: router,
      ),
    );
  }

  Future<void> pumpDebounce(WidgetTester tester) async {
    await tester.pump();
    await tester.pump(GlobalSearchController.debounceDuration);
    await tester.pump();
  }

  testWidgets('company header opens global search', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(app(initialLocation: '/conversations'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.byKey(const Key('hasim-global-search-btn')), findsOneWidget);
    await tester.tap(find.byKey(const Key('hasim-global-search-btn')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('global-search-screen')), findsOneWidget);
    expect(find.text('ابحث في المحادثات والعملاء والبريد'), findsOneWidget);
    expect(repo.queries, isEmpty);
  });

  testWidgets('independent worker does not get company global search', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      app(auth: _workerAuth, initialLocation: '/conversations'),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.byKey(const Key('hasim-global-search-btn')), findsNothing);
    expect(find.byKey(const Key('global-search-screen')), findsNothing);
  });

  testWidgets('search request shows conversation customer and email results', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(app());
    await tester.pump();

    await tester.enterText(find.byType(TextField), 'أحمد');
    await pumpDebounce(tester);

    expect(repo.queries, ['أحمد']);
    expect(repo.limits.single, 10);
    expect(find.byKey(const Key('search-conversation-7')), findsOneWidget);
    expect(find.byKey(const Key('search-customer-2')), findsOneWidget);
    expect(find.byKey(const Key('search-email-9')), findsOneWidget);
    expect(find.text('أحمد السالم'), findsWidgets);
    expect(find.text('فاتورة أكتوبر'), findsOneWidget);
    expect(find.text('محادثة'), findsOneWidget);
    expect(find.text('عميل'), findsOneWidget);
    expect(find.text('بريد'), findsOneWidget);
    expect(find.text('سر داخلي يجب ألا يظهر في البحث'), findsNothing);
  });

  testWidgets('empty results and error states are distinct', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    repo.payload = const SearchPayload();
    await tester.pumpWidget(app());
    await tester.pump();

    await tester.enterText(find.byType(TextField), 'لاشيء');
    await pumpDebounce(tester);
    expect(find.text('لا توجد نتائج'), findsOneWidget);

    repo.error = ApiException('تعذر تنفيذ البحث.');
    await tester.enterText(find.byType(TextField), 'خطأ');
    await pumpDebounce(tester);
    expect(find.text('تعذر تنفيذ البحث.'), findsOneWidget);
    expect(find.text('إعادة المحاولة'), findsOneWidget);
  });

  testWidgets('clear search drops results and does not request empty query', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(app());
    await tester.pump();

    await tester.enterText(find.byType(TextField), 'أحمد');
    await pumpDebounce(tester);
    expect(find.byKey(const Key('search-conversation-7')), findsOneWidget);

    await tester.tap(find.byTooltip('مسح'));
    await tester.pump();
    await tester.pump(GlobalSearchController.debounceDuration);

    expect(find.byKey(const Key('search-conversation-7')), findsNothing);
    expect(find.text('ابحث في حاسم'), findsOneWidget);
    expect(repo.queries, ['أحمد']);
  });

  testWidgets('debounce keeps only the latest query and drops stale results', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    repo.byQuery['قديم'] = const SearchPayload(
      conversations: [
        ConversationModel(
          id: 1,
          channel: 'web',
          status: 'open',
          customer: CustomerModel(id: 1, name: 'نتيجة قديمة'),
        ),
      ],
    );
    repo.byQuery['جديد'] = const SearchPayload(
      conversations: [
        ConversationModel(
          id: 2,
          channel: 'web',
          status: 'open',
          customer: CustomerModel(id: 2, name: 'نتيجة جديدة'),
        ),
      ],
    );
    repo.gate = Completer<void>();

    await tester.pumpWidget(app());
    await tester.pump();

    await tester.enterText(find.byType(TextField), 'قديم');
    await pumpDebounce(tester);
    expect(repo.queries, ['قديم']);

    await tester.enterText(find.byType(TextField), 'جديد');
    await pumpDebounce(tester);
    expect(repo.queries, ['قديم', 'جديد']);

    repo.gate!.complete();
    await tester.pump();
    await tester.pump();

    expect(find.text('نتيجة جديدة'), findsOneWidget);
    expect(find.text('نتيجة قديمة'), findsNothing);
  });

  testWidgets('tapping a result during loading does not navigate', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    repo.gate = Completer<void>();
    await tester.pumpWidget(app());
    await tester.pump();

    await tester.enterText(find.byType(TextField), 'أحمد');
    await pumpDebounce(tester);

    expect(find.byType(CircularProgressIndicator), findsOneWidget);
    expect(find.byKey(const Key('search-conversation-7')), findsNothing);
    await tester.tap(find.byType(CircularProgressIndicator), warnIfMissed: false);
    await tester.pump();
    expect(find.text('محادثة 7'), findsNothing);

    repo.gate!.complete();
    await tester.pump();
    await tester.pump();
    expect(find.byKey(const Key('search-conversation-7')), findsOneWidget);
  });

  testWidgets('result rows open existing conversation customer and email screens', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(app());
    await tester.pump();
    await tester.enterText(find.byType(TextField), 'أحمد');
    await pumpDebounce(tester);

    await tester.tap(find.byKey(const Key('search-conversation-7')));
    await tester.pumpAndSettle();
    expect(find.text('محادثة 7'), findsOneWidget);

    await tester.tap(find.byType(BackButton));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('search-customer-2')));
    await tester.pumpAndSettle();
    expect(find.text('عميل 2'), findsOneWidget);

    await tester.tap(find.byType(BackButton));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('search-email-9')));
    await tester.pumpAndSettle();
    expect(find.text('بريد 9'), findsOneWidget);
  });

  testWidgets('workspace switch clears previous search results', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    _MutableAuthController? auth;
    await tester.pumpWidget(
      app(
        authFactory: (ref) {
          auth = _MutableAuthController(ref, _companyAuth);
          return auth;
        },
      ),
    );
    await tester.pump();
    await tester.enterText(find.byType(TextField), 'أحمد');
    await pumpDebounce(tester);
    expect(find.byKey(const Key('search-conversation-7')), findsOneWidget);

    auth!.seedWorkspace(const WorkspaceModel(id: 99, name: 'أخرى'));
    await tester.pump();

    expect(find.byKey(const Key('search-conversation-7')), findsNothing);
    expect(find.text('ابحث في حاسم'), findsOneWidget);
    expect(repo.queries, ['أحمد']);
  });

  testWidgets('empty and whitespace queries never hit the API', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(app());
    await tester.pump();

    await tester.enterText(find.byType(TextField), '   ');
    await pumpDebounce(tester);
    expect(repo.queries, isEmpty);
    expect(find.text('ابحث في حاسم'), findsOneWidget);
  });
}
