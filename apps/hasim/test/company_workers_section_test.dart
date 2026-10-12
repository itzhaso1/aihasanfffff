import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/data/chat_catalog_repository.dart';
import 'package:hasim/features/settings/data/ai_setting_model.dart';
import 'package:hasim/features/settings/data/company_worker_discovery_repository.dart';
import 'package:hasim/features/settings/presentation/ai_settings_screen.dart';
import 'package:hasim/features/settings/providers/ai_employees_controller.dart';
import 'package:hasim/features/settings/providers/ai_settings_controller.dart';
import 'package:hasim/features/settings/providers/company_worker_discovery_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  testWidgets(
    'real workers stay separate from AI employees and can open a profile or send an offer',
    (tester) async {
      tester.view.physicalSize = const Size(390, 4000);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      SharedPreferences.setMockInitialValues({});
      final prefs = PrefsStore(await SharedPreferences.getInstance());
      final catalogRepository = _FakeCatalogRepository(
        ApiClient(secureStore: SecureStore(), prefsStore: prefs),
      );

      late _OfferDiscoveryController discovery;
      final router = GoRouter(
        initialLocation: '/ai-settings',
        routes: [
          GoRoute(
            path: '/ai-settings',
            builder: (_, _) => const AiSettingsScreen(),
          ),
          GoRoute(
            path: '/company-worker-profile',
            builder: (_, state) {
              final worker = state.extra as CompanyWorkerDiscoveryWorker?;
              return Scaffold(body: Text('ملف ${worker?.displayName ?? ''}'));
            },
          ),
        ],
      );

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            aiSettingsControllerProvider.overrideWith(_SettingsController.new),
            aiEmployeesControllerProvider.overrideWith(
              (ref) => _EmployeesController(
                ref,
                const AiEmployeesState(employees: [], presets: []),
              ),
            ),
            companyWorkerDiscoveryControllerProvider.overrideWith((ref) {
              discovery = _OfferDiscoveryController(ref, _workerState());
              return discovery;
            }),
            companyWorkerDiscoveryRepositoryProvider.overrideWithValue(
              _FakeDiscoveryRepository(
                ApiClient(secureStore: SecureStore(), prefsStore: prefs),
              ),
            ),
            chatCatalogRepositoryProvider.overrideWithValue(catalogRepository),
          ],
          child: MaterialApp.router(
            locale: const Locale('ar'),
            localizationsDelegates: AppLocalizations.localizationsDelegates,
            supportedLocales: AppLocalizations.supportedLocales,
            routerConfig: router,
          ),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('عام'), findsOneWidget);
      expect(find.text('موظفو الذكاء الاصطناعي'), findsOneWidget);
      expect(find.text('التعاقد مع موظفين حقيقيين'), findsOneWidget);

      await tester.tap(find.text('موظفو الذكاء الاصطناعي'));
      await tester.pumpAndSettle();
      expect(
        find.text(
          'لا يوجد موظفون بعد. أضف موظفًا من قالب أو أنشئ موظفًا جديدًا.',
        ),
        findsOneWidget,
      );
      expect(find.text('الموظفون الحقيقيون'), findsNothing);

      await tester.tap(find.text('التعاقد مع موظفين حقيقيين'));
      await tester.pumpAndSettle();
      expect(
        find.byKey(const ValueKey('company-worker-discovery-tab')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('company-worker-open-contracts-tab')),
        findsOneWidget,
      );
      expect(find.text('الموظفون الحقيقيون'), findsNWidgets(2));
      expect(find.text('العقود المفتوحة'), findsOneWidget);
      expect(find.text('عامل متعاقد'), findsNothing);
      expect(find.text('عرض تفاصيل التعاقد'), findsNothing);

      await tester.tap(
        find.byKey(const ValueKey('company-worker-open-contracts-tab')),
      );
      await tester.pumpAndSettle();
      expect(find.text('عامل متعاقد'), findsOneWidget);
      expect(find.text('التعاقد نشط'), findsOneWidget);
      expect(find.text('1 شهر'), findsOneWidget);
      expect(find.text('عرض تفاصيل التعاقد'), findsOneWidget);
      expect(find.text('عامل الدعم'), findsNothing);
      final contractDetails = tester.getRect(find.text('عرض تفاصيل التعاقد'));
      final capabilities = tester.getRect(find.text('إدارة الصلاحيات'));
      expect(
        (contractDetails.center.dy - capabilities.center.dy).abs(),
        lessThan(1),
      );

      await tester.tap(
        find.byKey(const ValueKey('company-worker-discovery-tab')),
      );
      await tester.pumpAndSettle();
      expect(find.text('عامل متعاقد'), findsNothing);
      expect(find.text('عامل الدعم'), findsOneWidget);
      expect(find.text('يدير محادثات العملاء'), findsOneWidget);
      expect(find.text('JO'), findsOneWidget);
      expect(find.text('متاح'), findsOneWidget);
      expect(find.text('موثّق'), findsOneWidget);
      expect(find.textContaining('دعم العملاء'), findsOneWidget);
      expect(find.text('عرض الملف الشخصي'), findsOneWidget);
      expect(find.text('إرسال عرض'), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('real-worker-9')),
          matching: find.text('تفعيل'),
        ),
        findsNothing,
      );

      await tester.tap(find.byKey(const ValueKey('real-worker-view-9')));
      await tester.pumpAndSettle();
      expect(find.text('ملف عامل الدعم'), findsOneWidget);

      router.pop();
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('real-worker-offer-9')));
      await tester.pumpAndSettle();
      expect(find.text('إرسال عرض إلى عامل الدعم'), findsOneWidget);
      await tester.pumpAndSettle();
      expect(find.text('سعر التعاقد: 100.00 SAR'), findsOneWidget);
      expect(find.text('سعر اليوم للمنتج'), findsNothing);
      expect(find.text('سعر الأسبوع للمنتج'), findsNothing);
      expect(find.text('سعر الشهر للمنتج'), findsNothing);
      await tester.enterText(
        find.byKey(const ValueKey('worker-offer-starts-at')),
        '2026-10-01',
      );
      await tester.pump();
      expect(find.text('النهاية المحسوبة: 2026-10-02'), findsOneWidget);
      await tester.tap(
        find.byKey(const ValueKey('worker-offer-duration-preset')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('أسبوع واحد').last);
      await tester.pumpAndSettle();
      expect(find.text('النهاية المحسوبة: 2026-10-08'), findsOneWidget);
      expect(find.text('سعر التعاقد: 600.00 SAR'), findsOneWidget);
      await tester.tap(
        find.byKey(const ValueKey('worker-offer-duration-preset')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('شهر واحد').last);
      await tester.pumpAndSettle();
      expect(find.text('النهاية المحسوبة: 2026-11-01'), findsOneWidget);
      await tester.tap(
        find.byKey(const ValueKey('worker-offer-duration-preset')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('مدة مخصصة').last);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('worker-offer-duration-value')),
        '45',
      );
      await tester.pump();
      expect(find.text('النهاية المحسوبة: 2026-11-15'), findsOneWidget);

      await tester.tap(find.byType(CheckboxListTile).first);
      await tester.pump();
      await tester.enterText(
        find.byKey(const ValueKey('worker-offer-product-min-101')),
        '22',
      );
      await tester.enterText(
        find.byKey(const ValueKey('worker-offer-product-max-101')),
        '30',
      );
      await tester.enterText(find.byType(TextFormField).first, 'دعم العملاء');
      await tester.enterText(
        find.byKey(const ValueKey('worker-offer-product-min-101')),
        '31',
      );
      await tester.tap(find.byKey(const ValueKey('worker-offer-submit')).last);
      await tester.pump();
      expect(
        find.textContaining('يجب ألا يكون الحد الأدنى أكبر من الحد الأعلى'),
        findsOneWidget,
      );
      expect(discovery.sendCalls, 0);
      await tester.enterText(
        find.byKey(const ValueKey('worker-offer-product-min-101')),
        '22',
      );
      await tester.tap(find.byTooltip('إزالة المنتج'));
      await tester.pump();
      expect(
        find.byKey(const ValueKey('worker-offer-product-min-101')),
        findsNothing,
      );
      await tester.tap(find.byType(CheckboxListTile).first);
      await tester.pump();
      await tester.enterText(
        find.byKey(const ValueKey('worker-offer-product-min-101')),
        '22',
      );
      await tester.enterText(
        find.byKey(const ValueKey('worker-offer-product-max-101')),
        '30',
      );
      await tester.enterText(find.byType(TextFormField).first, 'دعم العملاء');
      await tester.tap(find.byKey(const ValueKey('worker-offer-submit')).last);
      await tester.pumpAndSettle();

      expect(discovery.lastWorkerId, 9);
      expect(discovery.lastJobTitle, 'دعم العملاء');
      expect(discovery.lastDurationValue, 45);
      expect(discovery.lastDurationUnit, 'day');
      expect(discovery.lastProducts, hasLength(1));
      expect(discovery.lastProducts.single.id, 101);
      expect(discovery.lastProducts.single.minimumSellingPrice, '22');
      expect(discovery.lastProducts.single.maximumSellingPrice, '30');
      expect(find.text('تم إرسال العرض'), findsOneWidget);
    },
  );

  testWidgets(
    'offer sheet stays below the top bar and keeps the send action visible',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      SharedPreferences.setMockInitialValues({});
      final prefs = PrefsStore(await SharedPreferences.getInstance());
      final catalogRepository = _FakeCatalogRepository(
        ApiClient(secureStore: SecureStore(), prefsStore: prefs),
      );

      final router = GoRouter(
        initialLocation: '/ai-settings',
        routes: [
          GoRoute(
            path: '/ai-settings',
            builder: (_, _) => const AiSettingsScreen(),
          ),
        ],
      );

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            aiSettingsControllerProvider.overrideWith(_SettingsController.new),
            aiEmployeesControllerProvider.overrideWith(
              (ref) => _EmployeesController(
                ref,
                const AiEmployeesState(employees: [], presets: []),
              ),
            ),
            companyWorkerDiscoveryControllerProvider.overrideWith(
              (ref) => _OfferDiscoveryController(ref, _workerState()),
            ),
            companyWorkerDiscoveryRepositoryProvider.overrideWithValue(
              _FakeDiscoveryRepository(
                ApiClient(secureStore: SecureStore(), prefsStore: prefs),
              ),
            ),
            chatCatalogRepositoryProvider.overrideWithValue(catalogRepository),
          ],
          child: MaterialApp.router(
            locale: const Locale('ar'),
            localizationsDelegates: AppLocalizations.localizationsDelegates,
            supportedLocales: AppLocalizations.supportedLocales,
            routerConfig: router,
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('التعاقد مع موظفين حقيقيين'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('real-worker-offer-9')));
      await tester.pumpAndSettle();

      final title = tester.getRect(find.text('إرسال عرض إلى عامل الدعم'));
      final sheet = tester.getRect(
        find.byKey(const ValueKey('worker-offer-sheet')),
      );
      final submit = tester.getRect(
        find.byKey(const ValueKey('worker-offer-submit')),
      );

      expect(title.top, greaterThan(kToolbarHeight));
      expect(sheet.left, greaterThanOrEqualTo(12));
      expect(sheet.right, lessThanOrEqualTo(378));
      expect(sheet.width, lessThan(390));
      expect(sheet.height, lessThan(844 - kToolbarHeight - 40));
      expect(submit.bottom, lessThanOrEqualTo(844 - 8));
      expect(submit.top, greaterThan(title.bottom));
      expect(find.text('إرسال العرض'), findsOneWidget);
    },
  );

  testWidgets('company offer editor sends the engagement work guide', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 4000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    SharedPreferences.setMockInitialValues({});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    final catalogRepository = _FakeCatalogRepository(
      ApiClient(secureStore: SecureStore(), prefsStore: prefs),
    );

    late _OfferDiscoveryController discovery;
    final router = GoRouter(
      initialLocation: '/ai-settings',
      routes: [
        GoRoute(
          path: '/ai-settings',
          builder: (_, _) => const AiSettingsScreen(),
        ),
      ],
    );
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          aiSettingsControllerProvider.overrideWith(_SettingsController.new),
          aiEmployeesControllerProvider.overrideWith(
            (ref) => _EmployeesController(
              ref,
              const AiEmployeesState(employees: [], presets: []),
            ),
          ),
          companyWorkerDiscoveryControllerProvider.overrideWith((ref) {
            discovery = _OfferDiscoveryController(ref, _workerState());
            return discovery;
          }),
          companyWorkerDiscoveryRepositoryProvider.overrideWithValue(
            _FakeDiscoveryRepository(
              ApiClient(secureStore: SecureStore(), prefsStore: prefs),
            ),
          ),
          chatCatalogRepositoryProvider.overrideWithValue(catalogRepository),
        ],
        child: MaterialApp.router(
          locale: const Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          routerConfig: router,
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('التعاقد مع موظفين حقيقيين'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('real-worker-offer-9')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('worker-offer-starts-at')),
      '2026-10-01',
    );

    await tester.enterText(
      find.byKey(const ValueKey('worker-offer-guide-company-overview')),
      'شركة متخصصة في الإلكترونيات',
    );
    await tester.enterText(
      find.byKey(const ValueKey('worker-offer-guide-responsibilities')),
      'الرد على العملاء\nشرح المنتجات',
    );
    await tester.enterText(find.byType(TextFormField).first, 'مندوب مبيعات');
    await tester.tap(find.text('إضافة سؤال'));
    await tester.pump();
    await tester.enterText(
      find.byKey(const ValueKey('worker-offer-guide-faq-question-0')),
      'متى أطلب موافقة؟',
    );
    await tester.enterText(
      find.byKey(const ValueKey('worker-offer-guide-faq-answer-0')),
      'عند طلب خصم استثنائي.',
    );
    await tester.tap(find.byKey(const ValueKey('worker-offer-submit')).last);
    await tester.pumpAndSettle();

    expect(discovery.lastGuide?.companyOverview, 'شركة متخصصة في الإلكترونيات');
    expect(discovery.lastGuide?.responsibilities, [
      'الرد على العملاء',
      'شرح المنتجات',
    ]);
    expect(discovery.lastGuide?.faqItems.single.question, 'متى أطلب موافقة؟');
    expect(
      discovery.lastGuide?.faqItems.single.answer,
      'عند طلب خصم استثنائي.',
    );
  });
}

CompanyWorkerDiscoveryState _workerState() {
  return const CompanyWorkerDiscoveryState(
    workers: [
      CompanyWorkerDiscoveryWorker(
        id: 9,
        displayName: 'عامل الدعم',
        countryCode: 'JO',
        professionalSummary: 'يدير محادثات العملاء',
        salesExperienceLevel: 'senior',
        availabilityStatus: 'available',
        verifiedBadge: true,
        skills: [
          CompanyWorkerDiscoverySkill(
            skillKey: 'customer_support',
            displayName: 'دعم العملاء',
          ),
        ],
        languages: ['ar'],
        experiences: [],
      ),
    ],
    openEngagements: [
      CompanyWorkerOpenEngagement(
        id: 22,
        workerProfileId: 11,
        status: 'active',
        workerDisplayName: 'عامل متعاقد',
        jobTitle: 'مندوب مبيعات',
        startsAt: '2026-10-01',
        endsAt: '2026-11-01',
        durationValue: 1,
        durationUnit: 'month',
        hasGuide: true,
        grantedCapabilitiesCount: 2,
        assignedProductsCount: 3,
      ),
    ],
  );
}

class _OfferDiscoveryController extends CompanyWorkerDiscoveryController {
  _OfferDiscoveryController(super.ref, this.seed);

  final CompanyWorkerDiscoveryState seed;
  int? lastWorkerId;
  String? lastJobTitle;
  int? lastDurationValue;
  String? lastDurationUnit;
  List<CompanyWorkerOfferProduct> lastProducts = const [];
  CompanyWorkerOfferGuide? lastGuide;
  int sendCalls = 0;

  @override
  Future<void> reload() async {
    state = seed;
  }

  @override
  Future<bool> sendOffer(
    CompanyWorkerDiscoveryWorker worker, {
    required String jobTitle,
    String? jobDescription,
    String? startsAt,
    required int durationValue,
    required String durationUnit,
    List<CompanyWorkerOfferProduct> products = const [],
    CompanyWorkerOfferGuide? guide,
  }) async {
    lastWorkerId = worker.id;
    lastJobTitle = jobTitle;
    lastDurationValue = durationValue;
    lastDurationUnit = durationUnit;
    lastProducts = products;
    lastGuide = guide;
    sendCalls += 1;
    return true;
  }
}

class _FakeDiscoveryRepository extends CompanyWorkerDiscoveryRepository {
  _FakeDiscoveryRepository(super.api);

  @override
  Future<CompanyWorkerContractPrice> fetchContractPrice(
    int workerProfileId, {
    required int durationValue,
    required String durationUnit,
  }) async {
    final amountMinor = switch (durationUnit) {
      'week' => 60000,
      'month' => 200000,
      _ => 10000,
    };

    return CompanyWorkerContractPrice(
      durationValue: durationValue,
      durationUnit: durationUnit,
      amountMinor: amountMinor,
      currency: 'SAR',
    );
  }
}

class _FakeCatalogRepository extends ChatCatalogRepository {
  _FakeCatalogRepository(super.api);

  @override
  Future<({List<ChatCatalogItemModel> items, String? nextCursor})> list({
    String? cursor,
    String? search,
    String? itemKind,
    int? categoryId,
    bool? activeOnly,
    int perPage = 20,
  }) async {
    return (
      items: const [
        ChatCatalogItemModel(
          id: 101,
          name: 'منتج الشركة',
          price: 25,
          workerMinimumPrice: 22,
          workerMaximumPrice: 30,
          currency: 'SAR',
        ),
      ],
      nextCursor: null,
    );
  }
}

class _SettingsController extends AiSettingsController {
  _SettingsController(super.ref);

  @override
  Future<void> reload() async {
    state = AiSettingsState(
      settings: AiSettingModel.fromJson({
        'id': 1,
        'name': 'Legacy',
        'ai_role': 'sales_agent',
        'is_active': true,
      }),
      aiRoles: const ['sales_agent'],
    );
  }
}

class _EmployeesController extends AiEmployeesController {
  _EmployeesController(super.ref, this.seed);

  final AiEmployeesState seed;

  @override
  Future<void> reload() async {
    state = seed;
  }
}
