import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/local_db/domain_local_store.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/commerce/data/commerce_order_models.dart';
import 'package:hasim/features/commerce/data/commerce_order_repository.dart';
import 'package:hasim/features/settings/data/plan_channel_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late ApiClient api;

  setUp(() async {
    SharedPreferences.setMockInitialValues({'workspace_id': 1});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    api = isolatedTestApiClient(
      secureStore: _MemorySecure(),
      prefsStore: prefs,
    );
  });

  test('InboxDatabase schema v12 creates commerce tables and outbox diagnostics columns', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    await db.ensureReady();

    expect(db.schemaVersion, 12);

    final tables = await db.select(
      "SELECT name FROM sqlite_master WHERE type='table' AND name IN ('local_commerce_orders', 'local_customers')",
    );
    final tableNames = tables.map((r) => r['name']?.toString()).toSet();
    expect(tableNames.contains('local_commerce_orders'), isTrue);
    expect(tableNames.contains('local_customers'), isTrue);

    final msgCols = await db.select('PRAGMA table_info(inbox_messages)');
    final colNames = msgCols.map((r) => r['name']?.toString()).toSet();
    expect(colNames.contains('local_error'), isTrue);
    expect(colNames.contains('correlation_id'), isTrue);
  });

  test('DomainLocalStore stores, filters, and retrieves commerce orders offline', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = DomainLocalStore(db);

    const order1 = CommerceOrderModel(
      id: 101,
      orderNumber: 'ORD-101',
      status: 'completed',
      currency: 'SAR',
      totalAmount: '250.00',
      customerId: 5,
      items: [
        CommerceOrderItemModel(
          id: 1,
          name: 'قميص فاخر',
          quantity: 2,
          unitPrice: '125.00',
          totalAmount: '250.00',
        ),
      ],
    );

    const order2 = CommerceOrderModel(
      id: 102,
      orderNumber: 'ORD-102',
      status: 'pending_payment',
      currency: 'SAR',
      totalAmount: '80.00',
      customerId: 6,
    );

    await store.upsertOrders(1, const [order1, order2]);

    final allOrders = await store.orders(1);
    expect(allOrders.length, 2);

    final completedOrders = await store.orders(1, status: 'completed');
    expect(completedOrders.length, 1);
    expect(completedOrders.first.orderNumber, 'ORD-101');
    expect(completedOrders.first.items.length, 1);
    expect(completedOrders.first.items.first.name, 'قميص فاخر');

    final customer6Orders = await store.orders(1, customerId: 6);
    expect(customer6Orders.length, 1);
    expect(customer6Orders.first.orderNumber, 'ORD-102');

    final singleOrder = await store.order(1, 101);
    expect(singleOrder?.orderNumber, 'ORD-101');
    expect(singleOrder?.statusLabel, 'مكتمل');
  });

  test('DomainLocalStore stores, searches, and retrieves customers offline', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = DomainLocalStore(db);

    const customer1 = CustomerModel(
      id: 501,
      name: 'عبدالله محمد',
      phone: '0501234567',
      email: 'abdullah@example.com',
      city: 'الرياض',
      status: 'completed',
    );

    const customer2 = CustomerModel(
      id: 502,
      name: 'سارة خالد',
      phone: '0559876543',
      email: 'sara@example.com',
      city: 'جدة',
      status: 'needs_review',
    );

    await store.upsertCustomers(1, const [customer1, customer2]);

    final all = await store.customers(1);
    expect(all.length, 2);

    final searchPhone = await store.customers(1, search: '050123');
    expect(searchPhone.length, 1);
    expect(searchPhone.first.name, 'عبدالله محمد');

    final searchName = await store.customers(1, search: 'سارة');
    expect(searchName.length, 1);
    expect(searchName.first.id, 502);

    final singleCustomer = await store.customer(1, 501);
    expect(singleCustomer?.name, 'عبدالله محمد');
    expect(singleCustomer?.city, 'الرياض');
  });

  test('CommerceOrderRepository and CustomerRepository fall back to SQLite when offline', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = DomainLocalStore(db);

    const cachedOrder = CommerceOrderModel(
      id: 200,
      orderNumber: 'ORD-OFFLINE-1',
      status: 'processing',
      currency: 'SAR',
      totalAmount: '450.00',
    );
    await store.upsertOrders(1, const [cachedOrder]);

    const cachedCustomer = CustomerModel(
      id: 300,
      name: 'أحمد السعيد',
      phone: '0540001122',
      status: 'paid',
    );
    await store.upsertCustomers(1, const [cachedCustomer]);

    // api will fail with network error because http://127.0.0.1:1 is unreachable
    final orderRepo = CommerceOrderRepository(
      api,
      local: store,
      workspaceIdOf: () => 1,
    );
    final customerRepo = CustomerRepository(
      api,
      local: store,
      workspaceIdOf: () => 1,
    );

    final orders = await orderRepo.list();
    expect(orders.length, 1);
    expect(orders.first.orderNumber, 'ORD-OFFLINE-1');
    expect(orderRepo.lastReadFromCache, isTrue);

    final singleOrder = await orderRepo.show(200);
    expect(singleOrder.totalAmount, '450.00');

    final customers = await customerRepo.list();
    expect(customers.length, 1);
    expect(customers.first.name, 'أحمد السعيد');
    expect(customerRepo.lastReadFromCache, isTrue);

    final singleCustomer = await customerRepo.show(300);
    expect(singleCustomer.phone, '0540001122');
  });
}

class _MemorySecure extends SecureStore {
  @override
  Future<String?> readToken() async => null;
  @override
  Future<void> saveToken(String token) async {}
  @override
  Future<void> clearToken() async {}
}
