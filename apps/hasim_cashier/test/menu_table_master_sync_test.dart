import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/pos/application/catalog_admin_service.dart';
import 'package:hasim_cashier/core/pos/application/local_auth_service.dart';
import 'package:hasim_cashier/core/pos/pos_errors.dart';
import 'package:hasim_cashier/core/pos/pos_mode.dart';
import 'package:hasim_cashier/core/repositories/catalog_repository.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';
import 'package:hasim_cashier/core/sync/sync_engine_v2.dart';
import 'package:hasim_cashier/core/sync/sync_queue_classifier.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late AppDatabase db;
  late SyncQueueRepository queue;
  late CatalogAdminService catalog;
  late CatalogRepository repo;

  const workspaceId = 12;
  const deviceId = 'POS-MENU';
  const perms = LocalAuthService.adminPermissions;

  setUp(() {
    db = AppDatabase.memory();
    queue = SyncQueueRepository(db);
    catalog = CatalogAdminService(
      db,
      queue: queue,
      deviceId: () async => deviceId,
    );
    repo = CatalogRepository(db);
  });

  tearDown(() async {
    await db.close();
  });

  test('Flutter can delete a server-owned category and enqueue category.deleted',
      () async {
    const localId = 'cat-server';
    await db.into(db.localCategories).insert(
      LocalCategoriesCompanion.insert(
        localId: localId,
        workspaceId: workspaceId,
        serverId: const Value(44),
        name: 'مشروبات سحابة',
        updatedAt: DateTime.now(),
      ),
    );
    await catalog.deleteCategory(
      workspaceId: workspaceId,
      localId: localId,
      permissions: perms,
    );
    expect(await repo.categories(workspaceId), isEmpty);
    final row = await (db.select(db.syncQueueItems)).getSingle();
    expect(row.entityType, 'category');
    expect(row.operation, 'delete');
    expect(row.entityId, localId);
  });

  test('Flutter can delete a server-owned product and enqueue product.deleted',
      () async {
    const localId = 'prod-server';
    await db.into(db.localProducts).insert(
      LocalProductsCompanion.insert(
        localId: localId,
        workspaceId: workspaceId,
        serverId: const Value(90),
        name: 'شاي سحابة',
        price: const Value(1000),
        updatedAt: DateTime.now(),
      ),
    );
    await catalog.deleteProduct(
      workspaceId: workspaceId,
      localId: localId,
      permissions: perms,
    );
    expect(await repo.products(workspaceId), isEmpty);
    final row = await (db.select(db.syncQueueItems)).getSingle();
    expect(row.entityType, 'product');
    expect(row.operation, 'delete');
  });

  test('category with products cannot be deleted locally', () async {
    final catId = await catalog.createCategory(
      workspaceId: workspaceId,
      name: 'مشروبات',
      permissions: perms,
    );
    await catalog.createProduct(
      workspaceId: workspaceId,
      name: 'شاي',
      price: 10,
      categoryLocalId: catId,
      permissions: perms,
    );
    expect(
      () => catalog.deleteCategory(
        workspaceId: workspaceId,
        localId: catId,
        permissions: perms,
      ),
      throwsA(isA<DatabaseFailure>()),
    );
  });

  test('900001 menu edits stay local and never enter the cloud queue', () async {
    await catalog.createCategory(
      workspaceId: PosMode.standaloneWorkspaceId,
      name: 'محلي',
      permissions: perms,
    );
    expect(await (db.select(db.syncQueueItems)).get(), isEmpty);
  });

  test('product create waits for category server id then pushes both', () async {
    final catId = await catalog.createCategory(
      workspaceId: workspaceId,
      name: 'مشروبات',
      permissions: perms,
    );
    final prodId = await catalog.createProduct(
      workspaceId: workspaceId,
      name: 'شاي',
      price: 10,
      categoryLocalId: catId,
      permissions: perms,
    );

    final classified = await SyncQueueClassifier(db).classifyWorkspace(workspaceId);
    expect(
      classified.where((c) => c.row.entityType == 'category').single.bucket,
      SyncQueueBucket.ready,
    );
    expect(
      classified.where((c) => c.row.entityType == 'product').single.bucket,
      SyncQueueBucket.waitingParent,
    );

    final posted = <Map<String, dynamic>>[];
    await SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async {
        posted.add(Map<String, dynamic>.from(body));
        final ops = (body['operations'] as List).cast<Map>();
        return {
          'accepted': [
            for (final op in ops)
              {
                'id': op['id'],
                'status': 'applied',
                'entity_id': op['type'] == 'category.created' ? 44 : 90,
                'result': {
                  'id': op['type'] == 'category.created' ? 44 : 90,
                  if (op['type'] == 'product.created')
                    'pos_item_category_id': 44,
                },
              },
          ],
          'failed': <Map<String, dynamic>>[],
        };
      },
    ).pushPending(workspaceId: workspaceId);

    expect(posted, isNotEmpty);
    expect(
      (posted.first['operations'] as List).map((op) => op['type']),
      contains('category.created'),
    );
    expect(
      (posted.first['operations'] as List).map((op) => op['type']),
      isNot(contains('product.created')),
    );
    final allTypes = [
      for (final body in posted)
        for (final op in (body['operations'] as List).cast<Map>()) op['type'],
    ];
    expect(allTypes, containsAll(['category.created', 'product.created']));

    final cat = await (db.select(db.localCategories)
          ..where((t) => t.localId.equals(catId)))
        .getSingle();
    expect(cat.serverId, 44);
    final product = await (db.select(db.localProducts)
          ..where((t) => t.localId.equals(prodId)))
        .getSingle();
    expect(product.serverId, 90);
    expect(product.categoryServerId, 44);
  });

  test('edit while create is pending folds into the same UUID', () async {
    final id = await catalog.createCategory(
      workspaceId: workspaceId,
      name: 'قديم',
      permissions: perms,
    );
    await catalog.updateCategory(
      workspaceId: workspaceId,
      localId: id,
      name: 'جديد',
      permissions: perms,
    );
    final rows = await (db.select(db.syncQueueItems)).get();
    expect(rows, hasLength(1));
    expect(rows.single.operation, 'create');
    expect(rows.single.payloadJson, contains('جديد'));
  });

  test('delete before create ACK cancels the queue row', () async {
    final id = await catalog.createCategory(
      workspaceId: workspaceId,
      name: 'مؤقت',
      permissions: perms,
    );
    await catalog.deleteCategory(
      workspaceId: workspaceId,
      localId: id,
      permissions: perms,
    );
    final rows = await (db.select(db.syncQueueItems)).get();
    expect(rows.single.status, 'cancelled');
    expect(rows.single.operation, 'create');
  });

  test('table create uses a local board id and null serverId', () async {
    final localId = await catalog.createTable(
      workspaceId: workspaceId,
      name: 'VIP 1',
      permissions: perms,
    );
    final row = await (db.select(db.localTables)
          ..where((t) => t.localId.equals(localId)))
        .getSingle();
    expect(row.serverId, isNull);
    expect(row.payloadJson, contains('board_id'));
    final queued = await (db.select(db.syncQueueItems)).getSingle();
    expect(queued.entityType, 'table');
    expect(queued.operation, 'create');
  });

  test('table_session open is auto-synced with menu/table master', () async {
    await queue.enqueue(
      workspaceId: workspaceId,
      deviceId: deviceId,
      entityType: 'table_session',
      entityId: 't-1',
      operation: 'open',
      payload: {'table_server_id': 1},
      clientReference: 'sess-1',
    );
    final item = (await SyncQueueClassifier(db).classifyWorkspace(workspaceId))
        .single;
    expect(item.bucket, SyncQueueBucket.ready);
    expect((await SyncQueueClassifier(db).counts(workspaceId)).scopedPending, 1);
  });
}
