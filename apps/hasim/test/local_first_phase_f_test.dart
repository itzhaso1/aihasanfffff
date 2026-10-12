import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/local_db/settings_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/settings/data/plan_channel_repository.dart';
import 'package:hasim/features/settings/data/session_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late ApiClient api;

  setUp(() async {
    SharedPreferences.setMockInitialValues({'workspace_id': 4});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    api = isolatedTestApiClient(secureStore: _MemorySecure(), prefsStore: prefs);
  });

  test('channel cache drops secrets and stays inside the workspace', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = SettingsLocalStore(db);
    final safe = channelCachePayload({
      'key': 'whatsapp',
      'name': 'واتساب',
      'connected': true,
      'status': 'connected',
      'bot_token': 'secret-value',
      'manage_url': 'https://example.test/channels?token=abc',
    });
    expect(safe.containsKey('bot_token'), isFalse);
    expect(safe.containsKey('manage_url'), isFalse);

    await store.put(4, 'channels', [safe]);
    await store.put(9, 'channels', [
      {'key': 'email', 'name': 'بريد', 'connected': false, 'status': 'disconnected'},
    ]);

    final repoA = ChannelRepository(api, local: store, workspaceIdOf: () => 4);
    final repoB = ChannelRepository(api, local: store, workspaceIdOf: () => 9);
    expect((await repoA.cached()).single.key, 'whatsapp');
    expect((await repoB.cached()).single.key, 'email');
  });

  test('session cache keeps display fields and logout clears the workspace', () async {
    final safe = sessionCachePayload({
      'id': 1,
      'name': 'هاتف',
      'device_type': 'mobile',
      'is_current': true,
      'token': 'secret-token',
      'ip_address': '10.0.0.8',
      'user_agent': 'Hasim',
    });
    expect(safe.containsKey('token'), isFalse);
    expect(safe.containsKey('ip_address'), isFalse);

    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = SettingsLocalStore(db);
    final inbox = InboxLocalStore(db);
    await store.put(4, 'sessions', [safe]);
    await store.put(4, 'channels', [
      {'key': 'whatsapp', 'name': 'واتساب', 'connected': true, 'status': 'connected'},
    ]);
    await inbox.upsertConversations(4, const [
      ConversationModel(id: 3, channel: 'whatsapp', status: 'open'),
    ]);
    final sessions = SessionRepository(api, local: store, workspaceIdOf: () => 4);
    expect((await sessions.cached()).single.name, 'هاتف');
    expect(await SessionRepository(api, local: store, workspaceIdOf: () => 8).cached(), isEmpty);

    await inbox.clearAll();
    expect(await sessions.cached(), isEmpty);
    expect(await ChannelRepository(api, local: store, workspaceIdOf: () => 4).cached(), isEmpty);
    expect(await inbox.getConversations(4), isEmpty);
    expect(await inbox.outboxCount(), 0);
  });

  test('a failed refresh keeps channels and connect does not enter the outbox', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = SettingsLocalStore(db);
    final inbox = InboxLocalStore(db);
    await store.put(4, 'channels', [
      channelCachePayload({
        'key': 'whatsapp',
        'name': 'واتساب',
        'connected': true,
        'status': 'connected',
      }),
    ]);
    final repo = ChannelRepository(api, local: store, workspaceIdOf: () => 4);
    final items = await repo.list();
    expect(repo.lastReadFromCache, isTrue);
    expect(items.single.key, 'whatsapp');

    await expectLater(
      repo.startConnect('telegram', botToken: '123:secret'),
      throwsA(isA<ApiException>()),
    );
    expect(await inbox.outboxCount(), 0);
    expect((await store.get(4, 'channels')).toString().contains('secret'), isFalse);
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
