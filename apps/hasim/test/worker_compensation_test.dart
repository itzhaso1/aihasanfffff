import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
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
import 'package:hasim/features/worker/presentation/worker_disputes_screen.dart';
import 'package:hasim/features/worker/presentation/worker_engagement_detail_screen.dart';
import 'package:hasim/features/worker/presentation/worker_payouts_screen.dart';
import 'package:hasim/features/worker/providers/worker_disputes_controller.dart';
import 'package:hasim/features/worker/providers/worker_earnings_controller.dart';
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

Map<String, dynamic> _periodJson({
  int id = 31,
  String status = 'approved',
  Map<String, int> totals = const {'SAR': 12000},
  List<Map<String, dynamic>>? lines,
}) {
  return {
    'id': id,
    'worker_engagement_id': 7,
    'status': status,
    'starts_at': '2026-08-01',
    'ends_at': '2026-08-31',
    'totals_minor': totals,
    'lines':
        lines ??
        [
          {
            'id': 41,
            'line_type': 'base',
            'source_type': 'engagement_terms',
            'source_id': null,
            'amount_minor': 12500,
            'currency': 'SAR',
            'description': 'Base pay',
          },
          {
            'id': 42,
            'line_type': 'deduction',
            'source_type': 'adjustment',
            'source_id': null,
            'amount_minor': -500,
            'currency': 'SAR',
            'description': 'Fee',
          },
        ],
  };
}

Map<String, dynamic> _payoutJson({
  required int id,
  required String status,
  int engagementId = 7,
  String? failureReason,
}) {
  return {
    'id': id,
    'worker_engagement_id': engagementId,
    'worker_earning_period_id': 31,
    'amount_minor': 12500,
    'currency': 'SAR',
    'status': status,
    'requested_at': '2026-09-01T10:00:00.000000Z',
    'processing_at': status == 'pending' ? null : '2026-09-01T11:00:00.000000Z',
    'paid_at': status == 'paid' ? '2026-09-01T12:00:00.000000Z' : null,
    'failed_at': status == 'failed' ? '2026-09-01T12:00:00.000000Z' : null,
    'cancelled_at': status == 'cancelled'
        ? '2026-09-01T12:00:00.000000Z'
        : null,
    'failure_reason': failureReason,
  };
}

Map<String, dynamic> _disputeJson({
  int id = 51,
  String status = 'open',
  int engagementId = 7,
  String category = 'schedule',
}) {
  return {
    'id': id,
    'worker_engagement_id': engagementId,
    'reporter_type': 'worker',
    'category': category,
    'description': 'Visible dispute',
    'status': status,
    'resolution_summary': status == 'resolved' ? 'Handled' : null,
    'opened_at': '2026-09-02T10:00:00.000000Z',
    'under_review_at': status == 'open' ? null : '2026-09-03T10:00:00.000000Z',
    'resolved_at': status == 'resolved' ? '2026-09-04T10:00:00.000000Z' : null,
    'closed_at': status == 'closed' ? '2026-09-05T10:00:00.000000Z' : null,
    'review_notes': 'Internal review note',
  };
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
  List<WorkerEarningPeriod> earnings = const [];
  List<WorkerPayout> payouts = const [];
  WorkerDisputePage disputes = const WorkerDisputePage(
    items: [],
    total: 0,
    perPage: 20,
    currentPage: 1,
    lastPage: 1,
  );
  WorkerDispute? disputeDetail;
  Object? earningsFailure;
  Object? payoutsFailure;
  Object? disputesFailure;
  Object? disputeActionFailure;
  int earningsCalls = 0;
  int payoutsCalls = 0;
  int disputesCalls = 0;
  int? lastEarningsId;
  String? lastPathHint;
  WorkerDisputeFilters? lastFilters;
  int? lastCreateEngagementId;
  String? lastCategory;
  String? lastDescription;
  int? lastPeriodId;
  String? lastReason;
  int createCalls = 0;
  int periodDisputeCalls = 0;

  @override
  Future<WorkerEngagement> engagement(int id) async => engagementValue;

  @override
  Future<List<WorkerProduct>> products(int engagementId) async => const [];

  @override
  Future<List<WorkerWorkSession>> getWorkSession(int engagementId) async =>
      const [];

  @override
  Future<List<WorkerEarningPeriod>> getWorkerEarnings(int engagementId) async {
    earningsCalls += 1;
    lastEarningsId = engagementId;
    lastPathHint = 'GET worker/engagements/$engagementId/earnings';
    final failure = earningsFailure;
    if (failure != null) throw failure;
    return earnings;
  }

  @override
  Future<WorkerEarningPeriod> disputeWorkerEarningPeriod(
    int engagementId,
    int periodId, {
    String? reason,
  }) async {
    periodDisputeCalls += 1;
    lastPeriodId = periodId;
    lastReason = reason;
    lastPathHint =
        'POST worker/engagements/$engagementId/earnings/$periodId/dispute';
    final failure = disputeActionFailure;
    if (failure != null) throw failure;
    return WorkerEarningPeriod.fromJson(_periodJson(status: 'disputed'));
  }

  @override
  Future<List<WorkerPayout>> getWorkerPayouts() async {
    payoutsCalls += 1;
    lastPathHint = 'GET worker/payouts';
    final failure = payoutsFailure;
    if (failure != null) throw failure;
    return payouts;
  }

  @override
  Future<WorkerDisputePage> getWorkerDisputes([
    WorkerDisputeFilters filters = const WorkerDisputeFilters(),
  ]) async {
    disputesCalls += 1;
    lastFilters = filters;
    lastPathHint = 'GET worker/disputes';
    final failure = disputesFailure;
    if (failure != null) throw failure;
    if (filters.page > 1) {
      return WorkerDisputePage(
        items: [
          WorkerDispute.fromJson(_disputeJson(id: 52, status: 'resolved')),
        ],
        total: 2,
        perPage: filters.perPage,
        currentPage: 2,
        lastPage: 2,
      );
    }
    return disputes;
  }

  @override
  Future<WorkerDispute> getWorkerDispute(int disputeId) async {
    lastPathHint = 'GET worker/disputes/$disputeId';
    final failure = disputesFailure;
    if (failure != null) throw failure;
    final detail = disputeDetail;
    if (detail == null || detail.id != disputeId) {
      throw ApiException('غير موجود.', statusCode: 404);
    }
    return detail;
  }

  @override
  Future<WorkerDispute> createWorkerDispute(
    int engagementId, {
    required String category,
    required String description,
  }) async {
    createCalls += 1;
    lastCreateEngagementId = engagementId;
    lastCategory = category;
    lastDescription = description;
    lastPathHint = 'POST worker/engagements/$engagementId/disputes';
    final failure = disputeActionFailure;
    if (failure != null) throw failure;
    return WorkerDispute.fromJson(
      _disputeJson(id: 99)
        ..['category'] = category
        ..['description'] = description,
    );
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('earning period, lines, and multi-currency totals parse', () {
    final period = WorkerEarningPeriod.fromJson(
      _periodJson(
        totals: const {'SAR': 12000, 'JOD': 500},
        lines: [
          {
            'id': 1,
            'line_type': 'base',
            'source_type': 'work_session',
            'source_id': 11,
            'amount_minor': 12000,
            'currency': 'SAR',
            'description': 'Session line',
          },
          {
            'id': 2,
            'line_type': 'bonus',
            'source_type': 'engagement_terms',
            'source_id': null,
            'amount_minor': 500,
            'currency': 'JOD',
            'description': null,
          },
        ],
      ),
    );

    expect(period.id, 31);
    expect(period.workerEngagementId, 7);
    expect(period.statusLabel, 'معتمد');
    expect(period.totalsMinor['SAR'], 12000);
    expect(period.totalsMinor['JOD'], 500);
    expect(period.lines, hasLength(2));
    expect(period.lines.first.sourceId, 11);
    expect(period.lines.first.lineTypeLabel, 'أساسي');
    expect(formatWorkerMoney(12000, 'SAR'), '120.00 SAR');
    expect(formatWorkerMoney(-500, 'SAR'), '-5.00 SAR');
  });

  test('payout parses every status without inventing a balance', () {
    for (final status in [
      'pending',
      'processing',
      'paid',
      'failed',
      'cancelled',
    ]) {
      final payout = WorkerPayout.fromJson(
        _payoutJson(
          id: 1,
          status: status,
          failureReason: status == 'failed' ? 'Provider declined.' : null,
        ),
      );
      expect(payout.status, status);
      expect(payout.statusLabel, isNot(status));
      expect(payout.amountMinor, 12500);
    }
  });

  test('dispute parses statuses and timestamps and ignores review notes', () {
    final dispute = WorkerDispute.fromJson(_disputeJson(status: 'resolved'));
    expect(dispute.statusLabel, 'محلول');
    expect(dispute.reporterLabel, 'العامل');
    expect(dispute.openedAt, isNotNull);
    expect(dispute.underReviewAt, isNotNull);
    expect(dispute.resolvedAt, isNotNull);
    expect(dispute.toString(), isNot(contains('Internal review note')));
  });

  test('repository uses the exact compensation endpoints and bodies', () async {
    SharedPreferences.setMockInitialValues({});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    final calls = <String>[];
    Object? disputeBody;
    Object? periodBody;
    Map<String, dynamic>? disputeQuery;
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1/api/mobile/v1/'));
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          calls.add('${options.method} ${options.path}');
          if (options.path == 'worker/disputes') {
            disputeQuery = Map<String, dynamic>.from(options.queryParameters);
          }
          if (options.path.endsWith('/dispute')) {
            periodBody = options.data;
          }
          if (options.path.endsWith('/disputes') && options.method == 'POST') {
            disputeBody = options.data;
          }
          final Object data;
          if (options.path.contains('/payouts')) {
            data = [_payoutJson(id: 8, status: 'pending')];
          } else if (options.path.endsWith('/earnings')) {
            data = [_periodJson(), _periodJson(id: 32, status: 'draft')];
          } else if (options.path.endsWith('/dispute')) {
            data = _periodJson(status: 'disputed');
          } else if (options.path.contains('/disputes/')) {
            data = _disputeJson();
          } else if (options.method == 'POST') {
            data = _disputeJson(id: 99);
          } else {
            data = [_disputeJson()];
          }
          handler.resolve(
            Response(
              requestOptions: options,
              data: {
                'success': true,
                'data': data,
                'meta': <String, dynamic>{
                  'total': 1,
                  'per_page': 20,
                  'current_page': 1,
                  'last_page': 1,
                },
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

    final earnings = await repo.getWorkerEarnings(7);
    await repo.getWorkerPayouts();
    final page = await repo.getWorkerDisputes(
      const WorkerDisputeFilters(
        status: 'open',
        from: '2026-09-01',
        to: '2026-09-20',
      ),
    );
    await repo.getWorkerDispute(51);
    await repo.createWorkerDispute(
      7,
      category: 'access',
      description: 'Missing access',
    );
    await repo.disputeWorkerEarningPeriod(7, 31, reason: 'Amount is wrong');

    expect(earnings, hasLength(1));
    expect(earnings.single.status, 'approved');
    expect(page.total, 1);
    expect(page.currentPage, 1);
    expect(disputeQuery, {
      'status': 'open',
      'from': '2026-09-01',
      'to': '2026-09-20',
      'per_page': 20,
      'page': 1,
    });
    expect(disputeBody, {
      'category': 'access',
      'description': 'Missing access',
    });
    expect(periodBody, {'reason': 'Amount is wrong'});
    expect(calls, [
      'GET worker/engagements/7/earnings',
      'GET worker/payouts',
      'GET worker/disputes',
      'GET worker/disputes/51',
      'POST worker/engagements/7/disputes',
      'POST worker/engagements/7/earnings/31/dispute',
    ]);
  });

  test(
    'earnings controller surfaces 404 without exposing a foreign period',
    () async {
      final repo = await _fake();
      repo.earningsFailure = ApiException('غير موجود.', statusCode: 404);
      final container = ProviderContainer(
        overrides: [workerRepositoryProvider.overrideWithValue(repo)],
      );
      addTearDown(container.dispose);
      final sub = container.listen(workerEarningsProvider(7), (_, _) {});
      addTearDown(sub.close);
      await container.read(workerEarningsProvider(7).notifier).load();
      expect(container.read(workerEarningsProvider(7)).error, 'غير موجود.');
      expect(container.read(workerEarningsProvider(7)).periods, isEmpty);
    },
  );

  testWidgets('engagement earnings hide drafts, show money and dispute rules', (
    tester,
  ) async {
    final repo = await _fake();
    repo.earnings = [
      WorkerEarningPeriod.fromJson(
        _periodJson(totals: const {'SAR': 12000, 'JOD': 500}),
      ),
      WorkerEarningPeriod.fromJson(_periodJson(id: 32, status: 'paid')),
      WorkerEarningPeriod.fromJson(_periodJson(id: 33, status: 'draft')),
    ];
    await _pumpDetail(tester, repo);
    await tester.scrollUntilVisible(
      find.byKey(const Key('worker-earnings-section')),
      200,
    );

    expect(find.text('الأرباح'), findsOneWidget);
    expect(find.text('فتح نزاع ارتباط'), findsOneWidget);
    expect(find.text('120.00 SAR'), findsWidgets);
    expect(find.text('5.00 JOD'), findsOneWidget);
    expect(find.text('الحالة: معتمد'), findsOneWidget);
    expect(find.text('الحالة: مدفوع'), findsOneWidget);
    expect(find.text('الحالة: مسودة'), findsNothing);
    expect(find.byKey(const Key('worker-earning-period-33')), findsNothing);

    await tester.tap(find.byKey(const Key('worker-earning-period-31')));
    await tester.pumpAndSettle();
    expect(find.text('أساسي — 125.00 SAR'), findsOneWidget);
    expect(find.text('خصم — -5.00 SAR'), findsOneWidget);
    expect(find.byKey(const Key('worker-earning-dispute-31')), findsOneWidget);

    await tester.tap(find.byKey(const Key('worker-earning-period-32')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('worker-earning-dispute-32')), findsNothing);

    await tester.tap(find.byKey(const Key('worker-earning-period-31')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('worker-earning-dispute-31')));
    await tester.pumpAndSettle();
    expect(find.text('اعتراض على فترة الأرباح'), findsOneWidget);
    expect(
      find.byKey(const Key('worker-earning-dispute-help')),
      findsOneWidget,
    );
    await tester.enterText(
      find.byKey(const Key('worker-earning-dispute-reason')),
      'The approved amount needs review.',
    );
    await tester.tap(find.byKey(const Key('worker-earning-dispute-confirm')));
    await tester.pumpAndSettle();
    expect(repo.periodDisputeCalls, 1);
    expect(repo.lastReason, 'The approved amount needs review.');
    expect(find.text('الحالة: محل اعتراض'), findsOneWidget);
    expect(find.byKey(const Key('worker-earning-dispute-31')), findsNothing);
  });

  testWidgets('payout history is read-only and shows all statuses', (
    tester,
  ) async {
    final repo = await _fake();
    repo.payouts = [
      WorkerPayout.fromJson(_payoutJson(id: 1, status: 'pending')),
      WorkerPayout.fromJson(_payoutJson(id: 2, status: 'processing')),
      WorkerPayout.fromJson(_payoutJson(id: 3, status: 'paid')),
      WorkerPayout.fromJson(
        _payoutJson(
          id: 4,
          status: 'failed',
          failureReason: 'Provider declined.',
        ),
      ),
      WorkerPayout.fromJson(_payoutJson(id: 5, status: 'cancelled')),
    ];
    await _pumpPage(tester, repo, const WorkerPayoutsScreen());

    expect(find.text('سجل الدفعات'), findsOneWidget);
    expect(find.text('الحالة: قيد الانتظار'), findsOneWidget);
    expect(find.text('الحالة: قيد المعالجة'), findsOneWidget);
    expect(find.text('الحالة: مدفوع'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('الحالة: فشل'), 200);
    expect(find.text('الحالة: فشل'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('الحالة: ملغى'), 200);
    expect(find.text('الحالة: ملغى'), findsOneWidget);
    expect(find.text('Provider declined.'), findsOneWidget);
    expect(find.text('طلب دفعة'), findsNothing);
    expect(find.text('سحب الأموال'), findsNothing);
    expect(find.text('IBAN'), findsNothing);
    expect(find.textContaining('رصيد'), findsNothing);
  });

  testWidgets(
    'engagement compensation sections hide records from another engagement',
    (tester) async {
      final repo = await _fake();
      repo.payouts = [
        WorkerPayout.fromJson(_payoutJson(id: 1, status: 'paid')),
        WorkerPayout.fromJson(
          _payoutJson(id: 2, status: 'paid', engagementId: 99),
        ),
      ];
      repo.disputes = WorkerDisputePage(
        items: [
          WorkerDispute.fromJson(
            _disputeJson(id: 51, category: 'current-engagement'),
          ),
          WorkerDispute.fromJson(
            _disputeJson(
              id: 52,
              engagementId: 99,
              category: 'foreign-engagement',
            ),
          ),
        ],
        total: 2,
        perPage: 20,
        currentPage: 1,
        lastPage: 1,
      );

      await _pumpDetail(tester, repo);

      await tester.scrollUntilVisible(
        find.byKey(const Key('worker-payouts-section')),
        300,
      );
      expect(find.byKey(const Key('worker-payout-1')), findsOneWidget);
      expect(find.byKey(const Key('worker-payout-2')), findsNothing);

      await tester.scrollUntilVisible(
        find.byKey(const Key('worker-engagement-disputes-section')),
        300,
      );
      expect(find.text('current-engagement'), findsOneWidget);
      expect(find.text('foreign-engagement'), findsNothing);
    },
  );

  testWidgets('disputes list paginates, filters, and shows detail', (
    tester,
  ) async {
    final repo = await _fake();
    repo.disputes = WorkerDisputePage(
      items: [WorkerDispute.fromJson(_disputeJson())],
      total: 2,
      perPage: 1,
      currentPage: 1,
      lastPage: 2,
    );
    repo.disputeDetail = WorkerDispute.fromJson(_disputeJson());
    await _pumpPage(tester, repo, const WorkerDisputesScreen());
    expect(find.text('schedule'), findsOneWidget);
    await tester.ensureVisible(
      find.byKey(const Key('worker-disputes-load-more')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('worker-disputes-load-more')));
    await tester.pumpAndSettle();
    expect(repo.lastFilters?.page, 2);
    expect(find.byKey(const Key('worker-dispute-52')), findsOneWidget);

    await tester.ensureVisible(
      find.byKey(const Key('worker-disputes-apply-filters')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('worker-disputes-apply-filters')));
    await tester.pumpAndSettle();
    expect(repo.lastFilters?.page, 1);

    await _pumpPage(
      tester,
      repo,
      const WorkerDisputeDetailScreen(disputeId: 51),
    );
    expect(find.text('Visible dispute'), findsOneWidget);
    expect(find.text('الحالة: مفتوح'), findsOneWidget);
    expect(find.text('Internal review note'), findsNothing);
  });

  testWidgets('creating a WorkerDispute validates and stays distinct', (
    tester,
  ) async {
    final repo = await _fake();
    await _pumpRoutedDetail(tester, repo);
    await tester.scrollUntilVisible(
      find.byKey(const Key('worker-dispute-create')),
      300,
    );
    await tester.tap(find.byKey(const Key('worker-dispute-create')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('worker-dispute-create-help')), findsOneWidget);

    await tester.tap(find.byKey(const Key('worker-dispute-submit')));
    await tester.pump();
    expect(find.byKey(const Key('worker-dispute-validation')), findsOneWidget);
    expect(repo.createCalls, 0);

    await tester.enterText(
      find.byKey(const Key('worker-dispute-category')),
      'access',
    );
    await tester.tap(find.byKey(const Key('worker-dispute-submit')));
    await tester.pump();
    expect(find.textContaining('الوصف مطلوب'), findsOneWidget);
    expect(repo.createCalls, 0);

    await tester.enterText(
      find.byKey(const Key('worker-dispute-description')),
      'The granted access does not match the offer.',
    );
    await tester.tap(find.byKey(const Key('worker-dispute-submit')));
    await tester.pumpAndSettle();
    expect(repo.createCalls, 1);
    expect(repo.periodDisputeCalls, 0);
    expect(repo.lastCategory, 'access');
    expect(
      repo.lastDescription,
      'The granted access does not match the offer.',
    );
    expect(find.text('dispute-open'), findsOneWidget);
  });

  test(
    'dispute list controller keeps server pages instead of faking them',
    () async {
      final repo = await _fake();
      repo.disputes = WorkerDisputePage(
        items: [WorkerDispute.fromJson(_disputeJson())],
        total: 2,
        perPage: 1,
        currentPage: 1,
        lastPage: 2,
      );
      final container = ProviderContainer(
        overrides: [workerRepositoryProvider.overrideWithValue(repo)],
      );
      addTearDown(container.dispose);
      final sub = container.listen(workerDisputesProvider, (_, _) {});
      addTearDown(sub.close);
      await container
          .read(workerDisputesProvider.notifier)
          .load(const WorkerDisputeFilters(status: 'open', perPage: 1));
      expect(repo.lastFilters?.status, 'open');
      expect(container.read(workerDisputesProvider).hasMore, isTrue);
      await container.read(workerDisputesProvider.notifier).loadMore();
      expect(container.read(workerDisputesProvider).items, hasLength(2));
      expect(container.read(workerDisputesProvider).currentPage, 2);
    },
  );
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

Future<void> _pumpPage(
  WidgetTester tester,
  _FakeWorkerRepository repo,
  Widget page,
) async {
  tester.view.physicalSize = const Size(800, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [workerRepositoryProvider.overrideWithValue(repo)],
      child: MaterialApp(home: page),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpDetail(
  WidgetTester tester,
  _FakeWorkerRepository repo,
) async {
  tester.view.physicalSize = const Size(800, 2200);
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

Future<void> _pumpRoutedDetail(
  WidgetTester tester,
  _FakeWorkerRepository repo,
) async {
  tester.view.physicalSize = const Size(800, 2200);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  final router = GoRouter(
    initialLocation: '/worker/engagements/7',
    routes: [
      GoRoute(
        path: '/worker/engagements/:id',
        builder: (context, state) =>
            const WorkerEngagementDetailScreen(engagementId: 7),
      ),
      GoRoute(
        path: '/worker/disputes/:id',
        builder: (context, state) => const Text('dispute-open'),
      ),
    ],
  );
  await tester.pumpWidget(
    ProviderScope(
      overrides: [workerRepositoryProvider.overrideWithValue(repo)],
      child: MaterialApp.router(routerConfig: router),
    ),
  );
  await tester.pumpAndSettle();
}
