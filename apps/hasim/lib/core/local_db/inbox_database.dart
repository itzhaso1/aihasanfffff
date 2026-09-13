import 'dart:io';

import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

/// Drift/SQLite inbox database. Schema is applied through [QueryExecutorUser]
/// so we do not depend on drift_dev (blocked by this app's analyzer/SDK set).
class InboxDatabase implements QueryExecutorUser {
  InboxDatabase(this.executor);

  final QueryExecutor executor;
  bool _opened = false;

  /// v1: core tables. v2: `inbox_messages.updated_at`.
  /// v3: conversation local-read / server unread columns.
  @override
  int get schemaVersion => 3;

  Future<void> ensureReady() async {
    if (_opened) return;
    await executor.ensureOpen(this);
    _opened = true;
  }

  @override
  Future<void> beforeOpen(QueryExecutor e, OpeningDetails details) async {
    await e.ensureOpen(this);
    await e.runCustom('PRAGMA foreign_keys = ON');
    if (details.wasCreated) {
      await _createCurrentSchema(e);
      return;
    }
    if (details.hadUpgrade) {
      final from = details.versionBefore ?? 0;
      if (from < 2) {
        await _upgradeV1ToV2(e);
      }
      if (from < 3) {
        await _upgradeV2ToV3(e);
      }
    }
    await _createIndexes(e);
  }

  Future<void> _createCurrentSchema(QueryExecutor e) async {
    await e.runCustom('''
      CREATE TABLE inbox_conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workspace_id INTEGER NOT NULL,
        server_id INTEGER NOT NULL,
        channel TEXT NOT NULL,
        status TEXT NOT NULL,
        external_id TEXT,
        ai_enabled INTEGER NOT NULL DEFAULT 0,
        last_message_at INTEGER,
        unread_count INTEGER NOT NULL DEFAULT 0,
        server_unread_count INTEGER NOT NULL DEFAULT 0,
        local_read_at INTEGER,
        local_read_last_message_at INTEGER,
        muted INTEGER NOT NULL DEFAULT 0,
        archived INTEGER NOT NULL DEFAULT 0,
        customer_id INTEGER,
        customer_name TEXT,
        customer_phone TEXT,
        customer_email TEXT,
        last_message_preview TEXT,
        last_message_server_id INTEGER,
        updated_at INTEGER NOT NULL,
        cached_at INTEGER NOT NULL
      )
    ''');
    await e.runCustom('''
      CREATE TABLE inbox_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workspace_id INTEGER NOT NULL,
        conversation_server_id INTEGER NOT NULL,
        server_id INTEGER,
        client_id TEXT,
        direction TEXT NOT NULL,
        message_type TEXT NOT NULL,
        content TEXT NOT NULL,
        ai_generated INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER,
        user_name TEXT,
        customer_name TEXT,
        attachments_json TEXT NOT NULL DEFAULT '[]',
        local_state TEXT NOT NULL DEFAULT 'sent',
        cached_at INTEGER NOT NULL,
        updated_at INTEGER
      )
    ''');
    await e.runCustom('''
      CREATE TABLE inbox_outbox (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workspace_id INTEGER NOT NULL,
        client_id TEXT NOT NULL,
        operation_type TEXT NOT NULL,
        conversation_server_id INTEGER NOT NULL,
        payload TEXT NOT NULL,
        state TEXT NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0,
        next_attempt_at INTEGER NOT NULL,
        last_error TEXT,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL
      )
    ''');
    await e.runCustom('''
      CREATE TABLE inbox_sync_state (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workspace_id INTEGER NOT NULL,
        resource TEXT NOT NULL,
        cursor TEXT,
        updated_at INTEGER NOT NULL
      )
    ''');
    await _createIndexes(e);
  }

  Future<void> _upgradeV1ToV2(QueryExecutor e) async {
    final rows = await e.runSelect('PRAGMA table_info(inbox_messages)', const []);
    final hasUpdatedAt = rows.any((row) => row['name'] == 'updated_at');
    if (!hasUpdatedAt) {
      await e.runCustom('ALTER TABLE inbox_messages ADD COLUMN updated_at INTEGER NULL');
    }
  }

  Future<void> _upgradeV2ToV3(QueryExecutor e) async {
    final rows = await e.runSelect('PRAGMA table_info(inbox_conversations)', const []);
    final names = rows.map((row) => row['name']?.toString()).toSet();
    if (!names.contains('server_unread_count')) {
      await e.runCustom(
        'ALTER TABLE inbox_conversations ADD COLUMN server_unread_count INTEGER NOT NULL DEFAULT 0',
      );
    }
    if (!names.contains('local_read_at')) {
      await e.runCustom('ALTER TABLE inbox_conversations ADD COLUMN local_read_at INTEGER NULL');
    }
    if (!names.contains('local_read_last_message_at')) {
      await e.runCustom(
        'ALTER TABLE inbox_conversations ADD COLUMN local_read_last_message_at INTEGER NULL',
      );
    }
  }

  Future<void> _createIndexes(QueryExecutor e) async {
    await e.runCustom(
      'CREATE UNIQUE INDEX IF NOT EXISTS idx_inbox_conversations_ws_server '
      'ON inbox_conversations (workspace_id, server_id)',
    );
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_inbox_conversations_ws_updated '
      'ON inbox_conversations (workspace_id, last_message_at)',
    );
    await e.runCustom(
      'CREATE UNIQUE INDEX IF NOT EXISTS idx_inbox_messages_ws_server '
      'ON inbox_messages (workspace_id, server_id) '
      'WHERE server_id IS NOT NULL',
    );
    await e.runCustom(
      'CREATE UNIQUE INDEX IF NOT EXISTS idx_inbox_messages_ws_client '
      'ON inbox_messages (workspace_id, client_id) '
      'WHERE client_id IS NOT NULL',
    );
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_inbox_messages_ws_conversation_created '
      'ON inbox_messages (workspace_id, conversation_server_id, created_at)',
    );
    await e.runCustom(
      'CREATE UNIQUE INDEX IF NOT EXISTS idx_inbox_outbox_ws_client '
      'ON inbox_outbox (workspace_id, client_id)',
    );
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_inbox_outbox_due '
      'ON inbox_outbox (workspace_id, state, next_attempt_at)',
    );
    await e.runCustom(
      'CREATE UNIQUE INDEX IF NOT EXISTS idx_inbox_sync_ws_resource '
      'ON inbox_sync_state (workspace_id, resource)',
    );
  }

  Future<T> transaction<T>(Future<T> Function(QueryExecutor tx) action) async {
    await ensureReady();
    final tx = executor.beginTransaction();
    try {
      await tx.ensureOpen(this);
      final result = await action(tx);
      await tx.send();
      return result;
    } catch (_) {
      await tx.rollback();
      rethrow;
    }
  }

  Future<List<Map<String, Object?>>> select(
    String sql, [
    List<Object?> args = const [],
  ]) async {
    await ensureReady();
    return executor.runSelect(sql, args);
  }

  Future<int> insert(String sql, [List<Object?> args = const []]) async {
    await ensureReady();
    return executor.runInsert(sql, args);
  }

  Future<int> update(String sql, [List<Object?> args = const []]) async {
    await ensureReady();
    return executor.runUpdate(sql, args);
  }

  Future<int> delete(String sql, [List<Object?> args = const []]) async {
    await ensureReady();
    return executor.runDelete(sql, args);
  }

  Future<void> custom(String sql, [List<Object?> args = const []]) async {
    await ensureReady();
    await executor.runCustom(sql, args);
  }

  Future<void> close() => executor.close();

  /// Application-support directory. Never assets, git, or public storage.
  static InboxDatabase open() {
    return InboxDatabase(
      LazyDatabase(() async {
        final dir = await getApplicationSupportDirectory();
        final file = File(p.join(dir.path, 'hasim_inbox.sqlite'));
        return NativeDatabase.createInBackground(file);
      }),
    );
  }

  static InboxDatabase memory() => InboxDatabase(NativeDatabase.memory());

  static InboxDatabase file(File file) => InboxDatabase(NativeDatabase(file));
}

/// Raw v1 schema for migration tests (no `inbox_messages.updated_at`).
const inboxSchemaV1Sql = [
  '''
  CREATE TABLE inbox_conversations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL,
    server_id INTEGER NOT NULL,
    channel TEXT NOT NULL,
    status TEXT NOT NULL,
    external_id TEXT,
    ai_enabled INTEGER NOT NULL DEFAULT 0,
    last_message_at INTEGER,
    unread_count INTEGER NOT NULL DEFAULT 0,
    muted INTEGER NOT NULL DEFAULT 0,
    archived INTEGER NOT NULL DEFAULT 0,
    customer_id INTEGER,
    customer_name TEXT,
    customer_phone TEXT,
    customer_email TEXT,
    last_message_preview TEXT,
    last_message_server_id INTEGER,
    updated_at INTEGER NOT NULL,
    cached_at INTEGER NOT NULL
  )
  ''',
  '''
  CREATE TABLE inbox_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL,
    conversation_server_id INTEGER NOT NULL,
    server_id INTEGER,
    client_id TEXT,
    direction TEXT NOT NULL,
    message_type TEXT NOT NULL,
    content TEXT NOT NULL,
    ai_generated INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER,
    user_name TEXT,
    customer_name TEXT,
    attachments_json TEXT NOT NULL DEFAULT '[]',
    local_state TEXT NOT NULL DEFAULT 'sent',
    cached_at INTEGER NOT NULL
  )
  ''',
  '''
  CREATE TABLE inbox_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL,
    client_id TEXT NOT NULL,
    operation_type TEXT NOT NULL,
    conversation_server_id INTEGER NOT NULL,
    payload TEXT NOT NULL,
    state TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at INTEGER NOT NULL,
    last_error TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
  )
  ''',
  '''
  CREATE TABLE inbox_sync_state (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL,
    resource TEXT NOT NULL,
    cursor TEXT,
    updated_at INTEGER NOT NULL
  )
  ''',
];
