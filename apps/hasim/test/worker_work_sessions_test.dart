import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/worker/data/worker_dispute.dart';
import 'package:hasim/features/worker/data/worker_earning.dart';
import 'package:hasim/features/worker/data/worker_payout.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';
import 'package:hasim/features/worker/data/worker_product.dart';
import 'package:hasim/features/worker/data/worker_work_session.dart';
import 'package:hasim/features/worker/presentation/worker_engagement_detail_screen.dart';
import 'package:hasim/features/worker/presentation/worker_scaffold.dart';
import 'package:hasim/features/worker/providers/worker_work_session_controller.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemorySecureStore extends SecureStore {
  _MemorySecureStore(this._token);
  String? _token;

  @override
  Future<void> saveToken(String token) async => _token = token;

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> clearToken() async => _token = null;
}

Map<String, dynamic> _sessionJson({
  int id = 11,
  int engagementId = 7,
  String status = 'started',
  String? startedAt = '2026-09-27T10:00:00.000000Z',
  String? endedAt,
  int? durationSeconds,
  List<Map<String, dynamic>>? segments,
}) {
  return {
    'id': id,
    'worker_engagement_id': engagementId,
    'status': status,
    'started_at': startedAt,
    'ended_at': endedAt,
    'duration_seconds': durationSeconds,
    'segments':
        segments ??
        [
          {
            'id': 21,
            'segment_type': 'active',
            'started_at': startedAt,
            'ended_at': endedAt,
          },
        ],
  };
}

WorkerWorkSession _session({
  int id = 11,
  int engagementId = 7,
  String status = 'started',
  String? startedAt = '2026-09-27T10:00:00.000000Z',
  String? endedAt,
  int? durationSeconds,
  List<Map<String, dynamic>>? segments,
}) {
  return WorkerWorkSession.fromJson(
    _sessionJson(
      id: id,
      engagementId: engagementId,
      status: status,
      startedAt: startedAt,
      endedAt: endedAt,
      durationSeconds: durationSeconds,
      segments: segments,
    ),
  );
}

WorkerEngagement _engagement({int id = 7, String status = 'active'}) {
  return WorkerEngagement.fromJson({
    'id': id,
    'status': status,
    'job_title': 'دعم العملاء',
    'workspace': {'id': 4, 'name': 'شركة النور'},
    'capabilities': const [],
  });
}

class _FakeWorkerRepository extends WorkerRepository {
  _FakeWorkerRepository(super.api);

  WorkerEngagement engagementValue = _engagement();
  List<WorkerWorkSession> sessions = const [];
  Object? sessionFailure;
  Object? actionFailure;
  int sessionCalls = 0;
  int actionCalls = 0;
  int? lastEngagementId;
  String? lastAction;
  Completer<List<WorkerWorkSession>>? sessionGate;
  Completer<WorkerWorkSession>? actionGate;

  @override
  Future<WorkerEngagement> engagement(int id) async => engagementValue;

  @override
  Future<List<WorkerProduct>> products(int engagementId) async => const [];

  @override
  Future<List<WorkerWorkSession>> getWorkSession(int engagementId) async {
    sessionCalls += 1;
    lastEngagementId = engagementId;
    final gate = sessionGate;
    if (gate != null) return gate.future;
    final failure = sessionFailure;
    if (failure != null) throw failure;
    return sessions;
  }

  @override
  Future<List<WorkerEarningPeriod>> getWorkerEarnings(int engagementId) async =>
      const [];

  @override
  Future<List<WorkerPayout>> getWorkerPayouts() async => const [];

  @override
  Future<WorkerDisputePage> getWorkerDisputes([
    WorkerDisputeFilters filters = const WorkerDisputeFilters(),
  ]) async {
    return const WorkerDisputePage(
      items: [],
      total: 0,
      perPage: 20,
      currentPage: 1,
      lastPage: 1,
    );
  }

  @override
  Future<WorkerWorkSession> startWorkSession(int engagementId) {
    return _action('start', engagementId);
  }

  @override
  Future<WorkerWorkSession> breakWorkSession(int engagementId) {
    return _action('break', engagementId);
  }

  @override
  Future<WorkerWorkSession> resumeWorkSession(int engagementId) {
    return _action('resume', engagementId);
  }

  @override
  Future<WorkerWorkSession> endWorkSession(int engagementId) {
    return _action('end', engagementId);
  }

  Future<WorkerWorkSession> _action(String action, int engagementId) async {
    actionCalls += 1;
    lastAction = action;
    lastEngagementId = engagementId;
    final gate = actionGate;
    if (gate != null) return gate.future;
    final failure = actionFailure;
    if (failure != null) throw failure;
    final updated = switch (action) {
      'break' => _session(
        segments: [
          {
            'id': 21,
            'segment_type': 'active',
            'started_at': '2026-09-27T10:00:00.000000Z',
            'ended_at': '2026-09-27T10:20:00.000000Z',
          },
          {
            'id': 22,
            'segment_type': 'break',
            'started_at': '2026-09-27T10:20:00.000000Z',
            'ended_at': null,
          },
        ],
      ),
      'resume' => _session(
        segments: [
          {
            'id': 21,
            'segment_type': 'active',
            'started_at': '2026-09-27T10:00:00.000000Z',
            'ended_at': '2026-09-27T10:20:00.000000Z',
          },
          {
            'id': 22,
            'segment_type': 'break',
            'started_at': '2026-09-27T10:20:00.000000Z',
            'ended_at': '2026-09-27T10:25:00.000000Z',
          },
          {
            'id': 23,
            'segment_type': 'active',
            'started_at': '2026-09-27T10:25:00.000000Z',
            'ended_at': null,
          },
        ],
      ),
      'end' => _session(
        status: 'ended',
        endedAt: '2026-09-27T10:45:00.000000Z',
        durationSeconds: 2700,
        segments: [
          {
            'id': 21,
            'segment_type': 'active',
            'started_at': '2026-09-27T10:00:00.000000Z',
            'ended_at': '2026-09-27T10:45:00.000000Z',
          },
        ],
      ),
      _ => _session(),
    };
    sessions = [
      updated,
      for (final session in sessions)
        if (session.id != updated.id) session,
    ];
    return updated;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('session and segment JSON parse started, ended, open, and idle', () {
    final started = WorkerWorkSession.fromJson(_sessionJson());
    final ended = WorkerWorkSession.fromJson(
      _sessionJson(
        status: 'ended',
        endedAt: '2026-09-27T10:05:00.000000Z',
        durationSeconds: 300,
      ),
    );
    final idle = WorkerWorkSessionSegment.fromJson({
      'id': 9,
      'segment_type': 'idle',
      'started_at': '2026-09-27T10:02:00.000000Z',
      'ended_at': null,
    });

    expect(started.id, 11);
    expect(started.workerEngagementId, 7);
    expect(started.isStarted, isTrue);
    expect(started.durationSeconds, isNull);
    expect(started.openSegment?.isOpen, isTrue);
    expect(started.openSegment?.isActive, isTrue);
    expect(started.statusLabel, 'جارية');
    expect(ended.isEnded, isTrue);
    expect(ended.durationSeconds, 300);
    expect(ended.openSegment, isNull);
    expect(ended.statusLabel, 'منتهية');
    expect(idle.isIdle, isTrue);
    expect(idle.isOpen, isTrue);
    expect(idle.typeLabel, 'خمول');
  });

  test(
    'display elapsed uses server duration and never invents a persisted value',
    () {
      final now = DateTime.parse('2026-09-27T10:10:00.000000Z');
      final live = _session();
      final ended = _session(
        status: 'ended',
        endedAt: '2026-09-27T10:05:00.000000Z',
        durationSeconds: 300,
      );

      expect(live.durationSeconds, isNull);
      expect(live.displayElapsedSeconds(now), 600);
      expect(ended.displayElapsedSeconds(now), 300);
      expect(ended.durationSeconds, 300);
    },
  );

  test(
    'repository uses engagement-scoped session endpoints without a body',
    () async {
      final calls = <String>[];
      Object? startBody;
      SharedPreferences.setMockInitialValues({});
      final prefs = PrefsStore(await SharedPreferences.getInstance());
      final dio = Dio(
        BaseOptions(baseUrl: 'http://127.0.0.1:1/api/mobile/v1/'),
      );
      dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (options, handler) {
            calls.add('${options.method} ${options.path}');
            if (options.path.contains('session/start')) {
              startBody = options.data;
            }
            final started =
                options.path.endsWith('session') && options.method == 'GET';
            handler.resolve(
              Response(
                requestOptions: options,
                data: {
                  'success': true,
                  'data': started
                      ? [_sessionJson()]
                      : _sessionJson(
                          status: options.path.contains('/end')
                              ? 'ended'
                              : 'started',
                          durationSeconds: options.path.contains('/end')
                              ? 300
                              : null,
                        ),
                },
              ),
            );
          },
        ),
      );
      final repo = WorkerRepository(
        ApiClient(
          secureStore: _MemorySecureStore('token'),
          prefsStore: prefs,
          dio: dio,
        ),
      );

      final history = await repo.getWorkSession(7);
      final started = await repo.startWorkSession(7);
      await repo.breakWorkSession(7);
      await repo.resumeWorkSession(7);
      final ended = await repo.endWorkSession(9);

      expect(history.single.workerEngagementId, 7);
      expect(started.isStarted, isTrue);
      expect(ended.isEnded, isTrue);
      expect(startBody, isNull);
      expect(calls, [
        'GET worker/engagements/7/session',
        'POST worker/engagements/7/session/start',
        'POST worker/engagements/7/session/break',
        'POST worker/engagements/7/session/resume',
        'POST worker/engagements/9/session/end',
      ]);
    },
  );

  test(
    'controller loads history, mutates from the response, and blocks duplicates',
    () async {
      final repo = await _fake();
      repo.sessions = [_session(id: 3, status: 'ended', durationSeconds: 120)];
      final container = ProviderContainer(
        overrides: [workerRepositoryProvider.overrideWithValue(repo)],
      );
      addTearDown(container.dispose);
      final sub = container.listen(workerWorkSessionProvider(7), (_, _) {});
      addTearDown(sub.close);
      await container.read(workerWorkSessionProvider(7).notifier).load();

      expect(
        container.read(workerWorkSessionProvider(7)).sessions,
        hasLength(1),
      );
      expect(
        container.read(workerWorkSessionProvider(7)).currentSession,
        isNull,
      );

      final gate = Completer<WorkerWorkSession>();
      repo.actionGate = gate;
      final first = container
          .read(workerWorkSessionProvider(7).notifier)
          .start();
      final second = container
          .read(workerWorkSessionProvider(7).notifier)
          .start();
      expect(repo.actionCalls, 1);
      expect(repo.lastEngagementId, 7);
      expect(container.read(workerWorkSessionProvider(7)).busy, isTrue);

      gate.complete(_session());
      expect(await first, isTrue);
      expect(await second, isFalse);
      repo.actionGate = null;
      expect(
        container.read(workerWorkSessionProvider(7)).currentSession?.isStarted,
        isTrue,
      );

      await container.read(workerWorkSessionProvider(7).notifier).beginBreak();
      expect(
        container
            .read(workerWorkSessionProvider(7))
            .currentSession
            ?.openSegment
            ?.isBreak,
        isTrue,
      );
      await container.read(workerWorkSessionProvider(7).notifier).resume();
      expect(
        container
            .read(workerWorkSessionProvider(7))
            .currentSession
            ?.openSegment
            ?.isActive,
        isTrue,
      );
      await container.read(workerWorkSessionProvider(7).notifier).end();
      expect(
        container.read(workerWorkSessionProvider(7)).currentSession,
        isNull,
      );
      expect(
        container
            .read(workerWorkSessionProvider(7))
            .sessions
            .first
            .durationSeconds,
        2700,
      );
    },
  );

  test(
    'controller surfaces 422, 403, and 404 without inventing a local rule',
    () async {
      final repo = await _fake();
      repo.sessionFailure = ApiException('غير موجود.', statusCode: 404);
      final container = ProviderContainer(
        overrides: [workerRepositoryProvider.overrideWithValue(repo)],
      );
      addTearDown(container.dispose);
      final sub = container.listen(workerWorkSessionProvider(7), (_, _) {});
      addTearDown(sub.close);
      await container.read(workerWorkSessionProvider(7).notifier).load();
      expect(container.read(workerWorkSessionProvider(7)).error, 'غير موجود.');

      repo.sessionFailure = null;
      repo.actionFailure = ApiException(
        'The worker engagement already has an active work session.',
        statusCode: 422,
      );
      await container.read(workerWorkSessionProvider(7).notifier).start();
      expect(
        container.read(workerWorkSessionProvider(7)).actionError,
        'The worker engagement already has an active work session.',
      );

      repo.actionFailure = ApiException(
        'لا تملك صلاحية تنفيذ هذا الإجراء.',
        statusCode: 403,
      );
      await container.read(workerWorkSessionProvider(7).notifier).start();
      expect(
        container.read(workerWorkSessionProvider(7)).actionError,
        'لا تملك صلاحية تنفيذ هذا الإجراء.',
      );
    },
  );

  test('refresh replaces local timer state with the server payload', () async {
    final repo = await _fake();
    repo.sessions = [_session()];
    final container = ProviderContainer(
      overrides: [workerRepositoryProvider.overrideWithValue(repo)],
    );
    addTearDown(container.dispose);
    final sub = container.listen(workerWorkSessionProvider(7), (_, _) {});
    addTearDown(sub.close);
    await container.read(workerWorkSessionProvider(7).notifier).load();
    expect(
      container
          .read(workerWorkSessionProvider(7))
          .currentSession
          ?.durationSeconds,
      isNull,
    );

    repo.sessions = [
      _session(
        status: 'ended',
        endedAt: '2026-09-27T10:05:00.000000Z',
        durationSeconds: 300,
      ),
    ];
    await container.read(workerWorkSessionProvider(7).notifier).load();
    final refreshed = container
        .read(workerWorkSessionProvider(7))
        .sessions
        .single;
    expect(refreshed.durationSeconds, 300);
    expect(refreshed.isEnded, isTrue);
  });

  testWidgets('engagement detail shows no-session, start, and worker shell', (
    tester,
  ) async {
    final repo = await _fake();
    await _pump(tester, repo);
    expect(find.text('جلسة العمل'), findsOneWidget);
    expect(find.text('لا توجد جلسات عمل بعد'), findsOneWidget);
    expect(find.byKey(const Key('worker-session-start')), findsOneWidget);
    expect(find.byType(WorkerScaffold), findsOneWidget);
    expect(find.text('المحادثات'), findsNothing);
    expect(find.text('العروض'), findsNothing);
    expect(find.text('الأعمال'), findsNothing);
    expect(find.text('الحساب'), findsNothing);
    expect(find.text('الجلسات'), findsNothing);
    expect(repo.sessionCalls, 1);
    expect(repo.lastEngagementId, 7);
  });

  testWidgets('active, break, resume, and end flows update the same screen', (
    tester,
  ) async {
    final repo = await _fake();
    await _pump(tester, repo);

    await _tapKey(tester, const Key('worker-session-start'));
    expect(repo.lastAction, 'start');
    expect(repo.lastEngagementId, 7);
    expect(find.text('الحالة: جارية'), findsOneWidget);
    expect(find.byKey(const Key('worker-session-timer')), findsOneWidget);
    expect(find.text('استراحة'), findsOneWidget);
    expect(find.text('إنهاء العمل'), findsOneWidget);
    expect(find.text('بدء العمل'), findsNothing);
    expect(repo.sessions.single.durationSeconds, isNull);

    await _tapKey(tester, const Key('worker-session-break'));
    expect(repo.lastAction, 'break');
    expect(find.text('القطعة الحالية: استراحة'), findsOneWidget);
    expect(find.text('استئناف العمل'), findsOneWidget);

    await _tapKey(tester, const Key('worker-session-resume'));
    expect(repo.lastAction, 'resume');
    expect(find.text('القطعة الحالية: عمل'), findsOneWidget);

    await _tapKey(tester, const Key('worker-session-end'));
    expect(find.text('إنهاء جلسة العمل الحالية؟'), findsOneWidget);
    await tester.tap(find.text('إلغاء'));
    await tester.pump();
    expect(repo.lastAction, 'resume');

    await _tapKey(tester, const Key('worker-session-end'));
    await tester.tap(find.byKey(const Key('worker-session-end-confirm')));
    await tester.pump();
    expect(repo.lastAction, 'end');
    expect(find.text('منتهية'), findsWidgets);
    expect(find.text('المدة: 00:45:00'), findsWidgets);
    expect(find.text('بدء العمل'), findsOneWidget);
  });

  testWidgets('ended history and idle segments render as read-only', (
    tester,
  ) async {
    final repo = await _fake();
    repo.sessions = [
      _session(
        status: 'ended',
        endedAt: '2026-09-27T10:05:00.000000Z',
        durationSeconds: 300,
        segments: [
          {
            'id': 21,
            'segment_type': 'active',
            'started_at': '2026-09-27T10:00:00.000000Z',
            'ended_at': '2026-09-27T10:03:00.000000Z',
          },
          {
            'id': 22,
            'segment_type': 'idle',
            'started_at': '2026-09-27T10:03:00.000000Z',
            'ended_at': '2026-09-27T10:04:00.000000Z',
          },
          {
            'id': 23,
            'segment_type': 'active',
            'started_at': '2026-09-27T10:04:00.000000Z',
            'ended_at': '2026-09-27T10:05:00.000000Z',
          },
        ],
      ),
    ];
    await _pump(tester, repo);
    expect(find.text('لا توجد جلسة عمل جارية'), findsOneWidget);
    expect(find.text('السجل'), findsOneWidget);
    expect(find.text('منتهية'), findsOneWidget);
    expect(find.text('المدة: 00:05:00'), findsOneWidget);
    expect(find.textContaining('خمول'), findsOneWidget);
    expect(find.text('خمول'), findsNothing);
    expect(find.byKey(const Key('worker-session-start')), findsOneWidget);
  });

  testWidgets('live timer ticks without writing a client duration', (
    tester,
  ) async {
    final repo = await _fake();
    final startedAt = DateTime.now().toUtc().subtract(
      const Duration(seconds: 4),
    );
    repo.sessions = [_session(startedAt: startedAt.toIso8601String())];
    await tester.pumpWidget(
      ProviderScope(
        overrides: [workerRepositoryProvider.overrideWithValue(repo)],
        child: const MaterialApp(
          home: WorkerEngagementDetailScreen(engagementId: 7),
        ),
      ),
    );
    for (var i = 0; i < 8; i++) {
      await tester.pump();
      if (find.byKey(const Key('worker-session-timer')).evaluate().isNotEmpty) {
        break;
      }
    }
    final first = tester
        .widget<Text>(find.byKey(const Key('worker-session-timer')))
        .data;
    await tester.pump(const Duration(seconds: 1));
    final second = tester
        .widget<Text>(find.byKey(const Key('worker-session-timer')))
        .data;
    expect(first, isNotEmpty);
    expect(second, isNot(first));
    expect(repo.sessions.single.durationSeconds, isNull);
  });

  testWidgets('duplicate start taps fire one request and 422 is shown', (
    tester,
  ) async {
    final repo = await _fake();
    final gate = Completer<WorkerWorkSession>();
    repo.actionGate = gate;
    await _pump(tester, repo);

    await tester.tap(find.byKey(const Key('worker-session-start')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('worker-session-start')));
    expect(repo.actionCalls, 1);

    repo.actionGate = null;
    repo.actionFailure = ApiException(
      'The worker engagement already has an active work session.',
      statusCode: 422,
    );
    gate.completeError(repo.actionFailure!);
    await tester.pump();
    expect(
      find.text('The worker engagement already has an active work session.'),
      findsOneWidget,
    );
  });
}

Future<_FakeWorkerRepository> _fake() async {
  SharedPreferences.setMockInitialValues({});
  final prefs = PrefsStore(await SharedPreferences.getInstance());
  return _FakeWorkerRepository(
    ApiClient(
      secureStore: _MemorySecureStore('token'),
      prefsStore: prefs,
      dio: Dio(BaseOptions(baseUrl: 'http://127.0.0.1:1/api/mobile/v1/')),
    ),
  );
}

Future<void> _pump(WidgetTester tester, _FakeWorkerRepository repo) async {
  tester.view.physicalSize = const Size(800, 1600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [workerRepositoryProvider.overrideWithValue(repo)],
      child: const MaterialApp(
        home: WorkerEngagementDetailScreen(engagementId: 7),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _tapKey(WidgetTester tester, Key key) async {
  final finder = find.byKey(key);
  await tester.ensureVisible(finder);
  await tester.pump();
  await tester.tap(finder);
  await tester.pump();
}
