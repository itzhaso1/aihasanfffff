import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/local_db/worker_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late ApiClient api;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    api = isolatedTestApiClient(
      secureStore: _MemorySecure(),
      prefsStore: prefs,
    );
  });
  test('worker records stay separated by worker and engagement', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = WorkerLocalStore(db);
    await store.put(1, 'engagements', [
      {'id': 10, 'status': 'active', 'workspace': {'id': 4, 'name': 'شركة أ'}},
    ]);
    await store.put(1, 'products', [
      {'id': 3, 'name': 'منتج أ'},
    ], scopeId: 10);
    await store.put(1, 'products', [
      {'id': 4, 'name': 'منتج ب'},
    ], scopeId: 11);
    await store.put(2, 'engagements', [
      {'id': 20, 'status': 'offered', 'workspace': {'id': 9, 'name': 'شركة ب'}},
    ]);

    final repoA = WorkerRepository(api, local: store, workerProfileIdOf: () => 1);
    final repoB = WorkerRepository(api, local: store, workerProfileIdOf: () => 2);

    expect((await repoA.cachedEngagements()).single.workspaceName, 'شركة أ');
    expect((await repoA.cachedProducts(10)).single.name, 'منتج أ');
    expect(await repoA.cachedProducts(11), isNotEmpty);
    expect((await repoA.cachedProducts(10)).map((item) => item.name), isNot(contains('منتج ب')));
    expect((await repoB.cachedEngagements()).single.id, 20);
    expect(await repoB.cachedProducts(10), isEmpty);
  });

  test('profile cache keeps skills and does not require a token column', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = WorkerLocalStore(db);
    await store.put(7, 'profile', {
      'id': 7,
      'display_name': 'ليان',
      'verification_status': 'pending',
      'verification_status_label': 'قيد المراجعة',
      'lifecycle_status': 'active',
      'lifecycle_status_label': 'نشط',
      'availability_status': 'available',
      'availability_status_label': 'متاح',
      'professional_summary': 'مبيعات',
      'sales_experience_level': 'mid',
      'skills': [
        {'id': 1, 'skill_key': 'close', 'display_name': 'إغلاق'},
      ],
      'languages': [
        {'language_code': 'ar'},
      ],
      'experiences': [
        {'id': 2, 'title': 'مندوب', 'organization_name': 'متجر', 'is_current': true},
      ],
    });
    final repo = WorkerRepository(api, local: store, workerProfileIdOf: () => 7);
    final profile = await repo.cachedProfile();
    expect(profile?.displayName, 'ليان');
    expect(profile?.skills.single.displayName, 'إغلاق');
    expect(profile?.languages, isNotEmpty);
    expect(profile?.experiences.single.title, 'مندوب');
    final columns = await db.select('PRAGMA table_info(local_worker_records)');
    expect(columns.any((row) => row['name'].toString().contains('token')), isFalse);
  });

  test('logout clears worker rows and keeps the schema upgrade path', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = WorkerLocalStore(db);
    final inbox = InboxLocalStore(db);
    await inbox.upsertConversations(1, const [
      ConversationModel(id: 1, channel: 'whatsapp', status: 'open'),
    ]);
    await store.put(3, 'payouts', [
      {'id': 1, 'status': 'paid', 'amount_minor': 1000, 'currency': 'SAR'},
    ]);
    await inbox.clearAll();
    expect(await store.get(3, 'payouts'), isNull);
    expect(await inbox.conversationCount(), 0);
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
