import 'dart:async';
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
    } catch (_) {
      // Malformed outbox payload — treat as empty content.
    }
    return '';
  }
}

/// Account and workspace fields the shell needs before the network returns.
class LocalAccountSnapshot {
  const LocalAccountSnapshot({
    required this.user,
    required this.participant,
    required this.workspaces,
    this.currentWorkspaceId,
  });

  final UserModel user;
  final MobileParticipant participant;
  final List<WorkspaceModel> workspaces;
  final int? currentWorkspaceId;
}

/// Workspace-scoped Drift access. Every query takes [workspaceId] explicitly.
class InboxLocalStore {
  InboxLocalStore(this._db);

  final InboxDatabase _db;
  final _changes = StreamController<void>.broadcast();

  /// Newest messages returned for an open chat. Older rows stay in SQLite.
  static const latestMessageWindow = 300;

  /// Live inbox watch window. Smaller than [latestMessageWindow] so opening a
  /// chat does not remap hundreds of rows on every local write.
  static const liveMessageWindow = 80;

  static const conversationListLimit = 1000;

  Future<List<ConversationModel>> getConversations(
    int workspaceId, {
    String filter = 'all',
    String? channel,
    String? search,
    String? customerPriority,
    String? paymentStatus,
    String? reviewStatus,
    int limit = conversationListLimit,
  }) async {
    final args = <Object?>[workspaceId];
    final where = StringBuffer(
      'workspace_id = ? AND deleted_at IS NULL',
    );
    if (filter == 'unread') {
      where.write(' AND archived = 0 AND unread_count > 0');
    } else if (filter == 'archived') {
      where.write(' AND archived = 1');
    } else {
      where.write(' AND archived = 0');
    }
    if (channel != null && channel.isNotEmpty) {
      where.write(' AND channel = ?');
      args.add(channel);
    }
    if (customerPriority != null && customerPriority.isNotEmpty) {
      where.write(' AND customer_priority = ?');
      args.add(customerPriority);
    }
    if (paymentStatus != null && paymentStatus.isNotEmpty) {
      where.write(' AND payment_status = ?');
      args.add(paymentStatus);
    }
    if (reviewStatus != null && reviewStatus.isNotEmpty) {
      where.write(' AND review_status = ?');
      args.add(reviewStatus);
    }
    final q = search?.trim().toLowerCase() ?? '';
    if (q.isNotEmpty) {
      where.write(
        " AND (lower(COALESCE(customer_name, '')) LIKE ? ESCAPE '\\' "
        "OR lower(COALESCE(last_message_preview, '')) LIKE ? ESCAPE '\\' "
        "OR lower(COALESCE(external_id, '')) LIKE ? ESCAPE '\\')",
      );
      final like = _like(q);
      args.add(like);
      args.add(like);
      args.add(like);
    }
    args.add(limit);
    final rows = await _db.select(
      'SELECT * FROM inbox_conversations WHERE $where '
      'ORDER BY last_message_at IS NULL, last_message_at DESC, server_id DESC '
      'LIMIT ?',
      args,
    );
    return rows.map(_conversationFromRow).toList();
  }

  Stream<List<ConversationModel>> watchConversations(
    int workspaceId, {
    String filter = 'all',
    String? channel,
    String? search,
    String? customerPriority,
    String? paymentStatus,
    String? reviewStatus,
  }) {
    return _queryStream(
      () => getConversations(
        workspaceId,
        filter: filter,
        channel: channel,
        search: search,
        customerPriority: customerPriority,
        paymentStatus: paymentStatus,
        reviewStatus: reviewStatus,
      ),
    );
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
    _changed();
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
    final existingLastId = (existing?['last_message_server_id'] as num?)?.toInt();
    final incomingLastId =
        (item.lastMessage?.id ?? 0) > 0 ? item.lastMessage!.id : null;
    final incomingOutbound = item.lastMessage?.isOutbound == true;

    if (item.unreadCount <= 0) {
      displayUnread = 0;
    } else if (existing != null && localReadAt != null) {
      final readUntil = (localReadLast as num?)?.toInt();
      final hasNewerMessageId = incomingLastId != null &&
          existingLastId != null &&
          incomingLastId > existingLastId;
      final hasNewerTimestamp = incomingLast != null &&
          readUntil != null &&
          incomingLast > readUntil;
      // After a local send, last_message_server_id is already the outbound id,
      // so the same outbound row is not "newer". A later inbound — or an
      // outbound AI reply after that inbound — has a higher id and must restore.
      if (hasNewerMessageId ||
          (!incomingOutbound &&
              existingLastId == null &&
              hasNewerTimestamp) ||
          (!incomingOutbound &&
              incomingLastId == null &&
              hasNewerTimestamp)) {
        localReadAt = null;
        localReadLast = null;
      } else {
        displayUnread = 0;
      }
    }

    final preview = item.lastMessage?.content ?? existing?['last_message_preview']?.toString();
    final lastServerId = (incomingLastId != null && incomingLastId > 0)
        ? incomingLastId
        : existing?['last_message_server_id'];
    final customerId = item.customer?.id ?? existing?['customer_id'];
    final customerName = item.customer?.name ?? existing?['customer_name'];
    final customerPhone = item.customer?.phone ?? existing?['customer_phone'];
    final customerEmail = item.customer?.email ?? existing?['customer_email'];

    await _db.custom(
      '''
      INSERT INTO inbox_conversations (
        workspace_id, server_id, channel, status, external_id, ai_enabled,
        last_message_at, unread_count, server_unread_count, local_read_at,
        local_read_last_message_at, muted, archived, customer_id, customer_name,
        customer_phone, customer_email, last_message_preview, last_message_server_id,
        customer_priority, payment_status, review_status, classification_source,
        handoff_status, handoff_reason, assigned_user_id, assigned_user_name,
        assigned_worker_engagement_id, assigned_participant_type, can_reply,
        sync_state, deleted_at, updated_at, cached_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        customer_priority = excluded.customer_priority,
        payment_status = excluded.payment_status,
        review_status = excluded.review_status,
        classification_source = excluded.classification_source,
        handoff_status = excluded.handoff_status,
        handoff_reason = excluded.handoff_reason,
        assigned_user_id = excluded.assigned_user_id,
        assigned_user_name = excluded.assigned_user_name,
        assigned_worker_engagement_id = excluded.assigned_worker_engagement_id,
        assigned_participant_type = excluded.assigned_participant_type,
        can_reply = excluded.can_reply,
        sync_state = excluded.sync_state,
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
        customerId,
        customerName,
        customerPhone,
        customerEmail,
        preview,
        lastServerId,
        item.customerPriority,
        item.paymentStatus,
        item.reviewStatus,
        item.classificationSource,
        item.handoffStatus,
        item.handoffReason,
        item.assignedUserId,
        item.assignedUserName,
        item.assignedWorkerEngagementId,
        item.assignedParticipantType,
        _bool(item.canReply),
        'synced',
        null,
        _ms(item.lastMessageAt ?? now),
        _ms(now),
      ],
    );

    final last = item.lastMessage;
    if (last != null && last.id > 0) {
      final existingMsg = await _db.select(
        'SELECT 1 FROM inbox_messages WHERE workspace_id = ? AND server_id = ? LIMIT 1',
        [workspaceId, last.id],
      );
      if (existingMsg.isEmpty) {
        await _upsertMessage(workspaceId, item.id, last, now);
      }
    }
  }

  Future<List<MessageModel>> getMessages(
    int workspaceId,
    int conversationId, {
    int limit = latestMessageWindow,
  }) async {
    final rows = await _db.select(
      'SELECT * FROM ('
      'SELECT * FROM inbox_messages '
      'WHERE workspace_id = ? AND conversation_server_id = ? '
      'ORDER BY created_at IS NULL, created_at DESC, id DESC '
      'LIMIT ?'
      ') AS recent '
      'ORDER BY created_at IS NULL, created_at ASC, id ASC',
      [workspaceId, conversationId, limit],
    );
    return rows.map(_messageFromRow).toList();
  }

  Future<List<MessageModel>> getOlderMessages(
    int workspaceId,
    int conversationId, {
    required DateTime before,
    int limit = 40,
  }) async {
    final rows = await _db.select(
      'SELECT * FROM ('
      'SELECT * FROM inbox_messages '
      'WHERE workspace_id = ? AND conversation_server_id = ? AND created_at < ? '
      'ORDER BY created_at DESC, id DESC '
      'LIMIT ?'
      ') AS older '
      'ORDER BY created_at ASC, id ASC',
      [workspaceId, conversationId, _ms(before), limit],
    );
    return rows.map(_messageFromRow).toList();
  }

  Stream<List<MessageModel>> watchLatestMessages(
    int workspaceId,
    int conversationId, {
    int limit = latestMessageWindow,
  }) {
    return _queryStream(
      () => getMessages(workspaceId, conversationId, limit: limit),
    );
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
    _changed();
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
    final participantType = _participantType(item);
    final clientCreatedAt = existing?['client_created_at'] ??
        (localState == MessageLocalState.pending ? _ms(item.createdAt ?? now) : null);
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
          worker_engagement_id = COALESCE(?, worker_engagement_id),
          sender_user_id = COALESCE(?, sender_user_id),
          participant_type = COALESCE(?, participant_type),
          sync_state = ?,
          client_created_at = COALESCE(client_created_at, ?),
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
          item.workerEngagementId,
          item.senderUserId,
          participantType,
          localState,
          clientCreatedAt,
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
        attachments_json, local_state, worker_engagement_id, sender_user_id,
        participant_type, sync_state, client_created_at, cached_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        item.workerEngagementId,
        item.senderUserId,
        participantType,
        localState,
        clientCreatedAt,
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
      await tx.runCustom(
        '''
        UPDATE inbox_conversations
        SET last_message_preview = ?,
            last_message_at = ?,
            unread_count = 0,
            local_read_at = ?,
            local_read_last_message_at = ?,
            updated_at = ?,
            cached_at = ?
        WHERE workspace_id = ? AND server_id = ?
        ''',
        [
          message.content,
          _ms(message.createdAt ?? now),
          _ms(now),
          _ms(message.createdAt ?? now),
          _ms(now),
          _ms(now),
          workspaceId,
          conversationId,
        ],
      );
    });
    _changed();
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
      await tx.runCustom(
        '''
        UPDATE inbox_conversations
        SET last_message_preview = ?,
            last_message_at = ?,
            last_message_server_id = COALESCE(?, last_message_server_id),
            unread_count = 0,
            local_read_at = COALESCE(local_read_at, ?),
            local_read_last_message_at = ?,
            updated_at = ?,
            cached_at = ?
        WHERE workspace_id = ? AND server_id = ?
        ''',
        [
          server.content,
          _ms(server.createdAt ?? now),
          server.id > 0 ? server.id : null,
          _ms(now),
          _ms(server.createdAt ?? now),
          _ms(now),
          _ms(now),
          workspaceId,
          server.conversationId,
        ],
      );
    });
    _changed();
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
    _changed();
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
    _changed();
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
    _changed();
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
    _changed();
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
      await tx.runCustom('DELETE FROM local_catalog_items WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_catalog_variants WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_catalog_categories WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_digital_pools WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_digital_units WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_contacts WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_contact_groups WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_contact_group_members WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_emails WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_email_drafts WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_email_campaigns WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_stories WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_story_viewers WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_notifications WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_notification_preferences WHERE workspace_id = ?', [workspaceId]);
      await tx.runCustom('DELETE FROM local_company_cache WHERE workspace_id = ?', [workspaceId]);
    });
    _changed();
  }

  Future<void> clearAll() async {
    await _db.transaction((tx) async {
      await tx.runCustom('DELETE FROM inbox_messages');
      await tx.runCustom('DELETE FROM inbox_conversations');
      await tx.runCustom('DELETE FROM inbox_outbox');
      await tx.runCustom('DELETE FROM inbox_sync_state');
      await tx.runCustom('DELETE FROM local_account');
      await tx.runCustom('DELETE FROM local_workspaces');
      await tx.runCustom('DELETE FROM local_catalog_items');
      await tx.runCustom('DELETE FROM local_catalog_variants');
      await tx.runCustom('DELETE FROM local_catalog_categories');
      await tx.runCustom('DELETE FROM local_digital_pools');
      await tx.runCustom('DELETE FROM local_digital_units');
      await tx.runCustom('DELETE FROM local_contacts');
      await tx.runCustom('DELETE FROM local_contact_groups');
      await tx.runCustom('DELETE FROM local_contact_group_members');
      await tx.runCustom('DELETE FROM local_emails');
      await tx.runCustom('DELETE FROM local_email_drafts');
      await tx.runCustom('DELETE FROM local_email_campaigns');
      await tx.runCustom('DELETE FROM local_stories');
      await tx.runCustom('DELETE FROM local_story_viewers');
      await tx.runCustom('DELETE FROM local_notifications');
      await tx.runCustom('DELETE FROM local_notification_preferences');
      await tx.runCustom('DELETE FROM local_worker_records');
      await tx.runCustom('DELETE FROM local_company_cache');
    });
    _changed();
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
      customerPriority: row['customer_priority']?.toString() ?? 'normal',
      paymentStatus: row['payment_status']?.toString() ?? 'unpaid',
      reviewStatus: row['review_status']?.toString() ?? 'no_review_needed',
      classificationSource: row['classification_source']?.toString() ?? 'system',
      handoffStatus: row['handoff_status']?.toString() ?? 'none',
      handoffReason: row['handoff_reason']?.toString(),
      assignedUserId: (row['assigned_user_id'] as num?)?.toInt(),
      assignedUserName: row['assigned_user_name']?.toString(),
      assignedWorkerEngagementId: (row['assigned_worker_engagement_id'] as num?)?.toInt(),
      assignedParticipantType: row['assigned_participant_type']?.toString(),
      canReply: row['can_reply'] == null ? true : _asBool(row['can_reply']),
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
      } catch (_) {
        // Skip corrupt attachment JSON rows.
      }
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
      workerEngagementId: (row['worker_engagement_id'] as num?)?.toInt(),
      senderUserId: (row['sender_user_id'] as num?)?.toInt(),
      participantType: row['participant_type']?.toString(),
    );
  }

  Future<void> saveAccountSnapshot(LocalAccountSnapshot snapshot) async {
    final now = _ms(DateTime.now());
    await _db.transaction((tx) async {
      await tx.runCustom('DELETE FROM local_workspaces');
      await tx.runCustom(
        '''
        INSERT INTO local_account (
          id, server_user_id, name, email, phone, avatar_url, locale, timezone,
          participant_type, worker_profile_id, current_workspace_server_id, cached_at
        ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(id) DO UPDATE SET
          server_user_id = excluded.server_user_id,
          name = excluded.name,
          email = excluded.email,
          phone = excluded.phone,
          avatar_url = excluded.avatar_url,
          locale = excluded.locale,
          timezone = excluded.timezone,
          participant_type = excluded.participant_type,
          worker_profile_id = excluded.worker_profile_id,
          current_workspace_server_id = excluded.current_workspace_server_id,
          cached_at = excluded.cached_at
        ''',
        [
          snapshot.user.id,
          snapshot.user.name,
          snapshot.user.email,
          snapshot.user.phone,
          snapshot.user.avatarUrl,
          snapshot.user.locale,
          snapshot.user.timezone,
          snapshot.participant.type,
          snapshot.participant.workerProfileId,
          snapshot.currentWorkspaceId,
          now,
        ],
      );
      for (final workspace in snapshot.workspaces) {
        await tx.runCustom(
          '''
          INSERT INTO local_workspaces (
            server_id, name, type, slug, role, is_owner, cached_at
          ) VALUES (?, ?, ?, ?, ?, ?, ?)
          ''',
          [
            workspace.id,
            workspace.name,
            workspace.type,
            workspace.slug,
            workspace.role,
            _bool(workspace.isOwner),
            now,
          ],
        );
      }
    });
    _changed();
  }

  Future<void> setCurrentWorkspace(int? workspaceId) async {
    final now = _ms(DateTime.now());
    await _db.update(
      'UPDATE local_account SET current_workspace_server_id = ?, cached_at = ? WHERE id = 1',
      [workspaceId, now],
    );
    _changed();
  }

  Future<LocalAccountSnapshot?> loadAccountSnapshot() async {
    final rows = await _db.select('SELECT * FROM local_account WHERE id = 1 LIMIT 1');
    if (rows.isEmpty) return null;
    final row = rows.first;
    final workspaceRows = await _db.select(
      'SELECT * FROM local_workspaces ORDER BY name, server_id',
    );
    return LocalAccountSnapshot(
      user: UserModel(
        id: (row['server_user_id'] as num).toInt(),
        name: row['name']?.toString() ?? '',
        email: row['email']?.toString(),
        phone: row['phone']?.toString(),
        locale: row['locale']?.toString(),
        timezone: row['timezone']?.toString(),
        avatarUrl: row['avatar_url']?.toString(),
      ),
      participant: MobileParticipant(
        type: row['participant_type']?.toString() ?? 'company_staff',
        workerProfileId: (row['worker_profile_id'] as num?)?.toInt(),
      ),
      currentWorkspaceId: (row['current_workspace_server_id'] as num?)?.toInt(),
      workspaces: [
        for (final workspace in workspaceRows)
          WorkspaceModel(
            id: (workspace['server_id'] as num).toInt(),
            name: workspace['name']?.toString() ?? '',
            type: workspace['type']?.toString(),
            slug: workspace['slug']?.toString(),
            role: workspace['role']?.toString(),
            isOwner: _asBool(workspace['is_owner']),
          ),
      ],
    );
  }

  Stream<LocalAccountSnapshot?> watchAccount() {
    return _queryStream(loadAccountSnapshot);
  }

  Stream<T> _queryStream<T>(Future<T> Function() read) {
    return Stream<T>.multi((listener) {
      var alive = true;
      var request = 0;
      Future<void> emit() async {
        final token = ++request;
        try {
          final value = await read();
          // A slow read that started before a newer write must not replace
          // the newer snapshot. That was dropping inbound rows after the
          // conversation list had cached only the latest AI reply.
          if (!alive || token != request) return;
          listener.add(value);
        } catch (error, stack) {
          if (alive && token == request) listener.addError(error, stack);
        }
      }

      final subscription = _changes.stream.listen((_) {
        unawaited(emit());
      });
      unawaited(emit());
      listener.onCancel = () {
        alive = false;
        unawaited(subscription.cancel());
      };
    });
  }

  void _changed() {
    if (!_changes.isClosed) _changes.add(null);
  }

  static String _like(String query) {
    final escaped = query
        .replaceAll(r'\', r'\\')
        .replaceAll('%', r'\%')
        .replaceAll('_', r'\_');
    return '%$escaped%';
  }

  static String _participantType(MessageModel item) {
    final explicit = item.participantType?.trim();
    if (explicit != null && explicit.isNotEmpty) return explicit;
    if (item.aiGenerated) return 'ai';
    if (item.workerEngagementId != null) return 'worker';
    return 'human';
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
