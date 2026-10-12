import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/local_db/domain_local_store.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:sqlite3/sqlite3.dart';

void main() {
  test('v1 inbox upgrades without losing conversations or outbox', () async {
    final file = File(
      '${Directory.systemTemp.path}/hasim_phase_c_${DateTime.now().microsecondsSinceEpoch}.sqlite',
    );
    addTearDown(() {
      if (file.existsSync()) file.deleteSync();
    });
    final raw = sqlite3.open(file.path);
    for (final sql in inboxSchemaV1Sql) {
      raw.execute(sql);
    }
    raw.execute(
      'INSERT INTO inbox_conversations (workspace_id, server_id, channel, status, updated_at, cached_at) '
      "VALUES (1, 9, 'whatsapp', 'open', 1, 1)",
    );
    raw.execute(
      'INSERT INTO inbox_outbox (workspace_id, client_id, operation_type, conversation_server_id, payload, state, next_attempt_at, created_at, updated_at) '
      "VALUES (1, 'c1', 'send_text', 9, '{}', 'pending', 1, 1, 1)",
    );
    raw.execute('PRAGMA user_version = 1');
    raw.dispose();

    final db = InboxDatabase.file(file);
    addTearDown(db.close);
    final inbox = InboxLocalStore(db);
    expect((await inbox.getConversations(1)).single.channel, 'whatsapp');
    expect(await inbox.outboxCount(1), 1);
    final tables = await db.select(
      "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'local_contacts'",
    );
    expect(tables, isNotEmpty);
  });

  test('products, variants, and categories stay inside one workspace', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = DomainLocalStore(db);
    await store.upsertCategories(1, const [
      ChatCatalogCategoryModel(id: 3, name: 'عطور', parentId: null, sortOrder: 1),
    ]);
    await store.upsertProducts(1, const [
      ChatCatalogItemModel(
        id: 10,
        name: 'مسك',
        price: 25,
        workerMinimumPrice: 20,
        workerMaximumPrice: 40,
        categoryId: 3,
        variants: [
          ChatCatalogVariantModel(id: 8, name: 'صغير', sku: 'MSK-S', price: 25, sortOrder: 1),
        ],
      ),
    ]);
    await store.upsertProducts(2, const [
      ChatCatalogItemModel(id: 11, name: 'مساحة أخرى', price: 1),
    ]);

    final mine = await store.products(1, search: 'مسك');
    expect(mine, hasLength(1));
    expect(mine.single.workerMinimumPrice, 20);
    expect(mine.single.workerMaximumPrice, 40);
    expect((await store.product(1, 10))!.variants.single.sku, 'MSK-S');
    expect(await store.products(2, search: 'مسك'), isEmpty);
    expect((await store.categories(1)).single.name, 'عطور');
    expect(await store.categories(2), isEmpty);
  });

  test('digital pool cache drops secret fields', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = DomainLocalStore(db);
    await store.upsertDigitalPools(1, [
      {
        'id': 4,
        'name': 'حوض',
        'secret': 'DO-NOT-STORE',
        'metadata': {'label': 'ملف', 'code': 'HIDE'},
      },
    ]);
    await store.upsertDigitalUnits(1, 4, [
      {
        'id': 9,
        'unit_type': 'code',
        'status': 'available',
        'secret': 'UNIT-SECRET',
        'metadata': {'label': 'بطاقة'},
      },
    ]);
    final pools = await store.digitalPools(1);
    expect(pools.single['name'], 'حوض');
    expect(pools.single.containsKey('secret'), isFalse);
    expect((pools.single['metadata'] as Map).containsKey('code'), isFalse);
    final units = await db.select('SELECT label, unit_type FROM local_digital_units WHERE workspace_id = 1');
    expect(units.single['label'], 'بطاقة');
    expect(units.single['unit_type'], 'code');
  });

  test('contacts, groups, and emails are workspace scoped', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = DomainLocalStore(db);
    await store.upsertContacts(1, const [
      EmailContactModel(id: 1, name: 'سارة', email: 'sara@example.com', phone: '050', isFavorite: true),
    ]);
    await store.upsertContacts(2, const [
      EmailContactModel(id: 2, name: 'سارة', email: 'other@example.com'),
    ]);
    await store.upsertGroups(1, const [
      ContactGroupModel(
        id: 5,
        name: 'عملاء',
        contactsCount: 1,
        contacts: [EmailContactModel(id: 1, name: 'سارة', email: 'sara@example.com')],
      ),
    ]);
    await store.upsertEmails(1, 'inbox', const [
      EmailMessageModel(id: 7, sender: 'a@b.c', recipient: 'me', subject: 'فاتورة', isRead: false),
    ]);
    await store.upsertEmails(1, 'sent', const [
      EmailMessageModel(id: 8, sender: 'me', recipient: 'a@b.c', subject: 'رد'),
    ]);

    expect((await store.contacts(1, search: 'سارة')).single.email, 'sara@example.com');
    expect(await store.contacts(2, search: '050'), isEmpty);
    expect((await store.groups(1)).single.contactsCount, 1);
    expect((await store.emails(1, folder: 'inbox')).single.subject, 'فاتورة');
    expect(await store.emails(1, folder: 'sent'), hasLength(1));
    expect(await store.emails(2, folder: 'inbox'), isEmpty);

    await store.setEmailFlags(1, 7, isRead: true, isStarred: true);
    final updated = await store.email(1, 7);
    expect(updated?.isRead, isTrue);
    expect(updated?.isStarred, isTrue);
  });

  test('compose draft stays on the workspace and logout clears it', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = DomainLocalStore(db);
    final inbox = InboxLocalStore(db);
    await store.saveComposeDraft(workspaceId: 1, recipients: 'a@b.c', subject: 'مسودة', body: 'نص');
    expect((await store.composeDraft(1))?.subject, 'مسودة');
    expect(await store.composeDraft(2), isNull);
    await inbox.clearAll();
    expect(await store.composeDraft(1), isNull);
  });

  test('product query stays bounded for 500 rows', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = DomainLocalStore(db);
    await store.upsertProducts(1, [
      for (var i = 0; i < 500; i++)
        ChatCatalogItemModel(id: i + 1, name: 'منتج $i', price: i, sku: 'SKU$i'),
    ]);
    final started = DateTime.now();
    final found = await store.products(1, search: 'منتج 499');
    expect(found.single.name, 'منتج 499');
    expect(DateTime.now().difference(started).inMilliseconds, lessThan(1500));
  });
}
