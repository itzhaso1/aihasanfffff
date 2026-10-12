import 'dart:async';

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
import 'package:hasim/features/worker/presentation/worker_capability_presentation.dart';
import 'package:hasim/features/worker/presentation/worker_engagement_detail_screen.dart';
import 'package:hasim/features/worker/presentation/worker_engagement_list_screen.dart';
import 'package:hasim/features/worker/providers/worker_engagement_controller.dart';
import 'package:hasim/router/worker_nav.dart';
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

WorkerEngagement _engagement({
  int id = 7,
  String status = 'offered',
  String? jobTitle = 'دعم العملاء',
  String? jobDescription,
  String? workspaceName = 'شركة النور',
  String? startsAt = '2026-01-01',
  String? endsAt = '2026-06-30',
  int? durationValue,
  String? durationUnit,
  int? offeredContractAmountMinor,
  String? offeredContractCurrency,
  int? agreedContractAmountMinor,
  String? agreedContractCurrency,
  String capabilityKey = 'inbox.messages.read',
  String capabilityName = 'عرض الرسائل',
  Map<String, dynamic>? guide,
}) {
  return WorkerEngagement.fromJson({
    'id': id,
    'status': status,
    'job_title': jobTitle,
    'job_description': jobDescription,
    'starts_at': startsAt,
    'ends_at': endsAt,
    'duration_value': durationValue,
    'duration_unit': durationUnit,
    'offered_contract_amount_minor': offeredContractAmountMinor,
    'offered_contract_currency': offeredContractCurrency,
    'agreed_contract_amount_minor': agreedContractAmountMinor,
    'agreed_contract_currency': agreedContractCurrency,
    'workspace': workspaceName == null
        ? null
        : {'id': 4, 'name': workspaceName},
    'capabilities': [
      {'capability_key': capabilityKey, 'display_name': capabilityName},
    ],
    'guide': guide,
  });
}

WorkerEngagement _engagementWithCapabilities(List<String> keys) {
  return WorkerEngagement.fromJson({
    'id': 7,
    'status': 'active',
    'job_title': 'إدارة العمليات',
    'starts_at': '2026-01-01',
    'ends_at': '2026-06-30',
    'workspace': {'id': 4, 'name': 'شركة النور'},
    'capabilities': [
      for (final key in keys)
        {'capability_key': key, 'display_name': 'Raw English capability name'},
    ],
  });
}

class _FakeWorkerRepository extends WorkerRepository {
  _FakeWorkerRepository(super.api);

  List<WorkerEngagement> items = [_engagement()];
  Object? listFailure;
  Object? actionFailure;
  int listCalls = 0;
  int detailCalls = 0;
  int? lastActionId;
  String? lastAction;
  int actionCalls = 0;
  List<WorkerProduct> productItems = const [];
  Object? productFailure;
  Completer<List<WorkerEngagement>>? listGate;
  Completer<WorkerEngagement>? actionGate;
  WorkerEngagement? refreshed;
  int sessionCalls = 0;

  @override
  Future<List<WorkerWorkSession>> getWorkSession(int engagementId) async {
    sessionCalls += 1;
    return const [];
  }

  @override
  Future<List<WorkerProduct>> products(int engagementId) async {
    final failure = productFailure;
    if (failure != null) throw failure;
    return productItems;
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
  Future<List<WorkerEngagement>> engagements() async {
    listCalls += 1;
    final gate = listGate;
    if (gate != null) return gate.future;
    final failure = listFailure;
    if (failure != null) throw failure;
    return items;
  }

  @override
  Future<WorkerEngagement> engagement(int id) async {
    detailCalls += 1;
    if (id != 7 && !items.any((item) => item.id == id)) {
      throw ApiException('غير موجود.', statusCode: 404);
    }
    if (detailCalls > 1 && refreshed != null) return refreshed!;
    return items.firstWhere(
      (item) => item.id == id,
      orElse: () => _engagement(id: id),
    );
  }

  @override
  Future<WorkerEngagement> acceptOffer(int id) {
    return _action('accept', id, 'accepted');
  }

  @override
  Future<WorkerEngagement> rejectOffer(int id) {
    return _action('reject', id, 'rejected');
  }

  Future<WorkerEngagement> _action(String action, int id, String status) async {
    actionCalls += 1;
    lastAction = action;
    lastActionId = id;
    final gate = actionGate;
    if (gate != null) return gate.future;
    final failure = actionFailure;
    if (failure != null) throw failure;
    final updated = _engagement(id: id, status: status);
    items = [
      for (final item in items)
        if (item.id == id) updated else item,
    ];
    return updated;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('engagement model keeps dates nullable and uses status labels', () {
    final offer = _engagement();
    final priced = _engagement(
      durationValue: 1,
      durationUnit: 'week',
      offeredContractAmountMinor: 60000,
      offeredContractCurrency: 'SAR',
    );
    final openEnded = WorkerEngagement.fromJson({
      'id': 3,
      'status': 'active',
      'job_title': null,
      'starts_at': null,
      'ends_at': '',
      'workspace': {'id': 4, 'name': 'شركة النور'},
      'capabilities': const [],
    });

    expect(offer.isOffer, isTrue);
    expect(offer.statusLabel, 'عرض مقدّم');
    expect(offer.startsAt, '2026-01-01');
    expect(offer.endsAt, '2026-06-30');
    expect(priced.contractDurationLabel, 'أسبوع واحد');
    expect(priced.contractPriceLabel, '600.00 SAR');
    expect(openEnded.startsAt, isNull);
    expect(openEnded.endsAt, isNull);
    expect(openEnded.headline, 'شركة النور');
    expect(openEnded.statusLabel, 'نشط');
    expect(openEnded.contractDurationLabel, isNull);
    expect(openEnded.contractPriceLabel, isNull);
    expect(
      WorkerEngagement.fromJson({'id': 1, 'status': 'custom'}).statusLabel,
      'حالة الارتباط',
    );
  });

  test('engagement list load is reused until an explicit retry', () async {
    final repo = await _fake();
    final container = ProviderContainer(
      overrides: [workerRepositoryProvider.overrideWithValue(repo)],
    );
    addTearDown(container.dispose);

    await container.read(workerEngagementListProvider.notifier).load();
    await container.read(workerEngagementListProvider.notifier).load();
    expect(repo.listCalls, 1);

    await container
        .read(workerEngagementListProvider.notifier)
        .load(force: true);
    expect(repo.listCalls, 2);
  });

  test(
    'current Worker capability catalog has Arabic presentation mappings',
    () {
      const expected = {
        'inbox.messages.read': 'قراءة الرسائل الواردة',
        'inbox.messages.send': 'إرسال رسائل الوارد',
        'customers.view': 'عرض العملاء',
        'customers.phone.view': 'عرض أرقام هواتف العملاء',
        'customers.email.view': 'عرض عناوين البريد الإلكتروني للعملاء',
        'catalog.products.view': 'عرض المنتجات',
        'commerce.orders.view': 'عرض الطلبات',
        'commerce.orders.create': 'إنشاء الطلبات',
        'commerce.payment_links.create': 'إنشاء روابط الدفع',
        'reports.view': 'عرض التقارير',
        'email.view': 'عرض البريد',
        'stories.view': 'عرض التحديثات',
      };

      for (final entry in expected.entries) {
        final presentation = WorkerCapabilityPresentation.fromCapability(
          WorkerCapability(
            key: entry.key,
            displayName: 'Raw English capability name',
          ),
        );
        expect(presentation.title, entry.value);
        expect(presentation.category, isNotEmpty);
        expect(presentation.description, isNotEmpty);
        expect(presentation.title, isNot(contains('Raw')));
      }
    },
  );

  testWidgets('offer list renders an offer and hides the raw status', (
    tester,
  ) async {
    final repo = await _fake();
    await _pump(tester, repo, const WorkerOffersScreen());

    expect(find.text('دعم العملاء'), findsOneWidget);
    expect(find.text('شركة النور'), findsOneWidget);
    expect(find.text('عرض مقدّم'), findsOneWidget);
    expect(find.text('تاريخ البداية'), findsOneWidget);
    expect(find.text('01-01-2026'), findsOneWidget);
    expect(find.text('قراءة الرسائل الواردة'), findsOneWidget);
    expect(find.text('offered'), findsNothing);
    expect(find.text('inbox.messages.read'), findsNothing);
  });

  testWidgets('offers screen renders its header and opens the filter sheet', (
    tester,
  ) async {
    final repo = await _fake();
    await _pump(tester, repo, const WorkerOffersScreen());

    expect(find.text('العروض'), findsWidgets);
    expect(find.text('عروض العمل المقدمة إليك'), findsOneWidget);
    expect(find.byKey(const Key('worker-offers-search')), findsOneWidget);
    expect(find.byKey(const Key('worker-offers-filters')), findsOneWidget);

    await tester.tap(find.byKey(const Key('worker-offers-filters')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const Key('worker-offers-filter-sheet-title')),
      findsOneWidget,
    );
    expect(find.textContaining('لا توجد فلاتر خادمية مدعومة'), findsOneWidget);

    await tester.tap(find.byKey(const Key('worker-offers-filter-close')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const Key('worker-offers-filter-sheet-title')),
      findsNothing,
    );
  });

  testWidgets('offers and engagements have empty states', (tester) async {
    final repo = await _fake(
      items: [_engagement(status: 'active', jobTitle: 'دعم العملاء')],
    );
    await _pump(tester, repo, const WorkerOffersScreen());
    expect(find.text('لا توجد عروض متاحة حاليًا'), findsOneWidget);

    await _pump(tester, repo, const WorkerEngagementsScreen());
    expect(find.text('دعم العملاء'), findsOneWidget);
    expect(find.text('نشط'), findsOneWidget);
  });

  testWidgets('engagement list shows several statuses and capability names', (
    tester,
  ) async {
    final repo = await _fake(
      items: [
        _engagement(id: 7, status: 'active', jobTitle: 'دعم العملاء'),
        _engagement(
          id: 8,
          status: 'paused',
          jobTitle: 'مبيعات',
          capabilityName: 'إرسال الرسائل',
          capabilityKey: 'inbox.messages.send',
        ),
      ],
    );
    await _pump(tester, repo, const WorkerEngagementsScreen());

    expect(find.text('الأعمال'), findsWidgets);
    expect(find.text('سجل الدفعات'), findsOneWidget);
    expect(find.text('النزاعات'), findsOneWidget);
    expect(find.text('قراءة الرسائل الواردة'), findsOneWidget);
    expect(
      find.byKey(const Key('worker-business-action-7-inbox.messages.read')),
      findsOneWidget,
    );
    expect(find.text('دعم العملاء'), findsOneWidget);
    await tester.drag(find.byType(ListView).first, const Offset(0, -1200));
    await tester.pumpAndSettle();
    expect(find.text('مبيعات'), findsOneWidget);
    expect(find.text('نشط'), findsOneWidget);
    expect(find.text('متوقف مؤقتاً'), findsOneWidget);
    expect(find.text('إرسال رسائل الوارد'), findsOneWidget);
    expect(find.text('inbox.messages.send'), findsNothing);
    expect(find.text('منح صلاحية'), findsNothing);
    expect(find.text('سحب الصلاحية'), findsNothing);
  });

  testWidgets('engagement list is empty when the worker has no engagements', (
    tester,
  ) async {
    final repo = await _fake(items: const []);
    await _pump(tester, repo, const WorkerEngagementsScreen());
    expect(find.text('لا توجد ارتباطات حالياً'), findsOneWidget);
  });

  testWidgets('engagement list shows loading and recovers from an error', (
    tester,
  ) async {
    final repo = await _fake();
    final gate = Completer<List<WorkerEngagement>>();
    repo.listGate = gate;
    await _pump(tester, repo, const WorkerOffersScreen(), settle: false);
    await tester.pump();
    expect(find.byKey(const Key('worker-engagements-loading')), findsOneWidget);
    expect(find.byKey(const Key('worker-offer-skeleton-1')), findsOneWidget);

    gate.complete([_engagement()]);
    await tester.pumpAndSettle();
    expect(find.text('دعم العملاء'), findsOneWidget);
  });

  testWidgets('business list shows responsive skeleton cards while loading', (
    tester,
  ) async {
    final repo = await _fake();
    final gate = Completer<List<WorkerEngagement>>();
    repo.listGate = gate;
    await _pump(tester, repo, const WorkerEngagementsScreen(), settle: false);
    await tester.pump();

    expect(find.byKey(const Key('worker-engagements-loading')), findsOneWidget);
    expect(find.byKey(const Key('worker-business-skeleton-1')), findsOneWidget);

    gate.complete([_engagement(status: 'active')]);
    await tester.pumpAndSettle();
    expect(find.text('عرض التفاصيل'), findsOneWidget);
  });

  testWidgets('long Arabic business data wraps without overflowing', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(320, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = await _fake(
      items: [
        _engagement(
          status: 'active',
          jobTitle:
              'إدارة عمليات التجارة الإلكترونية وخدمة العملاء عبر القنوات المتعددة',
          workspaceName: 'شركة الحلول الرقمية المتقدمة للتجارة والخدمات',
          capabilityName:
              'إدارة الرسائل والتواصل مع العملاء باللغة العربية والإنجليزية',
        ),
      ],
    );
    await _pump(tester, repo, const WorkerEngagementsScreen());

    expect(tester.takeException(), isNull);
    expect(find.text('عرض التفاصيل'), findsOneWidget);
  });

  testWidgets('long Arabic offer content wraps without overflowing', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(320, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = await _fake(
      items: [
        _engagement(
          jobTitle:
              'تطوير واجهات أمامية متقدمة لمنصة التجارة الإلكترونية وإدارة العمليات',
          workspaceName: 'شركة تطوير المنتجات الرقمية والخدمات التقنية',
          capabilityName: 'إدارة الرسائل والتواصل مع العملاء باللغة العربية',
        ),
      ],
    );
    await _pump(tester, repo, const WorkerOffersScreen());

    expect(tester.takeException(), isNull);
    expect(find.text('عرض التفاصيل'), findsOneWidget);
  });

  testWidgets('engagement list shows an error and retries', (tester) async {
    final repo = await _fake();
    repo.listFailure = ApiException('تعذر تحميل الارتباطات.', statusCode: 500);
    await _pump(tester, repo, const WorkerEngagementsScreen());
    expect(find.text('تعذر تحميل الارتباطات.'), findsOneWidget);

    repo.listFailure = null;
    repo.items = [_engagement(status: 'accepted', jobTitle: 'دعم العملاء')];
    await tester.tap(find.byKey(const Key('worker-engagements-retry')));
    await tester.pumpAndSettle();
    expect(find.text('مقبول'), findsOneWidget);
  });

  testWidgets('offer detail shows capabilities and accept or reject actions', (
    tester,
  ) async {
    final repo = await _fake();
    await _pump(
      tester,
      repo,
      const WorkerEngagementDetailScreen(engagementId: 7),
    );

    expect(find.text('تفاصيل العرض'), findsOneWidget);
    expect(find.text('الحالة: عرض مقدّم'), findsOneWidget);
    expect(find.text('قراءة الرسائل الواردة'), findsOneWidget);
    expect(find.text('المحادثات'), findsOneWidget);
    expect(find.text('الصلاحيات الممنوحة'), findsOneWidget);
    expect(find.text('inbox.messages.read'), findsNothing);
    expect(find.text('منح صلاحية'), findsNothing);
    expect(find.byKey(const Key('worker-offer-accept')), findsOneWidget);
    expect(find.byKey(const Key('worker-offer-reject')), findsOneWidget);
    expect(find.text('جلسة العمل'), findsNothing);
  });

  testWidgets('offer detail shows read-only contract duration and price', (
    tester,
  ) async {
    final repo = await _fake();
    repo.items = [
      _engagement(
        durationValue: 1,
        durationUnit: 'week',
        offeredContractAmountMinor: 60000,
        offeredContractCurrency: 'SAR',
      ),
    ];
    await _pump(
      tester,
      repo,
      const WorkerEngagementDetailScreen(engagementId: 7),
    );

    expect(find.text('مدة التعاقد: أسبوع واحد'), findsOneWidget);
    expect(find.text('سعر التعاقد: 600.00 SAR'), findsOneWidget);
    expect(
      find.byKey(const Key('worker-offer-contract-duration')),
      findsOneWidget,
    );
    expect(
      find.byKey(const Key('worker-offer-contract-price')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('worker-offer-accept')), findsOneWidget);
  });

  testWidgets('offer detail renders the private work guide before acceptance', (
    tester,
  ) async {
    final repo = await _fake();
    repo.items = [
      _engagement(
        guide: {
          'company_overview': 'شركة متخصصة في بيع الإلكترونيات.',
          'products_context': 'هواتف وإكسسوارات.',
          'responsibilities': ['الرد على العملاء', 'شرح المنتجات'],
          'workflow_steps': ['استقبال العميل', 'ترشيح المنتج'],
          'important_rules': ['لا يتم البيع تحت الحد الأدنى.'],
          'customer_guidance': ['حافظ على أسلوب مهني.'],
          'escalation_rules': ['أرسل الشكوى للمسؤول.'],
          'work_schedule_notes': 'من التاسعة حتى الخامسة.',
          'important_notes': 'تحقق من التوفر.',
          'faq_items': [
            {'question': 'متى أطلب موافقة؟', 'answer': 'عند طلب خصم.'},
          ],
        },
      ),
    ];
    await _pump(
      tester,
      repo,
      const WorkerEngagementDetailScreen(engagementId: 7),
    );

    expect(find.text('دليل العمل'), findsOneWidget);
    for (final label in [
      'نبذة عن الشركة',
      'ماذا نبيع؟',
      'دوري في هذا العمل',
      'المهام والمسؤوليات',
      'طريقة العمل',
      'قواعد وتعليمات مهمة',
      'التعامل مع العملاء',
      'متى أتواصل مع المسؤول؟',
      'أوقات ونظام العمل',
      'ملاحظات مهمة',
      'أسئلة شائعة / معلومات سريعة',
    ]) {
      expect(find.text(label), findsOneWidget);
    }
    expect(find.text('شركة متخصصة في بيع الإلكترونيات.'), findsOneWidget);
    expect(find.text('استقبال العميل'), findsOneWidget);
    expect(find.text('متى أطلب موافقة؟'), findsOneWidget);
    expect(find.text('تعديل دليل العمل'), findsNothing);
  });

  testWidgets(
    'empty work guide shows the Arabic empty state and hides sections',
    (tester) async {
      final repo = await _fake();
      repo.items = [_engagement()];
      await _pump(
        tester,
        repo,
        const WorkerEngagementDetailScreen(engagementId: 7),
      );

      expect(
        find.text('لا يوجد دليل عمل مضاف لهذا التعاقد حاليًا'),
        findsOneWidget,
      );
      expect(find.text('نبذة عن الشركة'), findsNothing);
      expect(find.text('أسئلة شائعة / معلومات سريعة'), findsNothing);
    },
  );

  testWidgets('long Arabic guide content wraps without overflowing', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(320, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final repo = await _fake();
    repo.items = [
      _engagement(
        status: 'accepted',
        guide: {
          'company_overview':
              'هذه نبذة عربية طويلة عن الشركة وطبيعة العمل والمنتجات والعملاء والقواعد التشغيلية التي يحتاج العامل إلى فهمها قبل بدء العمل.',
          'responsibilities': [
            'الرد على العملاء وشرح المنتجات ومتابعة الطلبات والتأكد من وضوح المعلومات باللغة العربية.',
          ],
          'workflow_steps': [
            'استقبال العميل وفهم احتياجه ثم ترشيح المنتج المناسب وشرح السعر والمتابعة بأسلوب مهني واضح.',
          ],
        },
      ),
    ];
    await _pump(
      tester,
      repo,
      const WorkerEngagementDetailScreen(engagementId: 7),
    );

    expect(tester.takeException(), isNull);
    expect(find.textContaining('شركة'), findsWidgets);
    expect(find.byKey(const Key('worker-offer-accept')), findsNothing);
  });

  testWidgets(
    'engagement detail groups every current capability in Arabic without raw keys',
    (tester) async {
      tester.view.physicalSize = const Size(360, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      const keys = [
        'inbox.messages.read',
        'inbox.messages.send',
        'customers.view',
        'customers.phone.view',
        'customers.email.view',
        'catalog.products.view',
        'commerce.orders.view',
        'commerce.orders.create',
        'commerce.payment_links.create',
        'reports.view',
        'email.view',
        'stories.view',
      ];
      final repo = await _fake();
      repo.items = [_engagementWithCapabilities(keys)];
      await _pump(
        tester,
        repo,
        const WorkerEngagementDetailScreen(engagementId: 7),
      );

      for (final label in [
        'قراءة الرسائل الواردة',
        'إرسال رسائل الوارد',
        'عرض العملاء',
        'عرض أرقام هواتف العملاء',
        'عرض عناوين البريد الإلكتروني للعملاء',
        'عرض المنتجات',
        'عرض الطلبات',
        'إنشاء الطلبات',
        'إنشاء روابط الدفع',
        'عرض التقارير',
        'عرض البريد',
        'عرض التحديثات',
      ]) {
        expect(find.text(label), findsOneWidget);
      }
      expect(find.text('Raw English capability name'), findsNothing);
      expect(find.text('inbox.messages.read'), findsNothing);
      expect(find.text('المحادثات'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('engagement detail shows an Arabic permissions empty state', (
    tester,
  ) async {
    final repo = await _fake();
    repo.items = [_engagementWithCapabilities(const [])];
    await _pump(
      tester,
      repo,
      const WorkerEngagementDetailScreen(engagementId: 7),
    );

    expect(find.text('لا توجد صلاحيات ممنوحة حاليًا'), findsOneWidget);
    expect(
      find.text('لم يتم منح أدوات تشغيلية لهذا الارتباط بعد.'),
      findsOneWidget,
    );
  });

  testWidgets(
    'engagement detail shows work brief and authorized product prices',
    (tester) async {
      final repo = await _fake();
      repo.items = [
        _engagement(
          status: 'active',
          jobDescription: 'اشرح المنتجات للعملاء وساعدهم على اختيار الأنسب.',
        ),
      ];
      repo.productItems = const [
        WorkerProduct(
          id: 10,
          name: 'منتج تجريبي',
          description: 'وصف المنتج',
          price: 25,
          minimumSellingPrice: 22,
          maximumSellingPrice: 30,
          currency: 'SAR',
        ),
      ];
      await _pump(
        tester,
        repo,
        const WorkerEngagementDetailScreen(engagementId: 7),
      );

      expect(find.text('تفاصيل العمل'), findsOneWidget);
      expect(
        find.text('اشرح المنتجات للعملاء وساعدهم على اختيار الأنسب.'),
        findsOneWidget,
      );
      expect(find.text('المنتجات'), findsOneWidget);
      expect(find.text('منتج تجريبي'), findsOneWidget);
      expect(find.text('المنتجات المحددة لهذا العمل'), findsOneWidget);
      expect(find.text('السعر الأساسي'), findsOneWidget);
      expect(find.text('الحد الأدنى لهذا العمل'), findsOneWidget);
      expect(find.text('الحد الأعلى لهذا العمل'), findsOneWidget);
      expect(find.text('25.00 SAR'), findsOneWidget);
      expect(find.text('22.00 SAR'), findsOneWidget);
      expect(find.text('30.00 SAR'), findsOneWidget);
    },
  );

  testWidgets('engagement detail hides products behind the capability error', (
    tester,
  ) async {
    final repo = await _fake();
    repo.productFailure = ApiException(
      'لا تملك صلاحية عرض المنتجات',
      statusCode: 403,
    );
    repo.items = [_engagement(status: 'active')];
    await _pump(
      tester,
      repo,
      const WorkerEngagementDetailScreen(engagementId: 7),
    );

    expect(find.text('لا تملك صلاحية عرض المنتجات'), findsOneWidget);
    expect(find.text('منتج تجريبي'), findsNothing);
  });

  testWidgets('engagement detail shows the assigned-products empty state', (
    tester,
  ) async {
    final repo = await _fake();
    repo.items = [_engagement(status: 'active')];
    repo.productItems = const [];
    await _pump(
      tester,
      repo,
      const WorkerEngagementDetailScreen(engagementId: 7),
    );

    expect(find.text('لا توجد منتجات محددة لهذا العمل حاليًا'), findsOneWidget);
  });

  testWidgets('active engagement details have no offer actions', (
    tester,
  ) async {
    final repo = await _fake(items: [_engagement(status: 'active')]);
    await _pump(
      tester,
      repo,
      const WorkerEngagementDetailScreen(engagementId: 7),
    );

    expect(find.text('تفاصيل العمل'), findsOneWidget);
    expect(find.text('الحالة: نشط'), findsOneWidget);
    expect(find.text('جلسة العمل'), findsOneWidget);
    expect(find.byKey(const Key('worker-offer-accept')), findsNothing);
    expect(find.byKey(const Key('worker-offer-reject')), findsNothing);
    expect(find.text('منح صلاحية'), findsNothing);
  });

  testWidgets('worker can accept an offer once', (tester) async {
    final repo = await _fake();
    final gate = Completer<WorkerEngagement>();
    repo.actionGate = gate;
    await _pump(
      tester,
      repo,
      const WorkerEngagementDetailScreen(engagementId: 7),
    );

    await tester.drag(find.byType(ListView).first, const Offset(0, -800));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('worker-offer-accept')));
    await tester.pump();
    expect(find.byType(CircularProgressIndicator), findsWidgets);
    await tester.drag(find.byType(ListView).first, const Offset(0, -200));
    await tester.pump();
    await tester.tap(find.byKey(const Key('worker-offer-accept')));
    expect(repo.actionCalls, 1);
    expect(repo.lastActionId, 7);
    expect(repo.lastAction, 'accept');

    gate.complete(_engagement(status: 'accepted'));
    await tester.pumpAndSettle();
    expect(find.text('الحالة: مقبول'), findsOneWidget);
    expect(find.text('تم قبول العرض'), findsOneWidget);
    expect(find.byKey(const Key('worker-offer-accept')), findsNothing);
    expect(find.text('active'), findsNothing);
  });

  testWidgets('worker can reject an offer', (tester) async {
    final repo = await _fake();
    await _pump(
      tester,
      repo,
      const WorkerEngagementDetailScreen(engagementId: 7),
    );

    await tester.drag(find.byType(ListView).first, const Offset(0, -800));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('worker-offer-reject')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('worker-offer-reject-confirm')));
    await tester.pumpAndSettle();

    expect(repo.lastAction, 'reject');
    expect(repo.lastActionId, 7);
    expect(find.text('الحالة: مرفوض'), findsOneWidget);
    expect(find.text('تم رفض العرض'), findsOneWidget);
    expect(find.byKey(const Key('worker-offer-accept')), findsNothing);
  });

  testWidgets('a transition error refreshes the engagement', (tester) async {
    final repo = await _fake();
    repo.actionFailure = ApiException(
      'لا يمكن تنفيذ هذا الإجراء على هذا الارتباط.',
      statusCode: 422,
    );
    repo.refreshed = _engagement(status: 'accepted');
    await _pump(
      tester,
      repo,
      const WorkerEngagementDetailScreen(engagementId: 7),
    );

    await tester.drag(find.byType(ListView).first, const Offset(0, -800));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('worker-offer-accept')));
    await tester.pumpAndSettle();

    expect(
      find.text('لا يمكن تنفيذ هذا الإجراء على هذا الارتباط.'),
      findsOneWidget,
    );
    expect(find.text('الحالة: مقبول'), findsOneWidget);
    expect(find.byKey(const Key('worker-offer-accept')), findsNothing);
  });

  testWidgets('opening an offer from the list shows its detail', (
    tester,
  ) async {
    final repo = await _fake();
    final router = GoRouter(
      initialLocation: workerOffersLocation,
      routes: [
        GoRoute(
          path: workerOffersLocation,
          builder: (context, state) => const WorkerOffersScreen(),
        ),
        GoRoute(
          path: '$workerEngagementsLocation/:engagementId',
          builder: (context, state) => WorkerEngagementDetailScreen(
            engagementId: int.tryParse(
              state.pathParameters['engagementId'] ?? '',
            ),
          ),
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
    await tester.tap(find.byKey(const Key('worker-offer-7')));
    await tester.pumpAndSettle();

    expect(find.text('تفاصيل العرض'), findsOneWidget);
    expect(find.text('قبول'), findsOneWidget);
  });
}

Future<_FakeWorkerRepository> _fake({List<WorkerEngagement>? items}) async {
  SharedPreferences.setMockInitialValues({});
  final prefs = PrefsStore(await SharedPreferences.getInstance());
  final repo = _FakeWorkerRepository(
    ApiClient(
      secureStore: _MemorySecureStore('token'),
      prefsStore: prefs,
      dio: Dio(BaseOptions(baseUrl: 'http://127.0.0.1:1/api/mobile/v1/')),
    ),
  );
  if (items != null) repo.items = items;
  return repo;
}

Future<void> _pump(
  WidgetTester tester,
  _FakeWorkerRepository repo,
  Widget child, {
  bool settle = true,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [workerRepositoryProvider.overrideWithValue(repo)],
      child: MaterialApp(home: child),
    ),
  );
  if (settle) await tester.pumpAndSettle();
}
