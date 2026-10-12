import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/worker/data/worker_product.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';
import 'package:hasim/features/worker/presentation/worker_sales_reference_screen.dart';
import 'package:hasim/features/worker/providers/worker_sales_reference_controller.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _MemorySecureStore extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}

  @override
  Future<String?> readToken() async => 'worker-token';

  @override
  Future<void> clearToken() async {}
}

class _SeededAuthController extends AuthController {
  _SeededAuthController(super.ref, this.seed);

  final AuthState seed;

  @override
  Future<void> bootstrap() async {
    state = seed;
  }
}

class _FakeWorkerRepository extends WorkerRepository {
  _FakeWorkerRepository(super.api, this.prefs, this.itemsByWorkspace);

  final PrefsStore prefs;
  final Map<int, List<WorkerProduct>> itemsByWorkspace;
  final List<int?> requestedWorkspaceIds = [];
  bool denyProducts = false;

  @override
  Future<List<WorkerEngagement>> engagements() async {
    final workspaceId = prefs.workspaceId;
    if (workspaceId == null) return const [];
    return [
      WorkerEngagement(
        id: workspaceId * 10,
        status: 'active',
        workspaceId: workspaceId,
        workspaceName: 'الشركة $workspaceId',
        capabilities: const [
          WorkerCapability(
            key: 'catalog.products.view',
            displayName: 'عرض المنتجات',
          ),
        ],
      ),
    ];
  }

  @override
  Future<List<WorkerProduct>> products(int engagementId) async {
    requestedWorkspaceIds.add(prefs.workspaceId);
    if (denyProducts) {
      throw ApiException('blocked', statusCode: 403);
    }
    return itemsByWorkspace[prefs.workspaceId] ?? const [];
  }
}

const _workerAuth = AuthState(
  bootstrapping: false,
  token: 'worker-token',
  user: UserModel(id: 3, name: 'عامل'),
  participant: MobileParticipant(
    type: 'independent_worker',
    workerProfileId: 9,
  ),
);

WorkerProduct _item({
  required int id,
  required String name,
  required String itemKind,
  String? description,
}) {
  return WorkerProduct(
    id: id,
    name: name,
    itemKind: itemKind,
    description: description,
    price: 125,
    currency: 'SAR',
  );
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('reference reloads when the worker changes company', () async {
    SharedPreferences.setMockInitialValues({});
    final preferences = await SharedPreferences.getInstance();
    final prefs = PrefsStore(preferences);
    await prefs.setWorkspaceId(1);
    final secure = _MemorySecureStore();
    final api = isolatedTestApiClient(secureStore: secure, prefsStore: prefs);
    final repository = _FakeWorkerRepository(api, prefs, {
      1: [_item(id: 1, name: 'منتج الشركة الأولى', itemKind: 'product')],
      2: [_item(id: 2, name: 'خدمة الشركة الثانية', itemKind: 'service')],
    });
    final container = ProviderContainer(
      overrides: [
        sharedPrefsProvider.overrideWithValue(preferences),
        prefsStoreProvider.overrideWithValue(prefs),
        secureStoreProvider.overrideWithValue(secure),
        apiClientProvider.overrideWithValue(api),
        workerRepositoryProvider.overrideWithValue(repository),
        authControllerProvider.overrideWith(
          (ref) => _SeededAuthController(ref, _workerAuth),
        ),
      ],
    );
    addTearDown(container.dispose);

    var controller = container.read(
      workerSalesReferenceControllerProvider.notifier,
    );
    await controller.load();
    expect(controller.state.items.map((item) => item.name), [
      'منتج الشركة الأولى',
    ]);

    await prefs.setWorkspaceId(2);
    container.invalidate(activeWorkspaceIdProvider);
    controller = container.read(
      workerSalesReferenceControllerProvider.notifier,
    );
    await controller.load();

    expect(controller.state.items.map((item) => item.name), [
      'خدمة الشركة الثانية',
    ]);
    expect(repository.requestedWorkspaceIds, [1, 2]);
  });

  test('reference reports missing worker capability separately', () async {
    SharedPreferences.setMockInitialValues({});
    final preferences = await SharedPreferences.getInstance();
    final prefs = PrefsStore(preferences);
    await prefs.setWorkspaceId(1);
    final secure = _MemorySecureStore();
    final api = isolatedTestApiClient(secureStore: secure, prefsStore: prefs);
    final repository = _FakeWorkerRepository(api, prefs, const {});
    repository.denyProducts = true;
    final container = ProviderContainer(
      overrides: [
        sharedPrefsProvider.overrideWithValue(preferences),
        prefsStoreProvider.overrideWithValue(prefs),
        secureStoreProvider.overrideWithValue(secure),
        apiClientProvider.overrideWithValue(api),
        workerRepositoryProvider.overrideWithValue(repository),
        authControllerProvider.overrideWith(
          (ref) => _SeededAuthController(ref, _workerAuth),
        ),
      ],
    );
    addTearDown(container.dispose);

    final controller = container.read(
      workerSalesReferenceControllerProvider.notifier,
    );
    await controller.load();

    expect(controller.state.permissionDenied, isTrue);
    expect(controller.state.error, isNull);
  });

  testWidgets('worker sales reference is read-only', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    SharedPreferences.setMockInitialValues({});
    final preferences = await SharedPreferences.getInstance();
    final prefs = PrefsStore(preferences);
    await prefs.setWorkspaceId(1);
    final secure = _MemorySecureStore();
    final api = isolatedTestApiClient(secureStore: secure, prefsStore: prefs);
    final repository = _FakeWorkerRepository(api, prefs, {
      1: [
        _item(
          id: 1,
          name: 'منتج الشركة',
          itemKind: 'product',
          description: 'وصف المنتج',
        ),
        _item(
          id: 2,
          name: 'خدمة الشركة',
          itemKind: 'service',
          description: 'وصف الخدمة',
        ),
      ],
    });

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(preferences),
          prefsStoreProvider.overrideWithValue(prefs),
          secureStoreProvider.overrideWithValue(secure),
          apiClientProvider.overrideWithValue(api),
          workerRepositoryProvider.overrideWithValue(repository),
          authControllerProvider.overrideWith(
            (ref) => _SeededAuthController(ref, _workerAuth),
          ),
        ],
        child: MaterialApp(
          theme: AppTheme.light(),
          locale: const Locale('ar'),
          home: const WorkerSalesReferenceScreen(embedded: true),
        ),
      ),
    );
    await tester.pump();
    await tester.pumpAndSettle();

    expect(find.text('مرجع المبيعات'), findsOneWidget);
    expect(find.text('منتج الشركة'), findsOneWidget);
    expect(find.text('تعديل'), findsNothing);
    expect(find.text('حذف'), findsNothing);
    expect(find.text('إضافة منتج'), findsNothing);

    await tester.tap(find.text('الخدمات'));
    await tester.pumpAndSettle();
    expect(find.text('خدمة الشركة'), findsOneWidget);
  });
}
