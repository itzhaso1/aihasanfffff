import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/message_reconciler.dart';
import 'package:hasim/core/models/models.dart';

class MessageLocalState {
  static const pending = 'pending';
  static const sending = 'sending';
  static const sent = 'sent';
  static const failed = 'failed';
}

class OutboxOpState {
  static const pending = 'pending';
  static const sending = 'sending';
  static const failed = 'failed';
}

class OutboxOpType {
  static const sendText = 'send_text';
}

class OutboxRecord {
  const OutboxRecord({
    required this.id,
    required this.workspaceId,
    required this.clientId,
    required this.operationType,
    required this.conversationServerId,
    required this.payload,
    required this.state,
    required this.attempts,
    required this.nextAttemptAt,
    this.lastError,
  });

  final int id;
  final int workspaceId;
  final String clientId;
  final String operationType;
  final int conversationServerId;
  final String payload;
  final String state;
  final int attempts;
  final DateTime nextAttemptAt;
  final String? lastError;

  String get content {
    try {
      final map = jsonDecode(payload);
      if (map is Map) return map['content']?.toString() ?? '';
    } catch (_) {}
    return '';
  }
}

/// Workspace-scoped Drift access. Every query takes [workspaceId] explicitly.
class InboxLocalStore {
  InboxLocalStore(this._db);

  final InboxDatabase _db;

  static const _messagePageLimit = 300;

  Future<List<ConversationModel>> getConversations(
    int workspaceId, {
    String filter = 'all',
    String? channel,
    String? search,
  }) async {
    final rows = await _db.select(
      'SELECT * FROM inbox_conversations WHERE workspace_id = ? '
      'ORDER BY last_message_at IS NULL, last_message_at DESC, server_id DESC',
      [workspaceId],
    );
    var items = rows.map(_conversationFromRow).toList();
    if (filter == 'unread') {
      items = items.where((c) => c.unreadCount > 0).toList();
    } else if (filter == 'archived') {
      items = items.where((c) => c.archived).toList();
    } else {
      items = items.where((c) => !c.archived).toList();
    }
    if (channel != null && channel.isNotEmpty) {
      items = items.where((c) => c.channel == channel).toList();
    }
    final q = search?.trim().toLowerCase() ?? '';
    if (q.isNotEmpty) {
      items = items.where((c) {
        return c.title.toLowerCase().contains(q) || c.preview.toLowerCase().contains(q);
      }).toList();
    }
    return items;
  }

  Future<ConversationModel?> getConversation(int workspaceId, int serverId) async {
    final rows = await _db.select(
      'SELECT * FROM inbox_conversations WHERE workspace_id = ? AND server_id = ? LIMIT 1',
      [workspaceId, serverId],
    );
    if (rows.isEmpty) return null;
    return _conversationFromRow(rows.first);
  }

  Future<void> upsertConversations(int workspaceId, List<ConversationModel> items) async {
    final now = DateTime.now();
    for (final item in items) {
      await _upsertConversation(workspaceId, item, now);
    }
  }

  Future<void> _upsertConversation(int workspaceId, ConversationModel item, DateTime now) async {
    final existingRows = await _db.select(
      'SELECT * FROM inbox_conversations WHERE workspace_id = ? AND server_id = ? LIMIT 1',
      [workspaceId, item.id],
    );
    final existing = existingRows.isEmpty ? null : existingRows.first;
    final incomingLast = _ms(item.lastMessageAt);
    var displayUnread = item.unreadCount;
    Object? localReadAt = existing?['local_read_at'];
    Object? localReadLast = existing?['local_read_last_message_at'];

    if (existing != null && localReadAt != null) {
      final readUntil = (localReadLast as num?)?.toInt();
      final incomingIsNotNewer = incomingLast == null || readUntil == null || incomingLast <= readUntil;
      if (incomingIsNotNewer) {
        displayUnread = 0;
      } else {
        localReadAt = null;
        localReadLast = null;
      }
    }

    await _db.custom(
      '''
      INSERT INTO inbox_conversations (
        workspace_id, server_id, channel, status, external_id, ai_enabled,
        last_message_at, unread_count, server_unread_count, local_read_at,
        local_read_last_message_at, muted, archived, customer_id, customer_name,
        customer_phone, customer_email, last_message_preview, last_message_server_id,
        updated_at, cached_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON CONFLICT(workspace_id, server_id) DO UPDATE SET
        channel = excluded.channel,
        status = excluded.status,
        external_id = excluded.external_id,
        ai_enabled = excluded.ai_enabled,
        last_message_at = excluded.last_message_at,
        unread_count = excluded.unread_count,
        server_unread_count = excluded.server_unread_count,
        local_read_at = excluded.local_read_at,
        local_read_last_message_at = excluded.local_read_last_message_at,
        muted = excluded.muted,
        archived = excluded.archived,
        customer_id = excluded.customer_id,
        customer_name = excluded.customer_name,
        customer_phone = excluded.customer_phone,
        customer_email = excluded.customer_email,
        last_message_preview = excluded.last_message_preview,
        last_message_server_id = excluded.last_message_server_id,
        updated_at = excluded.updated_at,
        cached_at = excluded.cached_at
      ''',
      [
        workspaceId,
        item.id,
        item.channel,
        item.status,
        item.externalId,
        _bool(item.aiEnabled),
        incomingLast,
        displayUnread,
        item.unreadCount,
        localReadAt,
        localReadLast,
        _bool(item.muted),
        _bool(item.archived),
        item.customer?.id,
        item.customer?.name,
        item.customer?.phone,
        item.customer?.email,
        item.lastMessage?.content,
        item.lastMessage?.id,
        _ms(item.lastMessageAt ?? now),
        _ms(now),
      ],
    );
  }

  Future<List<MessageModel>> getMessages(
    int workspaceId,
    int conversationId, {
    int limit = _messagePageLimit,
  }) async {
    final rows = await _db.select(
      'SELECT * FROM inbox_messages '
      'WHERE workspace_id = ? AND conversation_server_id = ? '
      'ORDER BY created_at IS NULL, created_at ASC, id ASC '
      'LIMIT ?',
      [workspaceId, conversationId, limit],
    );
    return rows.map(_messageFromRow).toList();
  }

  Future<MessageModel?> getMessageByClientId(int workspaceId, String clientId) async {
    final rows = await _db.select(
      'SELECT * FROM inbox_messages WHERE workspace_id = ? AND client_id = ? LIMIT 1',
      [workspaceId, clientId],
    );
    if (rows.isEmpty) return null;
    return _messageFromRow(rows.first);
  }

  Future<void> upsertMessages(
    int workspaceId,
    int conversationId,
    List<MessageModel> items,
  ) async {
    final now = DateTime.now();
    for (final item in items) {
      await _upsertMessage(workspaceId, conversationId, item, now);
    }
  }

  Future<void> _upsertMessage(
    int workspaceId,
    int conversationId,
    MessageModel item,
    DateTime now, {
    QueryExecutor? tx,
  }) async {
    final run = tx == null
        ? _db.custom
        : (String sql, [List<Object?> args = const []]) => tx.runCustom(sql, args);
    final select = tx == null
        ? _db.select
        : (String sql, [List<Object?> args = const []]) => tx.runSelect(sql, args);

    Map<String, Object?>? existing;
    if (item.clientId != null && item.clientId!.isNotEmpty) {
      final rows = await select(
        'SELECT * FROM inbox_messages WHERE workspace_id = ? AND client_id = ? LIMIT 1',
        [workspaceId, item.clientId],
      );
      if (rows.isNotEmpty) existing = rows.first;
    }
    if (existing == null && item.id > 0) {
      final rows = await select(
        'SELECT * FROM inbox_messages WHERE workspace_id = ? AND server_id = ? LIMIT 1',
        [workspaceId, item.id],
      );
      if (rows.isNotEmpty) existing = rows.first;
    }

    final serverId = item.id > 0 ? item.id : existing?['server_id'];
    final localState = MessageReconciler.localStateFor(
      existingState: existing?['local_state']?.toString(),
      incoming: item,
    );
    final attachments = jsonEncode(
      item.attachments
          .map(
            (a) => {
              'id': a.id,
              'kind': a.kind,
              'original_name': a.originalName,
              'mime_type': a.mimeType,
              'size_bytes': a.sizeBytes,
              'duration_ms': a.durationMs,
              'status': a.status,
              'download_url': a.downloadUrl,
              'preview_url': a.previewUrl,
            },
          )
          .toList(),
    );

    if (existing != null) {
      await run(
        '''
        UPDATE inbox_messages SET
          conversation_server_id = ?,
          server_id = COALESCE(?, server_id),
          client_id = COALESCE(?, client_id),
          direction = ?,
          message_type = ?,
          content = ?,
          ai_generated = ?,
          created_at = COALESCE(?, created_at),
          user_name = COALESCE(?, user_name),
          customer_name = COALESCE(?, customer_name),
          attachments_json = ?,
          local_state = ?,
          cached_at = ?,
          updated_at = ?
        WHERE id = ?
        ''',
        [
          conversationId,
          serverId,
          item.clientId,
          item.direction,
          item.messageType,
          item.content,
          _bool(item.aiGenerated),
          _ms(item.createdAt),
          item.userName,
          item.customerName,
          attachments,
          localState,
          _ms(now),
          _ms(now),
          existing['id'],
        ],
      );
      return;
    }

    await run(
      '''
      INSERT INTO inbox_messages (
        workspace_id, conversation_server_id, server_id, client_id, direction,
        message_type, content, ai_generated, created_at, user_name, customer_name,
        attachments_json, local_state, cached_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ''',
      [
        workspaceId,
        conversationId,
        serverId,
        item.clientId,
        item.direction,
        item.messageType,
        item.content,
        _bool(item.aiGenerated),
        _ms(item.createdAt),
        item.userName,
        item.customerName,
        attachments,
        localState,
        _ms(now),
        _ms(now),
      ],
    );
  }

  /// Atomic optimistic message + outbox insert.
  Future<void> enqueueTextSend({
    required int workspaceId,
    required int conversationId,
    required MessageModel message,
  }) async {
    final clientId = message.clientId;
    if (clientId == null || clientId.isEmpty) {
      throw ArgumentError('clientId is required for outbox sends');
    }
    final now = DateTime.now();
    await _db.transaction((tx) async {
      await _upsertMessage(workspaceId, conversationId, message, now, tx: tx);
      await tx.runCustom(
        '''
        INSERT OR IGNORE INTO inbox_outbox (
          workspace_id, client_id, operation_type, conversation_server_id,
          payload, state, attempts, next_attempt_at, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)
        ''',
        [
          workspaceId,
          clientId,
          OutboxOpType.sendText,
          conversationId,
          jsonEncode({
            'content': message.content,
            'message_type': message.messageType,
          }),
          OutboxOpState.pending,
          _ms(now),
          _ms(now),
          _ms(now),
        ],
      );
    });
  }

  Future<List<OutboxRecord>> dueOutbox({
    int? workspaceId,
    DateTime? now,
  }) async {
    final due = _ms(now ?? DateTime.now());
    final rows = workspaceId == null
        ? await _db.select(
            "SELECT * FROM inbox_outbox WHERE state = ? AND next_attempt_at <= ? ORDER BY id ASC",
            [OutboxOpState.pending, due],
          )
        : await _db.select(
            "SELECT * FROM inbox_outbox WHERE workspace_id = ? AND state = ? AND next_attempt_at <= ? ORDER BY id ASC",
            [workspaceId, OutboxOpState.pending, due],
          );
    return rows.map(_outboxFromRow).toList();
  }

  Future<OutboxRecord?> outboxByClientId(int workspaceId, String clientId) async {
    final rows = await _db.select(
      'SELECT * FROM inbox_outbox WHERE workspace_id = ? AND client_id = ? LIMIT 1',
      [workspaceId, clientId],
    );
    if (rows.isEmpty) return null;
    return _outboxFromRow(rows.first);
  }

  Future<bool> claimOutbox(int id) async {
    final due = _ms(DateTime.now());
    final changed = await _db.update(
      "UPDATE inbox_outbox SET state = ?, updated_at = ? "
      "WHERE id = ? AND state = ? AND next_attempt_at <= ?",
      [OutboxOpState.sending, due, id, OutboxOpState.pending, due],
    );
    return changed > 0;
  }

  Future<void> completeOutbox({
    required int workspaceId,
    required String clientId,
    required MessageModel server,
  }) async {
    final now = DateTime.now();
    await _db.transaction((tx) async {
      if (server.id > 0) {
        await tx.runCustom(
          'DELETE FROM inbox_messages WHERE workspace_id = ? AND server_id = ? '
          'AND (client_id IS NULL OR client_id != ?)',
          [workspaceId, server.id, clientId],
        );
      }
      await _upsertMessage(
        workspaceId,
        server.conversationId,
        server.copyWith(localPending: false, localFailed: false, clientId: clientId),
        now,
        tx: tx,
      );
      await tx.runCustom(
        'DELETE FROM inbox_outbox WHERE workspace_id = ? AND client_id = ?',
        [workspaceId, clientId],
      );
    });
  }

  Future<void> failOutbox({
    required int id,
    required int workspaceId,
    required String clientId,
    required String error,
    required bool permanent,
    required DateTime nextAttempt,
    required int attempts,
  }) async {
    final now = DateTime.now();
    await _db.transaction((tx) async {
      await tx.runCustom(
        'UPDATE inbox_outbox SET state = ?, attempts = ?, next_attempt_at = ?, '
        'last_error = ?, updated_at = ? WHERE id = ?',
        [
          permanent ? OutboxOpState.failed : OutboxOpState.pending,
          attempts,
          _ms(nextAttempt),
          error,
          _ms(now),
          id,
        ],
      );
      await tx.runCustom(
        'UPDATE inbox_messages SET local_state = ?, cached_at = ?, updated_at = ? '
        'WHERE workspace_id = ? AND client_id = ?',
        [
          permanent ? MessageLocalState.failed : MessageLocalState.pending,
          _ms(now),
          _ms(now),
          workspaceId,
          clientId,
        ],
      );
    });
  }

  Future<void> requeueFailed({
    required int workspaceId,
    required String clientId,
  }) async {
    final now = DateTime.now();
    await _db.transaction((tx) async {
      await tx.runCustom(
        "UPDATE inbox_outbox SET state = ?, attempts = 0, next_attempt_at = ?, "
        "last_error = NULL, updated_at = ? WHERE workspace_id = ? AND client_id = ?",
        [OutboxOpState.pending, _ms(now), _ms(now), workspaceId, clientId],
      );
      await tx.runCustom(
        'UPDATE inbox_messages SET local_state = ?, cached_at = ?, updated_at = ? '
        'WHERE workspace_id = ? AND client_id = ?',
        [MessageLocalState.pending, _ms(now), _ms(now), workspaceId, clientId],
      );
    });
  }

  Future<void> markConversationRead(int workspaceId, int conversationId) async {
    final now = DateTime.now();
    await _db.update(
      '''
      UPDATE inbox_conversations SET
        unread_count = 0,
        local_read_at = ?,
        local_read_last_message_at = COALESCE(last_message_at, ?),
        cached_at = ?,
        updated_at = ?
      WHERE workspace_id = ? AND server_id = ?
      ''',
      [_ms(now), _ms(now), _ms(now), _ms(now), workspaceId, conversationId],
    );
  }

  Future<int> staleServerUnreadClearedCount(int workspaceId) async {
    final rows = await _db.select(
      'SELECT COUNT(*) AS c FROM inbox_conversations '
      'WHERE workspace_id = ? AND archived = 0 AND unread_count = 0 '
      'AND server_unread_count > 0 AND local_read_at IS NOT NULL',
      [workspaceId],
    );
    return (rows.first['c'] as num?)?.toInt() ?? 0;
  }

  Future<int> unreadConversationCount(int workspaceId) async {
    final rows = await _db.select(
      'SELECT COUNT(*) AS c FROM inbox_conversations '
      'WHERE workspace_id = ? AND archived = 0 AND unread_count > 0',
      [workspaceId],
    );
    return (rows.first['c'] as num?)?.toInt() ?? 0;
  }

  Future<void> reclaimSendingOutbox() async {
    final now = _ms(DateTime.now());
    await _db.update(
      'UPDATE inbox_outbox SET state = ?, next_attempt_at = ?, updated_at = ? WHERE state = ?',
      [OutboxOpState.pending, now, now, OutboxOpState.sending],
    );
  }

  Future<void> markMessageSending(int workspaceId, String clientId) async {
    final now = DateTime.now();
    await _db.update(
      'UPDATE inbox_messages SET local_state = ?, cached_at = ?, updated_at = ? '
      'WHERE workspace_id = ? AND client_id = ?',
      [MessageLocalState.sending, _ms(now), _ms(now), workspaceId, clientId],
    );
  }

  Future<void> setSyncCursor(int workspaceId, String resource, String? cursor) async {
    final now = _ms(DateTime.now());
    await _db.custom(
      '''
      INSERT INTO inbox_sync_state (workspace_id, resource, cursor, updated_at)
      VALUES (?, ?, ?, ?)
      ON CONFLICT(workspace_id, resource) DO UPDATE SET
        cursor = excluded.cursor,
        updated_at = excluded.updated_at
      ''',
      [workspaceId, resource, cursor, now],
    );
  }

  Future<String?> getSyncCursor(int workspaceId, String resource) async {
    final rows = await _db.select(
      'SELECT cursor FROM inbox_sync_state WHERE workspace_id = ? AND resource = ? LIMIT 1',
      [workspaceId, resource],
    );
    if (rows.isEmpty) return null;
    return rows.first['cursor']?.toString();
  }

  Future<void> clearWorkspace(int workspaceId) async {
    await _db.transaction((tx) async {
      await tx.runCustom('DELETE FROM inbox_messages WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM inbox_conversations WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM inbox_outbox WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM inbox_sync_state WHERE workspace_id = ?', [workspaceId]);
    });
  }

  Future<void> clearAll() async {
    await _db.transaction((tx) async {
      await tx.runCustom('DELETE FROM inbox_messages');
      await tx.runCustom('DELETE FROM inbox_conversations');
      await tx.runCustom('DELETE FROM inbox_outbox');
      await tx.runCustom('DELETE FROM inbox_sync_state');
    });
  }

  Future<int> conversationCount([int? workspaceId]) async {
    final rows = workspaceId == null
        ? await _db.select('SELECT COUNT(*) AS c FROM inbox_conversations')
        : await _db.select(
            'SELECT COUNT(*) AS c FROM inbox_conversations WHERE workspace_id = ?',
            [workspaceId],
          );
    return (rows.first['c'] as num?)?.toInt() ?? 0;
  }

  Future<int> messageCount([int? workspaceId]) async {
    final rows = workspaceId == null
        ? await _db.select('SELECT COUNT(*) AS c FROM inbox_messages')
        : await _db.select(
            'SELECT COUNT(*) AS c FROM inbox_messages WHERE workspace_id = ?',
            [workspaceId],
          );
    return (rows.first['c'] as num?)?.toInt() ?? 0;
  }

  Future<int> outboxCount([int? workspaceId]) async {
    final rows = workspaceId == null
        ? await _db.select('SELECT COUNT(*) AS c FROM inbox_outbox')
        : await _db.select(
            'SELECT COUNT(*) AS c FROM inbox_outbox WHERE workspace_id = ?',
            [workspaceId],
          );
    return (rows.first['c'] as num?)?.toInt() ?? 0;
  }

  ConversationModel _conversationFromRow(Map<String, Object?> row) {
    CustomerModel? customer;
    final customerId = (row['customer_id'] as num?)?.toInt();
    if (customerId != null) {
      customer = CustomerModel(
        id: customerId,
        name: row['customer_name']?.toString() ?? '',
        phone: row['customer_phone']?.toString(),
        email: row['customer_email']?.toString(),
      );
    }
    final preview = row['last_message_preview']?.toString();
    return ConversationModel(
      id: (row['server_id'] as num).toInt(),
      channel: row['channel']?.toString() ?? 'web',
      status: row['status']?.toString() ?? 'open',
      externalId: row['external_id']?.toString(),
      aiEnabled: _asBool(row['ai_enabled']),
      lastMessageAt: _fromMs(row['last_message_at']),
      customer: customer,
      unreadCount: (row['unread_count'] as num?)?.toInt() ?? 0,
      lastMessage: preview == null || preview.isEmpty
          ? null
          : MessageModel(
              id: (row['last_message_server_id'] as num?)?.toInt() ?? 0,
              conversationId: (row['server_id'] as num).toInt(),
              direction: 'inbound',
              messageType: 'text',
              content: preview,
            ),
      muted: _asBool(row['muted']),
      archived: _asBool(row['archived']),
    );
  }

  MessageModel _messageFromRow(Map<String, Object?> row) {
    final localId = (row['id'] as num).toInt();
    final serverId = (row['server_id'] as num?)?.toInt();
    final state = row['local_state']?.toString() ?? MessageLocalState.sent;
    final attachments = <MessageAttachmentModel>[];
    final raw = row['attachments_json']?.toString();
    if (raw != null && raw.isNotEmpty) {
      try {
        final list = jsonDecode(raw);
        if (list is List) {
          for (final item in list) {
            if (item is Map) {
              attachments.add(MessageAttachmentModel.fromJson(Map<String, dynamic>.from(item)));
            }
          }
        }
      } catch (_) {}
    }
    return MessageModel(
      id: serverId ?? -localId,
      conversationId: (row['conversation_server_id'] as num).toInt(),
      direction: row['direction']?.toString() ?? 'inbound',
      messageType: row['message_type']?.toString() ?? 'text',
      content: row['content']?.toString() ?? '',
      aiGenerated: _asBool(row['ai_generated']),
      createdAt: _fromMs(row['created_at']),
      userName: row['user_name']?.toString(),
      customerName: row['customer_name']?.toString(),
      attachments: attachments,
      localPending: state == MessageLocalState.pending || state == MessageLocalState.sending,
      localFailed: state == MessageLocalState.failed,
      clientId: row['client_id']?.toString(),
    );
  }

  OutboxRecord _outboxFromRow(Map<String, Object?> row) {
    return OutboxRecord(
      id: (row['id'] as num).toInt(),
      workspaceId: (row['workspace_id'] as num).toInt(),
      clientId: row['client_id']?.toString() ?? '',
      operationType: row['operation_type']?.toString() ?? '',
      conversationServerId: (row['conversation_server_id'] as num).toInt(),
      payload: row['payload']?.toString() ?? '{}',
      state: row['state']?.toString() ?? OutboxOpState.pending,
      attempts: (row['attempts'] as num?)?.toInt() ?? 0,
      nextAttemptAt: _fromMs(row['next_attempt_at']) ?? DateTime.fromMillisecondsSinceEpoch(0),
      lastError: row['last_error']?.toString(),
    );
  }

  static int _bool(bool value) => value ? 1 : 0;
  static bool _asBool(Object? value) => value == 1 || value == true;
  static int? _ms(DateTime? value) => value?.millisecondsSinceEpoch;
  static DateTime? _fromMs(Object? value) {
    if (value is num) return DateTime.fromMillisecondsSinceEpoch(value.toInt());
    return null;
  }
}
