import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/features/analytics/data/analytics_models.dart';
import 'package:hasim/features/analytics/data/analytics_repository.dart';
import 'package:hasim/features/analytics/providers/analytics_controller.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _FakeAnalyticsRepository extends AnalyticsRepository {
  _FakeAnalyticsRepository(super.api);

  @override
  Future<WorkspaceAnalyticsModel> summary({String range = 'month', String? from, String? to}) async {
    return WorkspaceAnalyticsModel(
      from: '2026-01-01',
      to: '2026-01-31',
      revenue: range == 'today' ? 150.0 : 4500.0,
      ordersCount: range == 'today' ? 3 : 85,
      customersNew: range == 'today' ? 2 : 24,
      conversationsCount: range == 'today' ? 12 : 310,
      aiResponsesCount: range == 'today' ? 20 : 540,
      conversionRate: 27.4,
      topProducts: const [
        TopProductModel(name: 'عطر مسك فاخر', quantity: 18, revenue: 1620.0),
        TopProductModel(name: 'عود مروكي ملكي', quantity: 10, revenue: 2500.0),
      ],
      timeline: const [
        TimelineEntryModel(label: 'الأسبوع 1', orders: 20, revenue: 1000.0),
        TimelineEntryModel(label: 'الأسبوع 2', orders: 25, revenue: 1250.0),
      ],
      peakHours: const {'14:00': 15, '20:00': 32},
      paymentStatusBreakdown: const {'paid': 80, 'pending': 5},
    );
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('Analytics Models & Parsing', () {
    test('WorkspaceAnalyticsModel parsing handles all fields correctly', () {
      final json = {
        'from': '2026-02-01',
        'to': '2026-02-28',
        'revenue': 12500.50,
        'orders_count': 142,
        'customers_new': 35,
        'conversations_count': 500,
        'ai_responses_count': 920,
        'conversion_rate': 28.4,
        'top_products': [
          {'name': 'منتج تجريبي', 'quantity': 10, 'revenue': 1000.0},
        ],
        'timeline': [
          {'label': 'اليوم 1', 'orders': 5, 'revenue': 500.0},
        ],
        'peak_hours': {'12': 10, '18': 25},
        'payment_status_breakdown': {'paid': 130, 'failed': 12},
      };

      final model = WorkspaceAnalyticsModel.fromJson(json);
      expect(model.from, '2026-02-01');
      expect(model.to, '2026-02-28');
      expect(model.revenue, 12500.50);
      expect(model.ordersCount, 142);
      expect(model.customersNew, 35);
      expect(model.conversationsCount, 500);
      expect(model.aiResponsesCount, 920);
      expect(model.conversionRate, 28.4);
      expect(model.topProducts.length, 1);
      expect(model.topProducts.first.name, 'منتج تجريبي');
      expect(model.timeline.length, 1);
      expect(model.peakHours['18'], 25);
      expect(model.paymentStatusBreakdown['paid'], 130);
    });
  });

  group('AnalyticsController State Management', () {
    late _FakeAnalyticsRepository repo;
    late AnalyticsController controller;

    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      final prefs = PrefsStore(await SharedPreferences.getInstance());
      final api = isolatedTestApiClient(
        secureStore: SecureStore(),
        prefsStore: prefs,
      );
      repo = _FakeAnalyticsRepository(api);
      controller = AnalyticsController(repo);
    });

    test('initializes with month range and loads summary', () async {
      await controller.load('month');
      expect(controller.state.loading, isFalse);
      expect(controller.state.range, 'month');
      expect(controller.state.analytics, isNotNull);
      expect(controller.state.analytics!.revenue, 4500.0);
      expect(controller.state.analytics!.ordersCount, 85);
      expect(controller.state.analytics!.topProducts.length, 2);
    });

    test('changing range to today fetches filtered data', () async {
      await controller.load('today');
      expect(controller.state.loading, isFalse);
      expect(controller.state.range, 'today');
      expect(controller.state.analytics!.revenue, 150.0);
      expect(controller.state.analytics!.ordersCount, 3);
    });
  });
}
