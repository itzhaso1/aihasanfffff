import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:sqlite3/sqlite3.dart';

void main() {
  test('schema v1 upgrades to v2 without losing conversations', () async {
    final file = File(
      '${Directory.systemTemp.path}/hasim_mig_${DateTime.now().microsecondsSinceEpoch}.sqlite',
    );
    addTearDown(() {
      if (file.existsSync()) file.deleteSync();
    });

    final raw = sqlite3.open(file.path);
    for (final sql in inboxSchemaV1Sql) {
      raw.execute(sql);
    }
    raw.execute(
      'INSERT INTO inbox_conversations ('
      'workspace_id, server_id, channel, status, external_id, ai_enabled, '
      'unread_count, muted, archived, updated_at, cached_at'
      ') VALUES (1, 10, ?, ?, ?, 0, 2, 0, 0, 1, 1)',
      ['whatsapp', 'open', 'Keep Me'],
    );
    raw.execute(
      'INSERT INTO inbox_messages ('
      'workspace_id, conversation_server_id, server_id, direction, message_type, '
      'content, ai_generated, attachments_json, local_state, cached_at'
      ') VALUES (1, 10, 3, ?, ?, ?, 0, ?, ?, 1)',
      ['inbound', 'text', 'old body', '[]', 'sent'],
    );
    raw.execute('PRAGMA user_version = 1');
    raw.dispose();

    final db = InboxDatabase.file(file);
    addTearDown(db.close);
    final store = InboxLocalStore(db);

    expect((await store.getConversations(1)).single.externalId, 'Keep Me');
    expect((await store.getConversations(1)).single.unreadCount, 2);
    expect((await store.getMessages(1, 10)).single.content, 'old body');

    final columns = await db.select('PRAGMA table_info(inbox_messages)');
    expect(columns.any((row) => row['name'] == 'updated_at'), isTrue);
    final conversationColumns = await db.select('PRAGMA table_info(inbox_conversations)');
    expect(conversationColumns.any((row) => row['name'] == 'local_read_at'), isTrue);
    expect(conversationColumns.any((row) => row['name'] == 'server_unread_count'), isTrue);
    expect(conversationColumns.any((row) => row['name'] == 'handoff_status'), isTrue);
    expect(conversationColumns.any((row) => row['name'] == 'assigned_worker_engagement_id'), isTrue);
    final account = await db.select("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'local_account'");
    expect(account, isNotEmpty);
    expect((await store.getMessages(1, 10)).single.content, 'old body');
  });

  test('polling upsert ignores duplicate server messages', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = InboxLocalStore(db);
    const first = MessageModel(
      id: 9,
      conversationId: 4,
      direction: 'inbound',
      messageType: 'text',
      content: 'once',
    );
    await store.upsertMessages(1, 4, const [first]);
    await store.upsertMessages(1, 4, const [first, first]);
    expect(await store.getMessages(1, 4), hasLength(1));
  });
}
