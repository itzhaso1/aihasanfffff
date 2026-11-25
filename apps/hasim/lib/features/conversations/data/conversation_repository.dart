import 'dart:async';
import 'dart:io';
import 'dart:math' as math;

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:hasim/core/local_db/conversation_reconciler.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/utils/idempotency.dart';
import 'package:hasim/features/conversations/data/chat_attachment_policy.dart';
import 'package:http_parser/http_parser.dart';

List<ConversationModel> _parseConversationsIsolate(
  List<Map<String, dynamic>> rawRows,
) {
  final items = <ConversationModel>[];
  for (final raw in rawRows) {
    try {
      items.add(ConversationModel.fromJson(raw));
    } catch (_) {}
  }
  return items;
}

List<MessageModel> _parseMessagesIsolate(List<Map<String, dynamic>> rawRows) {
  final items = <MessageModel>[];
  for (final row in rawRows) {
    try {
      items.add(MessageModel.fromJson(row));
    } catch (_) {}
  }
  return items;
}

class ConversationRepository {
  ConversationRepository(this._api, {InboxLocalStore? local}) : _local = local;

  final ApiClient _api;
  final InboxLocalStore? _local;

  /// True when the last list/messages call returned SQLite because the API failed.
  bool lastReadFromCache = false;

  Future<List<ConversationModel>> cachedConversations(
    int workspaceId, {
    String filter = 'all',
    String? channel,
    String? search,
    String? customerPriority,
    String? paymentStatus,
    String? reviewStatus,
  }) async {
    final store = _local;
    if (store == null) return const [];
    return store.getConversations(
      workspaceId,
      filter: filter,
      channel: channel,
      search: search,
      customerPriority: customerPriority,
      paymentStatus: paymentStatus,
      reviewStatus: reviewStatus,
    );
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
    final store = _local;
    if (store == null) return const Stream.empty();
    return store.watchConversations(
      workspaceId,
      filter: filter,
      channel: channel,
      search: search,
      customerPriority: customerPriority,
      paymentStatus: paymentStatus,
      reviewStatus: reviewStatus,
    );
  }

  Future<List<MessageModel>> cachedMessages(
    int workspaceId,
    int conversationId,
  ) async {
    return _local?.getMessages(workspaceId, conversationId) ?? const [];
  }

  Future<List<MessageModel>> cachedOlderMessages(
    int workspaceId,
    int conversationId, {
    required DateTime before,
  }) async {
    return _local?.getOlderMessages(
          workspaceId,
          conversationId,
          before: before,
        ) ??
        const [];
  }

  Stream<List<MessageModel>> watchLatestMessages(
    int workspaceId,
    int conversationId, {
    int limit = InboxLocalStore.liveMessageWindow,
  }) {
    final store = _local;
    if (store == null) return const Stream.empty();
    return store.watchLatestMessages(workspaceId, conversationId, limit: limit);
  }

  Future<void> persistConversations(
    int workspaceId,
    List<ConversationModel> items,
  ) async {
    await _local?.upsertConversations(workspaceId, items);
  }

  Future<ConversationModel?> cachedConversation(
    int workspaceId,
    int conversationId,
  ) {
    return _local?.getConversation(workspaceId, conversationId) ??
        Future.value();
  }

  Future<int> maxCachedServerMessageId(int workspaceId, int conversationId) {
    return _local?.getMaxServerMessageId(workspaceId, conversationId) ??
        Future.value(0);
  }

  Future<int> latestCachedConversationUpdatedMs(int workspaceId) {
    return _local?.getLatestConversationUpdatedMs(workspaceId) ??
        Future.value(0);
  }

  Future<bool> applyRealtimeMessage(
    int workspaceId,
    MessageModel message, {
    bool isConversationOpen = false,
  }) {
    return _local?.applyRealtimeMessage(
          workspaceId,
          message,
          isConversationOpen: isConversationOpen,
        ) ??
        Future.value(false);
  }

  Future<bool> applyRealtimeConversation(
    int workspaceId,
    ConversationModel conversation, {
    bool isConversationOpen = false,
  }) {
    return _local?.applyRealtimeConversation(
          workspaceId,
          conversation,
          isConversationOpen: isConversationOpen,
        ) ??
        Future.value(false);
  }

  Future<void> applyMessageStatusUpdate(
    int workspaceId,
    MessageStatusUpdate update,
  ) {
    return _local?.updateMessageDeliveryStatus(
          workspaceId: workspaceId,
          update: update,
        ) ??
        Future.value();
  }

  static const Object _cancelTokenZoneKey = Object();

  static Future<R> runWithCancelToken<R>(
    CancelToken token,
    Future<R> Function() fn,
  ) {
    return runZoned(fn, zoneValues: {_cancelTokenZoneKey: token});
  }

  static CancelToken? get _zoneCancelToken =>
      Zone.current[_cancelTokenZoneKey] as CancelToken?;

  Future<({List<ConversationModel> items, String? nextCursor})> list({
    String filter = 'all',
    String? channel,
    String? handoff,
    String? search,
    String? customerPriority,
    String? paymentStatus,
    String? reviewStatus,
    String? cursor,
    int? workspaceId,
  }) => _fetchConversationList(
    filter: filter,
    channel: channel,
    handoff: handoff,
    search: search,
    customerPriority: customerPriority,
    paymentStatus: paymentStatus,
    reviewStatus: reviewStatus,
    cursor: cursor,
    workspaceId: workspaceId,
    cancelToken: _zoneCancelToken,
  );

  Future<({List<ConversationModel> items, String? nextCursor})> deltaList({
    required DateTime updatedAfter,
    String filter = 'all',
    String? channel,
    String? handoff,
    String? search,
    String? customerPriority,
    String? paymentStatus,
    String? reviewStatus,
    int? workspaceId,
  }) => _fetchConversationList(
    filter: filter,
    channel: channel,
    handoff: handoff,
    search: search,
    customerPriority: customerPriority,
    paymentStatus: paymentStatus,
    reviewStatus: reviewStatus,
    updatedAfter: updatedAfter,
    workspaceId: workspaceId,
    cancelToken: _zoneCancelToken,
  );

  Future<({List<ConversationModel> items, String? nextCursor})>
  _fetchConversationList({
    String filter = 'all',
    String? channel,
    String? handoff,
    String? search,
    String? customerPriority,
    String? paymentStatus,
    String? reviewStatus,
    DateTime? updatedAfter,
    String? cursor,
    int? workspaceId,
    CancelToken? cancelToken,
  }) async {
    try {
      lastReadFromCache = false;
      final res = await _api.get(
        '/conversations',
        cancelToken: cancelToken,
        query: {
          'filter': filter,
          if (channel != null && channel.isNotEmpty) 'channel': channel,
          if (handoff != null && handoff.isNotEmpty) 'handoff': handoff,
          if (search != null && search.isNotEmpty) 'search': search,
          if (customerPriority != null && customerPriority.isNotEmpty)
            'customer_priority': customerPriority,
          if (paymentStatus != null && paymentStatus.isNotEmpty)
            'payment_status': paymentStatus,
          if (reviewStatus != null && reviewStatus.isNotEmpty)
            'review_status': reviewStatus,
          if (updatedAfter != null)
            'updated_after': updatedAfter.toUtc().toIso8601String(),
          if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
          'per_page': 20,
        },
      );
      final rawList = asMapList(res.data);
      final List<ConversationModel> items;
      if (!kIsWeb && rawList.length >= 60) {
        items = await compute(_parseConversationsIsolate, rawList);
      } else {
        items = <ConversationModel>[];
        for (final raw in rawList) {
          try {
            items.add(ConversationModel.fromJson(raw));
          } catch (e, st) {
            debugPrint('[Hasim] Skipping malformed conversation: $e\n$st');
          }
        }
      }
      if (workspaceId != null) {
        try {
          await _local?.upsertConversations(workspaceId, items);
          if (cursor == null || cursor.isEmpty) {
            await _local?.setSyncCursor(
              workspaceId,
              ConversationReconciler.syncResource(
                filter: filter,
                channel: channel,
                search: search,
                handoff: handoff,
              ),
              res.nextCursor,
            );
          }
        } catch (e, st) {
          debugPrint('[Hasim] SQLite cache write failed in list(): $e\n$st');
        }
      }
      return (items: items, nextCursor: res.nextCursor);
    } catch (e, st) {
      if (e is ApiException && e.isCancelled) rethrow;
      debugPrint('[Hasim] ConversationRepository.list failed: $e\n$st');
      if (workspaceId != null && (cursor == null || cursor.isEmpty)) {
        try {
          final cached = await cachedConversations(
            workspaceId,
            filter: filter,
            channel: channel,
            search: search,
            customerPriority: customerPriority,
            paymentStatus: paymentStatus,
            reviewStatus: reviewStatus,
          );
          if (cached.isNotEmpty) {
            lastReadFromCache = true;
            return (items: cached, nextCursor: null);
          }
        } catch (cacheErr, cacheSt) {
          debugPrint('[Hasim] cachedConversations fallback failed: $cacheErr\n$cacheSt');
        }
      }
      rethrow;
    }
  }

  Future<ConversationModel> getById(int id, {int? workspaceId}) async {
    try {
      final res = await _api.get('/conversations/$id');
      final map = asMap(res.data);
      if (map == null) {
        throw ApiException(res.message ?? 'المحادثة غير موجودة.');
      }
      final conversation = ConversationModel.fromJson(map);
      if (workspaceId != null) {
        try {
          await _local?.upsertConversations(workspaceId, [conversation]);
        } catch (e) {
          debugPrint('[Hasim] getById local upsert error: $e');
        }
      }
      return conversation;
    } catch (e) {
      if (workspaceId != null) {
        try {
          final cached = await cachedConversation(workspaceId, id);
          if (cached != null) return cached;
        } catch (_) {}
      }
      rethrow;
    }
  }

  Future<({List<MessageModel> items, String? nextCursor, bool hasMore})> messagesPage(
    int conversationId, {
    String? cursor,
    int? afterId,
    int? workspaceId,
  }) async {
    try {
      lastReadFromCache = false;
      final res = await _api.get(
        '/conversations/$conversationId/messages',
        cancelToken: _zoneCancelToken,
        query: {
          if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
          if (afterId != null && afterId > 0) 'after_id': afterId,
          'per_page': (afterId != null && afterId > 0) ? 100 : 30,
        },
      );
      final rawList = asMapList(res.data);
      final List<MessageModel> items;
      if (!kIsWeb && rawList.length >= 60) {
        items = await compute(_parseMessagesIsolate, rawList);
      } else {
        items = <MessageModel>[];
        for (final row in rawList) {
          try {
            items.add(MessageModel.fromJson(row));
          } catch (_) {
            // One malformed row must not discard the customer messages beside it.
          }
        }
      }
      if (workspaceId != null && items.isNotEmpty) {
        final chronological = (afterId != null && afterId > 0)
            ? items
            : items.reversed.toList();
        try {
          await _local?.upsertMessages(
            workspaceId,
            conversationId,
            chronological,
          );
          if (cursor == null || cursor.isEmpty) {
            await _local?.setSyncCursor(
              workspaceId,
              ConversationReconciler.messagesResource(conversationId),
              res.nextCursor,
            );
          }
        } catch (e, st) {
          debugPrint('[Hasim] SQLite cache write failed in messages(): $e\n$st');
        }
      }
      final hasMore = res.meta?['has_more'] == true;
      return (items: items, nextCursor: res.nextCursor, hasMore: hasMore);
    } catch (e) {
      if (workspaceId != null &&
          (cursor == null || cursor.isEmpty) &&
          (afterId == null || afterId <= 0)) {
        final cached = await cachedMessages(workspaceId, conversationId);
        if (cached.isNotEmpty) {
          lastReadFromCache = true;
          return (items: cached.reversed.toList(), nextCursor: null, hasMore: false);
        }
      }
      rethrow;
    }
  }

  Future<({List<MessageModel> items, String? nextCursor})> messages(
    int conversationId, {
    String? cursor,
    int? afterId,
    int? workspaceId,
  }) async {
    final page = await messagesPage(
      conversationId,
      cursor: cursor,
      afterId: afterId,
      workspaceId: workspaceId,
    );
    return (items: page.items, nextCursor: page.nextCursor);
  }

  /// Always reuses [idempotencyKey] for both header and body. Do not call
  /// [_api.post] with `idempotent: true` here — that would mint a second key.
  Future<MessageModel> sendMessage(
    int conversationId,
    String content, {
    String? idempotencyKey,
    String direction = 'outbound',
  }) {
    return sendMessageWithKey(
      conversationId,
      content,
      idempotencyKey ?? newIdempotencyKey(),
      direction: direction,
    );
  }

  Future<MessageModel> sendMessageWithKey(
    int conversationId,
    String content,
    String key, {
    String direction = 'outbound',
    String? correlationId,
  }) async {
    final res = await _api.post(
      'conversations/$conversationId/messages',
      body: {
        'content': content,
        'direction': direction,
        'message_type': 'text',
        'idempotency_key': key,
        'client_id': key,
      },
      headers: {
        'Idempotency-Key': key,
        if (correlationId != null && correlationId.isNotEmpty)
          'X-Correlation-Id': correlationId,
      },
      mapData: asMap,
    );
    final map = res.data;
    if (map == null) throw ApiException(res.message ?? 'تعذر إرسال الرسالة.');
    final parsed = MessageModel.fromJson(map);
    return parsed.copyWith(
      clientId: key,
      correlationId: correlationId ?? parsed.correlationId,
    );
  }

  Future<MessageModel> retryMessage(
    int conversationId,
    int messageId, {
    String? correlationId,
  }) async {
    final res = await _api.post(
      'conversations/$conversationId/messages/$messageId/retry',
      headers: {
        if (correlationId != null && correlationId.isNotEmpty)
          'X-Correlation-Id': correlationId,
      },
      mapData: asMap,
    );
    final map = res.data;
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر إعادة إرسال الرسالة.');
    }
    final parsed = MessageModel.fromJson(map);
    return parsed.copyWith(
      correlationId: correlationId ?? parsed.correlationId,
    );
  }

  Future<MessageModel?> findMessageByClientIdOnServer(
    int conversationId,
    String clientId,
  ) async {
    final res = await _api.get(
      'conversations/$conversationId/messages',
      query: const {'per_page': 30},
    );
    final items = asMapList(res.data).map(MessageModel.fromJson);
    for (final m in items) {
      if (m.clientId == clientId) {
        return m;
      }
    }
    return null;
  }

  Future<String?> cachedOutboxError(int workspaceId, String clientId) async {
    final store = _local;
    if (store == null) return null;
    final op = await store.outboxByClientId(workspaceId, clientId);
    if (op?.lastError != null && op!.lastError!.isNotEmpty) {
      return op.lastError;
    }
    final msg = await store.getMessageByClientId(workspaceId, clientId);
    return msg?.localError;
  }

  Future<Map<String, dynamic>> sendBulkMessage({
    List<int> conversationIds = const [],
    bool selectAll = false,
    Map<String, dynamic> filters = const {},
    required String content,
    String? idempotencyKey,
  }) async {
    final key = idempotencyKey ?? newIdempotencyKey();
    final res = await _api.post(
      'conversations/bulk-messages',
      body: {
        'conversation_ids': conversationIds,
        'select_all': selectAll,
        if (filters.isNotEmpty) 'filters': filters,
        'content': content,
        'idempotency_key': key,
      },
      headers: {'Idempotency-Key': key},
      mapData: asMap,
    );
    final data = res.data;
    if (data == null) {
      throw ApiException(res.message ?? 'تعذر جدولة الإرسال الجماعي.');
    }

    return data;
  }

  Future<void> enqueueOutboundText({
    required int workspaceId,
    required int conversationId,
    required MessageModel message,
  }) async {
    final store = _local;
    if (store == null) {
      throw StateError('Inbox local store is not configured');
    }
    await store.enqueueTextSend(
      workspaceId: workspaceId,
      conversationId: conversationId,
      message: message,
    );
  }

  Future<void> retryOutbound({
    required int workspaceId,
    required String clientId,
  }) async {
    await _local?.requeueFailed(workspaceId: workspaceId, clientId: clientId);
  }

  Future<void> persistMessages(
    int workspaceId,
    int conversationId,
    List<MessageModel> items,
  ) async {
    await _local?.upsertMessages(workspaceId, conversationId, items);
  }

  Future<void> clearAllLocal() async {
    await _local?.clearAll();
  }

  Future<void> markRead(
    int conversationId, {
    int? messageId,
    int? workspaceId,
  }) async {
    await _api.post(
      '/conversations/$conversationId/read',
      body: {'message_id': ?messageId},
    );
    if (workspaceId != null) {
      await _local?.markConversationRead(workspaceId, conversationId);
    }
  }

  Future<void> markReadLocal(int workspaceId, int conversationId) async {
    await _local?.markConversationRead(workspaceId, conversationId);
  }

  Future<HomeSnapshot> overlayHomeUnread(
    int workspaceId,
    HomeSnapshot snapshot,
  ) async {
    final cleared =
        await _local?.staleServerUnreadClearedCount(workspaceId) ?? 0;
    if (cleared == 0) return snapshot;
    return snapshot.copyWith(
      unreadConversations: math.max(0, snapshot.unreadConversations - cleared),
    );
  }

  Future<ConversationModel> createConversation({
    int? customerId,
    String? customerName,
    String? customerPhone,
    required String channel,
    String? externalId,
    String status = 'open',
    bool aiEnabled = true,
    Map<String, dynamic>? metadata,
    String? initialMessage,
    int? workspaceId,
  }) async {
    final res = await _api.post(
      '/conversations',
      body: {
        'customer_id': ?customerId,
        if (customerName != null && customerName.isNotEmpty)
          'customer_name': customerName,
        if (customerPhone != null && customerPhone.isNotEmpty)
          'customer_phone': customerPhone,
        'channel': channel,
        if (externalId != null && externalId.isNotEmpty)
          'external_id': externalId,
        'status': status,
        'ai_enabled': aiEnabled,
        'metadata': ?metadata,
        if (initialMessage != null && initialMessage.isNotEmpty)
          'initial_message': initialMessage,
      },
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر إنشاء المحادثة.');
    final created = ConversationModel.fromJson(map);
    if (workspaceId != null) {
      await _local?.upsertConversations(workspaceId, [created]);
    }
    return created;
  }

  Future<void> deleteConversation(
    int conversationId, {
    int? workspaceId,
  }) async {
    await _api.delete('/conversations/$conversationId');
    if (workspaceId != null) {
      await _local?.deleteConversation(workspaceId, conversationId);
    }
  }

  Future<void> archive(
    int conversationId, {
    bool archived = true,
    int? workspaceId,
  }) async {
    await _api.post(
      '/conversations/$conversationId/archive',
      body: {'archived': archived},
    );
    if (workspaceId != null) {
      final existing = await cachedConversation(workspaceId, conversationId);
      if (existing != null) {
        await _local?.upsertConversations(workspaceId, [
          existing.copyWith(
            archived: archived,
            status: archived ? 'archived' : 'open',
          ),
        ]);
      }
    }
  }

  Future<void> closeConversation(
    int conversationId, {
    int? workspaceId,
  }) async {
    await _api.post('/conversations/$conversationId/close');
    if (workspaceId != null) {
      final existing = await cachedConversation(workspaceId, conversationId);
      if (existing != null) {
        await _local?.upsertConversations(workspaceId, [
          existing.copyWith(status: 'closed'),
        ]);
      }
    }
  }

  Future<void> reopenConversation(
    int conversationId, {
    int? workspaceId,
  }) async {
    await _api.post('/conversations/$conversationId/reopen');
    if (workspaceId != null) {
      final existing = await cachedConversation(workspaceId, conversationId);
      if (existing != null) {
        await _local?.upsertConversations(workspaceId, [
          existing.copyWith(status: 'open'),
        ]);
      }
    }
  }

  Future<void> mute(
    int conversationId, {
    bool muted = true,
    int? workspaceId,
  }) async {
    await _api.post(
      '/conversations/$conversationId/mute',
      body: {'muted': muted},
    );
    if (workspaceId != null) {
      final existing = await cachedConversation(workspaceId, conversationId);
      if (existing != null) {
        await _local?.upsertConversations(workspaceId, [
          existing.copyWith(muted: muted),
        ]);
      }
    }
  }

  Future<MessageAttachmentModel> uploadAttachment(
    int messageId,
    File file,
  ) async {
    final ext = ChatAttachmentPolicy.extensionOf(file);
    final mime = ChatAttachmentPolicy.mimeForExtension(ext);
    final rawName = file.uri.pathSegments.isNotEmpty
        ? file.uri.pathSegments.last
        : 'upload';
    final filename = rawName.contains('.') ? rawName : 'upload.${ext ?? 'bin'}';
    final size = file.existsSync() ? file.lengthSync() : 0;
    if (kDebugMode) {
      debugPrint(
        '[CHAT_IMAGE_UPLOAD] endpoint=POST /messages/$messageId/attachments '
        'field=file filename=$filename mime=$mime size=$size',
      );
    }
    final form = FormData.fromMap({
      'file': await MultipartFile.fromFile(
        file.path,
        filename: filename,
        contentType: mime == null ? null : MediaType.parse(mime),
      ),
    });
    final res = await _api.upload(
      '/messages/$messageId/attachments',
      formData: form,
    );
    final map = asMap(res.data);
    if (kDebugMode) {
      final url =
          map?['download_url']?.toString() ??
          map?['preview_url']?.toString() ??
          '';
      final pathOnly = Uri.tryParse(url)?.path ?? url;
      debugPrint(
        '[CHAT_IMAGE_UPLOAD] status=ok attachment_id=${map?['id']} path=$pathOnly',
      );
    }
    if (map == null) throw ApiException(res.message ?? 'تعذر رفع المرفق.');
    return MessageAttachmentModel.fromJson(map);
  }

  Future<MessageAttachmentModel> uploadAttachmentBytes(
    int messageId, {
    required Uint8List bytes,
    required String filename,
    String? mime,
  }) async {
    final ext = filename.contains('.')
        ? filename.split('.').last.toLowerCase()
        : null;
    final contentType = mime ?? ChatAttachmentPolicy.mimeForExtension(ext);
    if (kDebugMode) {
      debugPrint(
        '[CHAT_VOICE_UPLOAD] endpoint=POST /messages/$messageId/attachments '
        'field=file filename=$filename mime=$contentType size=${bytes.length}',
      );
    }
    final form = FormData.fromMap({
      'file': MultipartFile.fromBytes(
        bytes,
        filename: filename,
        contentType: contentType == null ? null : MediaType.parse(contentType),
      ),
    });
    final res = await _api.upload(
      '/messages/$messageId/attachments',
      formData: form,
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر رفع المرفق.');
    return MessageAttachmentModel.fromJson(map);
  }

  Future<ConversationModel> updateClassification(
    int conversationId, {
    required String customerPriority,
    required String paymentStatus,
    required String reviewStatus,
    int? workspaceId,
  }) async {
    final res = await _api.patch(
      '/conversations/$conversationId/classification',
      body: {
        'customer_priority': customerPriority,
        'payment_status': paymentStatus,
        'review_status': reviewStatus,
      },
    );
    return _conversationWrite(
      res.data,
      workspaceId: workspaceId,
      fallback: res.message ?? 'تعذر تحديث التصنيف.',
    );
  }

  Future<ConversationModel> requestHandoff(
    int conversationId, {
    String? reason,
    int? workspaceId,
  }) async {
    final res = await _api.post(
      '/conversations/$conversationId/handoff',
      body: {if (reason != null && reason.isNotEmpty) 'reason': reason},
    );
    return _conversationWrite(
      res.data,
      workspaceId: workspaceId,
      fallback: res.message ?? 'تعذر طلب التحويل.',
    );
  }

  Future<ConversationModel> assignHandoff(
    int conversationId, {
    int? userId,
    int? workerEngagementId,
    int? workspaceId,
  }) async {
    final res = await _api.post(
      '/conversations/$conversationId/assign',
      body: {'user_id': ?userId, 'worker_engagement_id': ?workerEngagementId},
    );
    return _conversationWrite(
      res.data,
      workspaceId: workspaceId,
      fallback: res.message ?? 'تعذر تعيين المحادثة.',
    );
  }

  Future<ConversationModel> takeoverHandoff(
    int conversationId, {
    int? workspaceId,
  }) async {
    final res = await _api.post('/conversations/$conversationId/takeover');
    return _conversationWrite(
      res.data,
      workspaceId: workspaceId,
      fallback: res.message ?? 'تعذر تولي المحادثة.',
    );
  }

  Future<ConversationModel> resolveHandoff(
    int conversationId, {
    int? workspaceId,
  }) async {
    final res = await _api.post(
      '/conversations/$conversationId/resolve-handoff',
    );
    return _conversationWrite(
      res.data,
      workspaceId: workspaceId,
      fallback: res.message ?? 'تعذر إنهاء التحويل.',
    );
  }

  Future<ConversationModel> resumeAi(
    int conversationId, {
    int? workspaceId,
  }) async {
    final res = await _api.post('/conversations/$conversationId/resume-ai');
    return _conversationWrite(
      res.data,
      workspaceId: workspaceId,
      fallback: res.message ?? 'تعذر تفعيل الذكاء الاصطناعي.',
    );
  }

  Future<ConversationModel> setAiEnabled(
    int conversationId, {
    required bool enabled,
    int? workspaceId,
  }) async {
    final res = await _api.patch(
      '/conversations/$conversationId/ai',
      body: {'ai_enabled': enabled},
    );
    return _conversationWrite(
      res.data,
      workspaceId: workspaceId,
      fallback: res.message ?? 'تعذر تحديث رد الذكاء الاصطناعي.',
    );
  }

  Future<ConversationModel> _conversationWrite(
    dynamic data, {
    required int? workspaceId,
    required String fallback,
  }) async {
    final map = asMap(data);
    if (map == null) throw ApiException(fallback);
    final model = ConversationModel.fromJson(map);
    if (workspaceId != null) {
      try {
        await _local?.upsertConversations(workspaceId, [model]);
      } catch (e, st) {
        debugPrint(
          '[Hasim] SQLite cache write failed in _conversationWrite(): $e\n$st',
        );
      }
    }
    return model;
  }
}
