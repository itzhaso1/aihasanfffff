import 'dart:async';

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
import 'package:hasim/features/stories/data/story_repository.dart';
import 'package:hasim/features/stories/presentation/story_viewer_screen.dart';
import 'package:hasim/features/stories/presentation/updates_screen.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
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

StoryModel _mine({int id = 11, int views = 3}) => StoryModel(
  id: id,
  type: 'text',
  bodyText: 'قصتي $id',
  isMine: true,
  viewsCount: views,
  author: const StoryAuthorModel(id: 1, name: 'أنا'),
);

StoryModel _other({int id = 22}) => StoryModel(
  id: id,
  type: 'text',
  bodyText: 'قصة زميل',
  isMine: false,
  author: const StoryAuthorModel(id: 8, name: 'سارة'),
);

class _FakeStoryRepository extends StoryRepository {
  _FakeStoryRepository(super.api, {required this.items});

  List<StoryModel> items;
  int listCalls = 0;
  int deleteCalls = 0;
  int viewersCalls = 0;
  int markViewedCalls = 0;
  Completer<void>? deleteGate;
  Completer<void>? viewersGate;
  Object? deleteError;
  Object? viewersError;
  List<StoryViewerEntry> viewerRows = const [];

  @override
  Future<List<StoryModel>> list() async {
    listCalls++;
    return List<StoryModel>.of(items);
  }

  @override
  Future<void> delete(int id) async {
    deleteCalls++;
    final gate = deleteGate;
    if (gate != null) {
      await gate.future;
    }
    if (deleteError != null) {
      throw deleteError!;
    }
    items = items.where((story) => story.id != id).toList();
  }

  @override
  Future<List<StoryViewerEntry>> viewers(int id) async {
    viewersCalls++;
    final gate = viewersGate;
    if (gate != null) {
      await gate.future;
    }
    if (viewersError != null) {
      throw viewersError!;
    }
    return viewerRows;
  }

  @override
  Future<StoryModel> markViewed(int id) async {
    markViewedCalls++;
    return items.firstWhere(
      (story) => story.id == id,
      orElse: () => _other(id: id),
    );
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

  List<Override> overrides(_FakeStoryRepository repo) => [
    sharedPrefsProvider.overrideWithValue(prefs),
    secureStoreProvider.overrideWithValue(_MemorySecureStore()),
    apiClientProvider.overrideWithValue(api),
    storyRepositoryProvider.overrideWithValue(repo),
    realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
  ];

  StoryViewerArgs argsFor(List<StoryModel> stories, {int bucketIndex = 0}) {
    final mine = stories.where((story) => story.isMine).toList();
    final others = stories.where((story) => !story.isMine).toList();
    final buckets = <StoryBucket>[
      if (mine.isNotEmpty)
        StoryBucket(
          authorKey: 'mine',
          authorName: 'حالتي',
          isMine: true,
          stories: mine,
        ),
      if (others.isNotEmpty)
        StoryBucket(
          authorKey: 'u-8',
          authorName: 'سارة',
          isMine: false,
          stories: others,
        ),
    ];
    return StoryViewerArgs(buckets: buckets, bucketIndex: bucketIndex);
  }

  Widget viewerApp({
    required _FakeStoryRepository repo,
    required StoryViewerArgs args,
  }) {
    return ProviderScope(
      overrides: overrides(repo),
      child: MaterialApp(
        theme: AppTheme.light(),
        locale: const Locale('ar'),
        builder: (context, child) => Directionality(
          textDirection: TextDirection.rtl,
          child: child ?? const SizedBox.shrink(),
        ),
        home: Builder(
          builder: (context) => Scaffold(
            body: TextButton(
              key: const Key('open-story'),
              onPressed: () {
                Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => StoryViewerScreen(args: args),
                  ),
                );
              },
              child: const Text('open'),
            ),
          ),
        ),
      ),
    );
  }

  Future<void> openViewer(
    WidgetTester tester, {
    required _FakeStoryRepository repo,
    required List<StoryModel> stories,
    int bucketIndex = 0,
  }) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(
      viewerApp(repo: repo, args: argsFor(stories, bucketIndex: bucketIndex)),
    );
    await tester.tap(find.byKey(const Key('open-story')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
  }

  testWidgets('delete and viewers are visible only for the author', (
    tester,
  ) async {
    final repo = _FakeStoryRepository(api, items: [_mine(), _other()]);
    await openViewer(tester, repo: repo, stories: [_mine(), _other()]);
    expect(find.byKey(const Key('story-delete')), findsOneWidget);
    expect(find.byKey(const Key('story-viewers')), findsOneWidget);
  });

  testWidgets('delete and viewers are hidden for another member story', (
    tester,
  ) async {
    final repo = _FakeStoryRepository(api, items: [_mine(), _other()]);
    await openViewer(
      tester,
      repo: repo,
      stories: [_mine(), _other()],
      bucketIndex: 1,
    );
    expect(find.text('قصة زميل'), findsOneWidget);
    expect(find.byKey(const Key('story-delete')), findsNothing);
    expect(find.byKey(const Key('story-viewers')), findsNothing);
  });

  testWidgets('delete confirmation can be cancelled', (tester) async {
    final repo = _FakeStoryRepository(api, items: [_mine()]);
    await openViewer(tester, repo: repo, stories: [_mine()]);
    await tester.tap(find.byKey(const Key('story-delete')));
    await tester.pump();
    expect(find.text('حذف التحديث'), findsOneWidget);
    await tester.tap(find.byKey(const Key('story-delete-cancel')));
    await tester.pump();
    expect(repo.deleteCalls, 0);
    expect(find.text('قصتي 11'), findsOneWidget);
  });

  testWidgets('successful delete pops the viewer', (tester) async {
    final repo = _FakeStoryRepository(api, items: [_mine()]);
    await openViewer(tester, repo: repo, stories: [_mine()]);
    await tester.tap(find.byKey(const Key('story-delete')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('story-delete-confirm')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();
    expect(repo.deleteCalls, 1);
    expect(find.byType(StoryViewerScreen), findsNothing);
    expect(find.byKey(const Key('open-story')), findsOneWidget);
  });

  testWidgets('failed delete keeps the story and shows the error', (
    tester,
  ) async {
    final repo = _FakeStoryRepository(api, items: [_mine(), _other()])
      ..deleteError = ApiException('يمكنك حذف قصصك فقط.', statusCode: 403);
    await openViewer(tester, repo: repo, stories: [_mine(), _other()]);
    await tester.tap(find.byKey(const Key('story-delete')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('story-delete-confirm')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(find.byType(SnackBar), findsWidgets);
    expect(find.text('يمكنك حذف قصصك فقط.'), findsWidgets);
    expect(find.text('قصتي 11'), findsOneWidget);
    expect(repo.deleteCalls, 1);
  });

  testWidgets('duplicate delete while loading is ignored', (tester) async {
    final repo = _FakeStoryRepository(api, items: [_mine()])
      ..deleteGate = Completer<void>();
    await openViewer(tester, repo: repo, stories: [_mine()]);
    await tester.tap(find.byKey(const Key('story-delete')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('story-delete-confirm')));
    await tester.pump();
    expect(find.byKey(const Key('story-delete-loading')), findsOneWidget);
    await tester.tap(find.byKey(const Key('story-delete')), warnIfMissed: false);
    await tester.pump(const Duration(milliseconds: 20));
    expect(repo.deleteCalls, 1);
    repo.deleteGate!.complete();
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(repo.deleteCalls, 1);
  });

  testWidgets('successful delete removes the item from the updates list', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeStoryRepository(api, items: [_mine(), _other()]);
    final router = GoRouter(
      routes: [
        GoRoute(path: '/', builder: (context, state) => const UpdatesScreen()),
        GoRoute(
          path: '/stories/create',
          builder: (context, state) =>
              const Scaffold(body: Text('شاشة إنشاء التحديث')),
        ),
        GoRoute(
          path: '/stories/view',
          builder: (context, state) {
            final extra = state.extra as Map;
            final buckets =
                (extra['buckets'] as List?)?.whereType<StoryBucket>().toList() ??
                const <StoryBucket>[];
            return StoryViewerScreen(
              args: StoryViewerArgs(
                buckets: buckets,
                bucketIndex: (extra['bucketIndex'] as num?)?.toInt() ?? 0,
              ),
            );
          },
        ),
      ],
    );

    await tester.pumpWidget(
      ProviderScope(
        overrides: overrides(repo),
        child: MaterialApp.router(
          theme: AppTheme.light(),
          locale: const Locale('ar'),
          builder: (context, child) => Directionality(
            textDirection: TextDirection.rtl,
            child: child ?? const SizedBox.shrink(),
          ),
          routerConfig: router,
        ),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(find.byKey(const Key('updates-author-u-8')), findsOneWidget);

    await tester.tap(find.byKey(const Key('updates-profile-bubble')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(find.byKey(const Key('story-delete')), findsOneWidget);

    await tester.tap(find.byKey(const Key('story-delete')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('story-delete-confirm')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.byType(UpdatesScreen), findsOneWidget);
    expect(find.byKey(const Key('updates-author-u-8')), findsOneWidget);
    expect(find.text('سارة'), findsWidgets);
    expect(repo.listCalls, 1);
    expect(repo.deleteCalls, 1);
  });

  testWidgets('viewers opens and shows Laravel rows', (tester) async {
    final repo = _FakeStoryRepository(api, items: [_mine()])
      ..viewerRows = [
        StoryViewerEntry(
          userId: 987654,
          name: 'سارة',
          viewedAt: DateTime.utc(2026, 10, 5, 8, 15),
        ),
      ];
    await openViewer(tester, repo: repo, stories: [_mine()]);
    final listCallsBefore = repo.listCalls;
    await tester.tap(find.byKey(const Key('story-viewers')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(find.byKey(const Key('story-viewers-list')), findsOneWidget);
    expect(find.text('سارة'), findsOneWidget);
    expect(find.text('987654'), findsNothing);
    expect(find.text('قصتي 11'), findsOneWidget);
    expect(repo.viewersCalls, 1);
    expect(repo.listCalls, listCallsBefore);
  });

  testWidgets('viewers empty state', (tester) async {
    final repo = _FakeStoryRepository(api, items: [_mine()]);
    await openViewer(tester, repo: repo, stories: [_mine()]);
    await tester.tap(find.byKey(const Key('story-viewers')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(find.byKey(const Key('story-viewers-empty')), findsOneWidget);
  });

  testWidgets('viewers error state', (tester) async {
    final repo = _FakeStoryRepository(api, items: [_mine()])
      ..viewersError = ApiException(
        'عرض المشاهدين متاح للكاتب فقط.',
        statusCode: 403,
      );
    await openViewer(tester, repo: repo, stories: [_mine()]);
    await tester.tap(find.byKey(const Key('story-viewers')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(find.byKey(const Key('story-viewers-error')), findsOneWidget);
    expect(find.text('عرض المشاهدين متاح للكاتب فقط.'), findsOneWidget);
    expect(find.text('قصتي 11'), findsOneWidget);
  });

  testWidgets('viewers loading state', (tester) async {
    final repo = _FakeStoryRepository(api, items: [_mine()])
      ..viewersGate = Completer<void>();
    await openViewer(tester, repo: repo, stories: [_mine()]);
    await tester.tap(find.byKey(const Key('story-viewers')));
    await tester.pump();
    expect(find.byKey(const Key('story-viewers-loading')), findsOneWidget);
    repo.viewersGate!.complete();
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(find.byKey(const Key('story-viewers-empty')), findsOneWidget);
  });
}
