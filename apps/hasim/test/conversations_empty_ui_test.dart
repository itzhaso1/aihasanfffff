import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/contacts/data/contact_repository.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/conversations/presentation/conversations_screen.dart';
import 'support/isolated_api_client.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_filter_chip.dart';
import 'package:hasim/features/conversations/presentation/widgets/inbox_empty_illustration.dart';
import 'package:hasim/features/settings/data/plan_channel_repository.dart';
import 'package:hasim/l10n/app_localizations.dart';
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

class _FakeCustomerRepository extends CustomerRepository {
  _FakeCustomerRepository(super.api);

  @override
  Future<CustomerModel> show(int id) async {
    return CustomerModel(id: id, name: 'أحمد السالم', phone: '+966501234567', email: 'ahmed@example.com');
  }

  @override
  Future<List<ConversationModel>> conversations(int id) async => const [];
}

class _FakeContactRepository extends ContactRepository {
  _FakeContactRepository(super.api);

  @override
  Future<List<EmailContactModel>> findByEmail(String email) async => const [];

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
    return EmailContactModel(id: 1, name: name, email: email, notes: notes);
  }

  @override
  Future<EmailContactModel> update(
    int id, {
    String? name,
    String? email,
    String? phone,
    String? company,
    String? jobTitle,
    String? notes,
    bool? isFavorite,
  }) async {
    return EmailContactModel(id: id, name: name ?? 'contact', email: email ?? '', notes: notes);
  }
}

class _FakeConversationRepository extends ConversationRepository {
  _FakeConversationRepository(super.api, {this.items = const []});

  List<ConversationModel> items;
  String lastFilter = 'all';
  String? lastChannel;
  String? lastSearch;
  int? lastArchivedId;
  bool? lastArchivedValue;
  int? lastMutedId;
  bool? lastMutedValue;
  Object? archiveError;
  Object? muteError;

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
    final visible = items.where((item) {
      if (filter == 'archived') return item.archived;
      if (item.archived) return false;
      if (filter == 'unread') return item.unreadCount > 0;
      return true;
    }).toList();
    return (items: visible, nextCursor: null);
  }

  @override
  Future<ConversationModel> getById(int id, {int? workspaceId}) async {
    return items.firstWhere((item) => item.id == id, orElse: () => items.first);
  }

  @override
  Future<({List<MessageModel> items, String? nextCursor})> messages(
    int conversationId, {
    String? cursor,
    int? workspaceId,
  }) async {
    return (items: const <MessageModel>[], nextCursor: null);
  }

  @override
  Future<void> markRead(int conversationId, {int? messageId, int? workspaceId}) async {}

  @override
  Future<void> archive(int conversationId, {bool archived = true, int? workspaceId}) async {
    lastArchivedId = conversationId;
    lastArchivedValue = archived;
    if (archiveError != null) throw archiveError!;
    items = [
      for (final item in items)
        if (item.id == conversationId) item.copyWith(archived: archived) else item,
    ];
  }

  @override
  Future<void> mute(int conversationId, {bool muted = true, int? workspaceId}) async {
    lastMutedId = conversationId;
    lastMutedValue = muted;
    if (muteError != null) throw muteError!;
    items = [
      for (final item in items)
        if (item.id == conversationId) item.copyWith(muted: muted) else item,
    ];
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
        apiClientProvider.overrideWithValue(api),
        conversationRepositoryProvider.overrideWithValue(repo),
        customerRepositoryProvider.overrideWithValue(_FakeCustomerRepository(api)),
        contactRepositoryProvider.overrideWithValue(_FakeContactRepository(api)),
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
    expect(find.text('المحادثات'), findsNothing);
    expect(find.text('كل محادثاتك في مكان واحد'), findsNothing);
    expect(find.text('ابحث في المحادثات'), findsOneWidget);
    expect(find.text('الكل'), findsOneWidget);
    expect(find.text('غير مقروءة'), findsNothing);
    expect(find.text('مؤرشفة'), findsOneWidget);
    expect(find.text('المفضلة'), findsNothing);
    expect(find.text('واتساب'), findsOneWidget);
    expect(find.text('بريد'), findsOneWidget);
    expect(find.text('ويب'), findsOneWidget);
    expect(find.text('يدوي'), findsOneWidget);
    expect(find.text('تعذر تحميل المحادثات.'), findsNothing);
    expect(find.byType(InboxEmptyIllustration), findsOneWidget);
    expect(find.text('لا توجد محادثات حاليًا'), findsOneWidget);
    expect(find.text('ستظهر هنا رسائل واتساب والويب والقنوات الموحدة.'), findsOneWidget);
    expect(find.text('ابدأ محادثة جديدة'), findsOneWidget);

    await tester.tap(find.text('واتساب'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(repo.lastChannel, 'whatsapp');

    await tester.tap(find.text('ابدأ محادثة جديدة'));
    await tester.pumpAndSettle();
    expect(find.text('شاشة جهات الاتصال'), findsOneWidget);
  });

  testWidgets('populated conversations list still opens a thread', (tester) async {
    tester.view.physicalSize = const Size(390, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

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

  testWidgets('desktop split keeps the list and shows the empty chat pane', (tester) async {
    tester.view.physicalSize = const Size(1280, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeConversationRepository(api);
    await tester.pumpWidget(app(repo: repo));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    expect(find.text('المحادثات'), findsWidgets);
    expect(find.text('كل محادثاتك في مكان واحد'), findsNothing);
    expect(find.text('ابحث في المحادثات'), findsOneWidget);
    final bar = find.byKey(const Key('desktop-conversation-filters-row'));
    expect(find.descendant(of: bar, matching: find.text('الكل')), findsOneWidget);
    expect(find.descendant(of: bar, matching: find.text('غير مقروءة')), findsOneWidget);
    expect(find.descendant(of: bar, matching: find.text('مؤرشفة')), findsOneWidget);
    final hasDropdown = find.descendant(of: bar, matching: find.byKey(const Key('conversation-channel-dropdown'))).evaluate().isNotEmpty;
    final hasInlineChannel = find.descendant(of: bar, matching: find.byKey(const Key('conversation-extra-whatsapp'))).evaluate().isNotEmpty;
    expect(hasDropdown || hasInlineChannel, isTrue);
    expect(find.byKey(const Key('conversation-panel-resize-handle')), findsOneWidget);
    expect(find.byType(InboxEmptyIllustration), findsOneWidget);
    expect(find.text('لا توجد محادثات حاليًا'), findsOneWidget);
    expect(find.byType(NavigationBar), findsNothing);
  });

  testWidgets('desktop filter chips stay visible in one horizontal row', (tester) async {
    tester.view.physicalSize = const Size(1280, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeConversationRepository(api);
    await tester.pumpWidget(app(repo: repo));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    final bar = find.byKey(const Key('desktop-conversation-filters-row'));
    expect(find.descendant(of: bar, matching: find.text('الكل')), findsOneWidget);
    expect(find.descendant(of: bar, matching: find.text('غير مقروءة')), findsOneWidget);
    expect(find.descendant(of: bar, matching: find.text('مؤرشفة')), findsOneWidget);
    const extras = [80.0, 70.0, 60.0, 75.0];
    const primary = [70.0, 110.0, 100.0];
    // الصف الواسع = 3 أساسية + 3 إضافية + زر القائمة + 6 فراغات = 570.
    int promoted(double width) => conversationPromotedExtraCount(
          availableWidth: width,
          primaryWidths: primary,
          extraWidths: extras,
          dropdownWidth: 32,
        );
    expect(promoted(700), 3);
    expect(promoted(570), 3);
    expect(promoted(569), 2);
    expect(promoted(502), 2);
    expect(promoted(501), 1);
    expect(promoted(424), 1);
    expect(promoted(423), 0);
    expect(promoted(300), 0);
    expect(tester.takeException(), isNull);
  });

  testWidgets('desktop filters: narrow hides extras progressively, wide shows 3 + menu', (tester) async {
    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    // خط الاختبار (Ahem) أعرض بكثير من الخط الحقيقي.
    tester.platformDispatcher.textScaleFactorTestValue = 0.7;
    addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);

    final repo = _FakeConversationRepository(api);
    await tester.pumpWidget(app(repo: repo));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    final bar = find.byKey(const Key('desktop-conversation-filters-row'));
    Finder extra(String id) => find.descendant(of: bar, matching: find.byKey(Key('conversation-extra-$id')));
    final dropdown = find.descendant(of: bar, matching: find.byKey(const Key('conversation-channel-dropdown')));
    final handle = find.byKey(const Key('conversation-panel-resize-handle'));

    // الوضع الافتراضي ضيق (~340px): القنوات في القائمة والسهم ظاهر.
    for (final id in ['whatsapp', 'email', 'web', 'manual']) {
      expect(extra(id), findsNothing);
    }
    expect(dropdown, findsOneWidget);

    // توسيع اللوحة (RTL: السحب لليسار يوسّعها).
    await tester.drag(handle, const Offset(-400, 0));
    await tester.pumpAndSettle();
    await tester.pump();
    await tester.pumpAndSettle();
    expect(extra('whatsapp'), findsOneWidget);
    expect(extra('email'), findsOneWidget);
    expect(extra('web'), findsOneWidget);
    expect(extra('manual'), findsNothing);
    expect(dropdown, findsOneWidget);

    await tester.tap(dropdown);
    await tester.pumpAndSettle();
    final menuItem = find.descendant(of: find.byType(PopupMenuItem<String>), matching: find.text('رسيدي'));
    expect(menuItem, findsOneWidget);
    await tester.tap(menuItem);
    await tester.pumpAndSettle();
    expect(repo.lastChannel, 'manual');

    // تصغير اللوحة مرة أخرى: تختفي الشرائح تدريجيًا وتعود للقائمة.
    await tester.drag(handle, const Offset(400, 0));
    await tester.pumpAndSettle();
    await tester.pump();
    await tester.pumpAndSettle();
    for (final id in ['whatsapp', 'email', 'web', 'manual']) {
      expect(extra(id), findsNothing);
    }
    expect(dropdown, findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('desktop split opens a thread beside the list without leaving it', (tester) async {
    tester.view.physicalSize = const Size(1280, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

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

    expect(find.text('أحمد'), findsOneWidget);
    await tester.tap(find.text('أحمد'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.text('أحمد'), findsWidgets);
    expect(find.text('محادثة 7'), findsNothing);
    expect(find.text('ابحث في المحادثات'), findsOneWidget);
  });

  testWidgets('desktop customer drawer opens beside chat and closes without leaving the list', (tester) async {
    tester.view.physicalSize = const Size(1400, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeConversationRepository(
      api,
      items: const [
        ConversationModel(
          id: 7,
          channel: 'whatsapp',
          status: 'open',
          unreadCount: 0,
          lastMessageAt: null,
          customer: CustomerModel(
            id: 3,
            name: 'أحمد السالم',
            phone: '+966501234567',
            email: 'ahmed@example.com',
          ),
        ),
      ],
    );

    await tester.pumpWidget(app(repo: repo));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    expect(find.text('معلومات العميل'), findsNothing);
    await tester.tap(find.text('أحمد السالم').first);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.byKey(const Key('desktop-customer-info')), findsOneWidget);
    await tester.tap(find.byKey(const Key('desktop-customer-info')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.text('معلومات العميل'), findsOneWidget);
    expect(find.text('+966501234567'), findsOneWidget);
    expect(find.text('اتصال'), findsOneWidget);
    expect(find.text('ابحث في المحادثات'), findsOneWidget);

    await tester.tap(find.byKey(const Key('customer-drawer-close')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 200));

    expect(find.text('معلومات العميل'), findsNothing);
    expect(find.text('أحمد السالم'), findsWidgets);
    expect(find.text('ابحث في المحادثات'), findsOneWidget);
  });

  testWidgets('mobile layout never shows the customer drawer', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeConversationRepository(
      api,
      items: const [
        ConversationModel(
          id: 7,
          channel: 'whatsapp',
          status: 'open',
          customer: CustomerModel(id: 3, name: 'أحمد السالم'),
        ),
      ],
    );

    await tester.pumpWidget(app(repo: repo));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('desktop-customer-info')), findsNothing);
    expect(find.text('معلومات العميل'), findsNothing);
  });

  testWidgets('archive action calls repository and leaves the inbox', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeConversationRepository(
      api,
      items: const [
        ConversationModel(id: 7, channel: 'whatsapp', status: 'open', externalId: 'أحمد'),
      ],
    );

    await tester.pumpWidget(app(repo: repo));
    await tester.pumpAndSettle();
    expect(find.text('أحمد'), findsOneWidget);

    await tester.tap(find.byKey(const Key('conversation-actions-7')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('أرشفة'));
    await tester.pumpAndSettle();

    expect(repo.lastArchivedId, 7);
    expect(repo.lastArchivedValue, isTrue);
    expect(find.text('أحمد'), findsNothing);

    await tester.tap(find.text('مؤرشفة'));
    await tester.pumpAndSettle();
    expect(repo.lastFilter, 'archived');
    expect(find.text('أحمد'), findsOneWidget);

    await tester.tap(find.byKey(const Key('conversation-actions-7')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('إلغاء الأرشفة'));
    await tester.pumpAndSettle();
    expect(repo.lastArchivedValue, isFalse);
    expect(find.text('أحمد'), findsNothing);
  });

  testWidgets('archive error restores the conversation in the inbox', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeConversationRepository(
      api,
      items: const [
        ConversationModel(id: 7, channel: 'whatsapp', status: 'open', externalId: 'أحمد'),
      ],
    )..archiveError = ApiException('blocked', statusCode: 403);

    await tester.pumpWidget(app(repo: repo));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('conversation-actions-7')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('أرشفة'));
    await tester.pumpAndSettle();

    expect(repo.lastArchivedId, 7);
    expect(find.text('أحمد'), findsOneWidget);
    expect(find.text('تعذر أرشفة المحادثة.'), findsOneWidget);
    expect(find.byType(CircularProgressIndicator), findsNothing);
  });

  testWidgets('mute action calls repository and unmute reverses it', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeConversationRepository(
      api,
      items: const [
        ConversationModel(id: 7, channel: 'whatsapp', status: 'open', externalId: 'أحمد'),
      ],
    );

    await tester.pumpWidget(app(repo: repo));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('conversation-actions-7')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('كتم'));
    await tester.pumpAndSettle();

    expect(repo.lastMutedId, 7);
    expect(repo.lastMutedValue, isTrue);
    expect(find.byIcon(Icons.notifications_off_outlined), findsOneWidget);

    await tester.tap(find.byKey(const Key('conversation-actions-7')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('إلغاء الكتم'));
    await tester.pumpAndSettle();
    expect(repo.lastMutedValue, isFalse);
    expect(find.byIcon(Icons.notifications_off_outlined), findsNothing);
  });

  testWidgets('mute error restores the unmuted UI', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeConversationRepository(
      api,
      items: const [
        ConversationModel(id: 7, channel: 'whatsapp', status: 'open', externalId: 'أحمد'),
      ],
    )..muteError = ApiException('blocked', statusCode: 422);

    await tester.pumpWidget(app(repo: repo));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('conversation-actions-7')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('كتم'));
    await tester.pumpAndSettle();

    expect(find.byIcon(Icons.notifications_off_outlined), findsNothing);
    expect(find.text('تعذر كتم المحادثة.'), findsOneWidget);
  });

  testWidgets('chat details archive action calls the same repository', (tester) async {
    tester.view.physicalSize = const Size(1280, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeConversationRepository(
      api,
      items: const [
        ConversationModel(id: 7, channel: 'whatsapp', status: 'open', externalId: 'أحمد'),
      ],
    );

    await tester.pumpWidget(app(repo: repo));
    await tester.pumpAndSettle();
    await tester.tap(find.text('أحمد'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.byKey(const Key('desktop-customer-info')), findsOneWidget);

    await tester.tap(find.byIcon(Icons.more_horiz_rounded));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('chat-action-archive')));
    await tester.pumpAndSettle();

    expect(repo.lastArchivedId, 7);
    expect(repo.lastArchivedValue, isTrue);
  });
}
