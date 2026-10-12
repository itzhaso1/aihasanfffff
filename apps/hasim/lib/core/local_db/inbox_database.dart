import 'package:drift/drift.dart';
import 'inbox_database_platform_io.dart'
    if (dart.library.js_interop) 'inbox_database_platform_web.dart'
    as platform;

/// Drift/SQLite inbox database. Schema is applied through [QueryExecutorUser]
/// so we do not depend on drift_dev (blocked by this app's analyzer/SDK set).
class InboxDatabase implements QueryExecutorUser {
  InboxDatabase(this.executor);

  final QueryExecutor executor;
  bool _opened = false;

  /// v1: core tables. v2: `inbox_messages.updated_at`.
  /// v3: conversation local-read / server unread columns.
  /// v4: account/workspace snapshots and inbox read-model columns.
  /// v5: catalog, contacts, and email read models.
  /// v6: stories and notifications.
  /// v7: independent worker read models.
  /// v8: channel and company settings read models.
  @override
  int get schemaVersion => 8;

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
      if (from < 4) {
        await _upgradeV3ToV4(e);
      }
      if (from < 5) {
        await _createDomainTables(e);
      }
      if (from < 6) {
        await _createFeedTables(e);
      }
      if (from < 7) {
        await _createWorkerTables(e);
      }
      if (from < 8) {
        await _createSettingsTables(e);
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
        customer_priority TEXT NOT NULL DEFAULT 'normal',
        payment_status TEXT NOT NULL DEFAULT 'unpaid',
        review_status TEXT NOT NULL DEFAULT 'no_review_needed',
        classification_source TEXT NOT NULL DEFAULT 'system',
        handoff_status TEXT NOT NULL DEFAULT 'none',
        handoff_reason TEXT,
        assigned_user_id INTEGER,
        assigned_user_name TEXT,
        assigned_worker_engagement_id INTEGER,
        assigned_participant_type TEXT,
        can_reply INTEGER NOT NULL DEFAULT 1,
        sync_state TEXT NOT NULL DEFAULT 'synced',
        deleted_at INTEGER,
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
        worker_engagement_id INTEGER,
        sender_user_id INTEGER,
        participant_type TEXT,
        sync_state TEXT NOT NULL DEFAULT 'synced',
        client_created_at INTEGER,
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
    await _createAccountTables(e);
    await _createDomainTables(e);
    await _createFeedTables(e);
    await _createWorkerTables(e);
    await _createSettingsTables(e);
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

  Future<void> _upgradeV3ToV4(QueryExecutor e) async {
    await _ensureColumn(e, 'inbox_conversations', 'customer_priority', "customer_priority TEXT NOT NULL DEFAULT 'normal'");
    await _ensureColumn(e, 'inbox_conversations', 'payment_status', "payment_status TEXT NOT NULL DEFAULT 'unpaid'");
    await _ensureColumn(e, 'inbox_conversations', 'review_status', "review_status TEXT NOT NULL DEFAULT 'no_review_needed'");
    await _ensureColumn(e, 'inbox_conversations', 'classification_source', "classification_source TEXT NOT NULL DEFAULT 'system'");
    await _ensureColumn(e, 'inbox_conversations', 'handoff_status', "handoff_status TEXT NOT NULL DEFAULT 'none'");
    await _ensureColumn(e, 'inbox_conversations', 'handoff_reason', 'handoff_reason TEXT');
    await _ensureColumn(e, 'inbox_conversations', 'assigned_user_id', 'assigned_user_id INTEGER');
    await _ensureColumn(e, 'inbox_conversations', 'assigned_user_name', 'assigned_user_name TEXT');
    await _ensureColumn(e, 'inbox_conversations', 'assigned_worker_engagement_id', 'assigned_worker_engagement_id INTEGER');
    await _ensureColumn(e, 'inbox_conversations', 'assigned_participant_type', 'assigned_participant_type TEXT');
    await _ensureColumn(e, 'inbox_conversations', 'can_reply', 'can_reply INTEGER NOT NULL DEFAULT 1');
    await _ensureColumn(e, 'inbox_conversations', 'sync_state', "sync_state TEXT NOT NULL DEFAULT 'synced'");
    await _ensureColumn(e, 'inbox_conversations', 'deleted_at', 'deleted_at INTEGER');
    await _ensureColumn(e, 'inbox_messages', 'worker_engagement_id', 'worker_engagement_id INTEGER');
    await _ensureColumn(e, 'inbox_messages', 'sender_user_id', 'sender_user_id INTEGER');
    await _ensureColumn(e, 'inbox_messages', 'participant_type', 'participant_type TEXT');
    await _ensureColumn(e, 'inbox_messages', 'sync_state', "sync_state TEXT NOT NULL DEFAULT 'synced'");
    await _ensureColumn(e, 'inbox_messages', 'client_created_at', 'client_created_at INTEGER');
    await _createAccountTables(e);
  }

  Future<void> _createDomainTables(QueryExecutor e) async {
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_catalog_items (
        workspace_id INTEGER NOT NULL,
        server_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        slug TEXT,
        description TEXT,
        sku TEXT,
        item_kind TEXT NOT NULL DEFAULT 'product',
        tangibility TEXT NOT NULL DEFAULT 'tangible',
        price_text TEXT NOT NULL DEFAULT '0',
        worker_min_text TEXT,
        worker_max_text TEXT,
        currency TEXT NOT NULL DEFAULT 'SAR',
        stock INTEGER,
        inventory_policy TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        image_url TEXT,
        category_server_id INTEGER,
        updated_at INTEGER,
        cached_at INTEGER NOT NULL,
        deleted_at INTEGER,
        PRIMARY KEY (workspace_id, server_id)
      )
    ''');
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_catalog_variants (
        workspace_id INTEGER NOT NULL,
        server_id INTEGER NOT NULL,
        product_server_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        sku TEXT,
        price_text TEXT,
        currency TEXT,
        stock INTEGER,
        inventory_policy TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        is_visible INTEGER NOT NULL DEFAULT 1,
        status TEXT,
        sort_order INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (workspace_id, server_id)
      )
    ''');
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_catalog_categories (
        workspace_id INTEGER NOT NULL,
        server_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        slug TEXT,
        description TEXT,
        parent_server_id INTEGER,
        status TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        is_visible INTEGER NOT NULL DEFAULT 1,
        is_featured INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (workspace_id, server_id)
      )
    ''');
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_digital_pools (
        workspace_id INTEGER NOT NULL,
        server_id INTEGER NOT NULL,
        payload_json TEXT NOT NULL,
        PRIMARY KEY (workspace_id, server_id)
      )
    ''');
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_digital_units (
        workspace_id INTEGER NOT NULL,
        server_id INTEGER NOT NULL,
        pool_server_id INTEGER NOT NULL,
        unit_type TEXT,
        status TEXT,
        expires_at TEXT,
        label TEXT,
        PRIMARY KEY (workspace_id, server_id)
      )
    ''');
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_contacts (
        workspace_id INTEGER NOT NULL,
        server_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        normalized_email TEXT,
        phone TEXT,
        company TEXT,
        job_title TEXT,
        notes TEXT,
        is_favorite INTEGER NOT NULL DEFAULT 0,
        avatar_url TEXT,
        created_at INTEGER,
        updated_at INTEGER,
        search_text TEXT NOT NULL DEFAULT '',
        deleted_at INTEGER,
        PRIMARY KEY (workspace_id, server_id)
      )
    ''');
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_contact_groups (
        workspace_id INTEGER NOT NULL,
        server_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        description TEXT,
        contacts_count INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (workspace_id, server_id)
      )
    ''');
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_contact_group_members (
        workspace_id INTEGER NOT NULL,
        group_server_id INTEGER NOT NULL,
        contact_server_id INTEGER NOT NULL,
        PRIMARY KEY (workspace_id, group_server_id, contact_server_id)
      )
    ''');
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_emails (
        workspace_id INTEGER NOT NULL,
        server_id INTEGER NOT NULL,
        folder TEXT NOT NULL,
        sender TEXT NOT NULL,
        recipient TEXT NOT NULL,
        subject TEXT,
        type TEXT,
        preview TEXT,
        body TEXT,
        is_read INTEGER NOT NULL DEFAULT 1,
        is_starred INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER,
        email_account_id INTEGER,
        PRIMARY KEY (workspace_id, server_id)
      )
    ''');
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_email_drafts (
        workspace_id INTEGER PRIMARY KEY,
        recipients TEXT NOT NULL,
        subject TEXT NOT NULL,
        body TEXT NOT NULL,
        updated_at INTEGER NOT NULL
      )
    ''');
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_email_campaigns (
        workspace_id INTEGER NOT NULL,
        server_id INTEGER NOT NULL,
        email_account_id INTEGER,
        subject TEXT,
        status TEXT NOT NULL,
        recipient_count INTEGER NOT NULL DEFAULT 0,
        sent_count INTEGER NOT NULL DEFAULT 0,
        failed_count INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER,
        account_name TEXT,
        account_email TEXT,
        PRIMARY KEY (workspace_id, server_id)
      )
    ''');
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_local_catalog_items_ws_name '
      'ON local_catalog_items (workspace_id, name)',
    );
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_local_catalog_variants_product '
      'ON local_catalog_variants (workspace_id, product_server_id)',
    );
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_local_catalog_categories_parent '
      'ON local_catalog_categories (workspace_id, parent_server_id)',
    );
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_local_contacts_ws_name '
      'ON local_contacts (workspace_id, name)',
    );
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_local_emails_folder '
      'ON local_emails (workspace_id, folder, created_at)',
    );
  }

  Future<void> _createFeedTables(QueryExecutor e) async {
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_stories (
        workspace_id INTEGER NOT NULL,
        server_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        caption TEXT,
        body_text TEXT,
        background_color TEXT,
        media_url TEXT,
        media_mime TEXT,
        media_size INTEGER,
        thumbnail_url TEXT,
        visibility TEXT,
        expires_at INTEGER,
        views_count INTEGER NOT NULL DEFAULT 0,
        status TEXT,
        created_at INTEGER,
        author_id INTEGER,
        author_name TEXT,
        author_avatar TEXT,
        is_mine INTEGER NOT NULL DEFAULT 0,
        viewed_by_me INTEGER NOT NULL DEFAULT 0,
        deleted_at INTEGER,
        PRIMARY KEY (workspace_id, server_id)
      )
    ''');
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_story_viewers (
        workspace_id INTEGER NOT NULL,
        story_server_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        avatar_url TEXT,
        viewed_at INTEGER,
        PRIMARY KEY (workspace_id, story_server_id, user_id)
      )
    ''');
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_notifications (
        workspace_id INTEGER NOT NULL,
        server_id TEXT NOT NULL,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        data_json TEXT NOT NULL DEFAULT '{}',
        read_at INTEGER,
        created_at INTEGER,
        PRIMARY KEY (workspace_id, server_id)
      )
    ''');
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_notification_preferences (
        workspace_id INTEGER PRIMARY KEY,
        messages INTEGER NOT NULL DEFAULT 1,
        email INTEGER NOT NULL DEFAULT 1,
        marketing INTEGER NOT NULL DEFAULT 0,
        cached_at INTEGER NOT NULL
      )
    ''');
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_local_stories_ws_created '
      'ON local_stories (workspace_id, created_at)',
    );
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_local_stories_ws_expires '
      'ON local_stories (workspace_id, expires_at)',
    );
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_local_notifications_ws_created '
      'ON local_notifications (workspace_id, created_at)',
    );
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_local_notifications_ws_read '
      'ON local_notifications (workspace_id, read_at)',
    );
  }

  Future<void> _createWorkerTables(QueryExecutor e) async {
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_worker_records (
        worker_profile_id INTEGER NOT NULL,
        resource TEXT NOT NULL,
        scope_id INTEGER NOT NULL DEFAULT 0,
        payload_json TEXT NOT NULL,
        cached_at INTEGER NOT NULL,
        PRIMARY KEY (worker_profile_id, resource, scope_id)
      )
    ''');
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_local_worker_records_worker '
      'ON local_worker_records (worker_profile_id, resource)',
    );
  }

  Future<void> _createSettingsTables(QueryExecutor e) async {
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_company_cache (
        workspace_id INTEGER NOT NULL,
        resource TEXT NOT NULL,
        payload_json TEXT NOT NULL,
        cached_at INTEGER NOT NULL,
        PRIMARY KEY (workspace_id, resource)
      )
    ''');
  }

  Future<void> _ensureColumn(
    QueryExecutor e,
    String table,
    String name,
    String definition,
  ) async {
    final rows = await e.runSelect('PRAGMA table_info($table)', const []);
    final exists = rows.any((row) => row['name']?.toString() == name);
    if (!exists) {
      await e.runCustom('ALTER TABLE $table ADD COLUMN $definition');
    }
  }

  Future<void> _createAccountTables(QueryExecutor e) async {
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_account (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        server_user_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        email TEXT,
        phone TEXT,
        avatar_url TEXT,
        locale TEXT,
        timezone TEXT,
        participant_type TEXT NOT NULL,
        worker_profile_id INTEGER,
        current_workspace_server_id INTEGER,
        cached_at INTEGER NOT NULL
      )
    ''');
    await e.runCustom('''
      CREATE TABLE IF NOT EXISTS local_workspaces (
        server_id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        type TEXT,
        slug TEXT,
        role TEXT,
        is_owner INTEGER NOT NULL DEFAULT 0,
        cached_at INTEGER NOT NULL
      )
    ''');
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
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_inbox_conversations_ws_archived_recent '
      'ON inbox_conversations (workspace_id, archived, last_message_at)',
    );
    await e.runCustom(
      'CREATE INDEX IF NOT EXISTS idx_inbox_conversations_ws_unread '
      'ON inbox_conversations (workspace_id, unread_count)',
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
  static InboxDatabase open() => InboxDatabase(platform.openExecutor());

  static InboxDatabase memory() => InboxDatabase(platform.memoryExecutor());

  static InboxDatabase file(Object file) =>
      InboxDatabase(platform.fileExecutor(file));
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
