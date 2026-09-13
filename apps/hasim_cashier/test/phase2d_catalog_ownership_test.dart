import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/pos/application/catalog_admin_service.dart';
import 'package:hasim_cashier/core/pos/application/local_auth_service.dart';
import 'package:hasim_cashier/core/repositories/catalog_repository.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late AppDatabase db;
  late CatalogAdminService catalog;
  late CatalogRepository repo;

  const workspaceId = 1;
  const perms = LocalAuthService.adminPermissions;

  setUp(() {
    db = AppDatabase.memory();
    catalog = CatalogAdminService(db);
    repo = CatalogRepository(db);
  });

  tearDown(() async {
    await db.close();
  });

  test('local category without server_id is soft-deleted offline', () async {
    final id = await catalog.createCategory(
      workspaceId: workspaceId,
      name: 'تصنيف محلي قديم',
      permissions: perms,
    );
    await catalog.deleteCategory(
      workspaceId: workspaceId,
      localId: id,
      permissions: perms,
    );
    final visible = await repo.categories(workspaceId);
    expect(visible, isEmpty);
    final row = await (db.select(db.localCategories)
          ..where((t) => t.localId.equals(id)))
        .getSingle();
    expect(row.isDeleted, isTrue);
    expect(row.serverId, isNull);
  });

  test('server-owned category can be deleted from Flutter cashier', () async {
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
    final row = await (db.select(db.localCategories)
          ..where((t) => t.localId.equals(localId)))
        .getSingle();
    expect(row.isDeleted, isTrue);
    expect(row.serverId, 44);
  });

  test('server-owned product can be deleted from Flutter cashier', () async {
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
  });
}
