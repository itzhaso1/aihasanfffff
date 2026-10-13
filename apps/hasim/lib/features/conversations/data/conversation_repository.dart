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

  Future<({List<ConversationModel> items, String? nextCursor})> list({
    String filter = 'all',
    String? channel,
    String? search,
    String? customerPriority,
    String? paymentStatus,
    String? reviewStatus,
    String? cursor,
    int? workspaceId,
  }) async {
    try {
      lastReadFromCache = false;
      final res = await _api.get(
        '/conversations',
        query: {
          'filter': filter,
          if (channel != null && channel.isNotEmpty) 'channel': channel,
          if (search != null && search.isNotEmpty) 'search': search,
          if (customerPriority != null && customerPriority.isNotEmpty)
            'customer_priority': customerPriority,
          if (paymentStatus != null && paymentStatus.isNotEmpty)
            'payment_status': paymentStatus,
          if (reviewStatus != null && reviewStatus.isNotEmpty)
            'review_status': reviewStatus,
          if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
          'per_page': 20,
        },
      );
      final items = asMapList(
        res.data,
      ).map(ConversationModel.fromJson).toList();
      if (workspaceId != null) {
        await _local?.upsertConversations(workspaceId, items);
        if (cursor == null || cursor.isEmpty) {
          await _local?.setSyncCursor(
            workspaceId,
            ConversationReconciler.syncResource(
              filter: filter,
              channel: channel,
              search: search,
            ),
            res.nextCursor,
          );
        }
      }
      return (items: items, nextCursor: res.nextCursor);
    } catch (e) {
      if (workspaceId != null && (cursor == null || cursor.isEmpty)) {
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
        await _local?.upsertConversations(workspaceId, [conversation]);
      }
      return conversation;
    } catch (e) {
      if (workspaceId != null) {
        final cached = await cachedConversation(workspaceId, id);
        if (cached != null) return cached;
      }
      rethrow;
    }
  }

  Future<({List<MessageModel> items, String? nextCursor})> messages(
    int conversationId, {
    String? cursor,
    int? workspaceId,
  }) async {
    try {
      lastReadFromCache = false;
      final res = await _api.get(
        '/conversations/$conversationId/messages',
        query: {
          if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
          'per_page': 30,
        },
      );
      final items = asMapList(res.data).map(MessageModel.fromJson).toList();
      if (workspaceId != null) {
        final chronological = items.reversed.toList();
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
      }
      return (items: items, nextCursor: res.nextCursor);
    } catch (e) {
      if (workspaceId != null && (cursor == null || cursor.isEmpty)) {
        final cached = await cachedMessages(workspaceId, conversationId);
        if (cached.isNotEmpty) {
          lastReadFromCache = true;
          return (items: cached.reversed.toList(), nextCursor: null);
        }
      }
      rethrow;
    }
  }

  /// Always reuses [idempotencyKey] for both header and body. Do not call
  /// [_api.post] with `idempotent: true` here — that would mint a second key.
  Future<MessageModel> sendMessage(
    int conversationId,
    String content, {
    String? idempotencyKey,
  }) {
    return sendMessageWithKey(
      conversationId,
      content,
      idempotencyKey ?? newIdempotencyKey(),
    );
  }

  Future<MessageModel> sendMessageWithKey(
    int conversationId,
    String content,
    String key,
  ) async {
    final res = await _api.post(
      'conversations/$conversationId/messages',
      body: {
        'content': content,
        'message_type': 'text',
        'idempotency_key': key,
      },
      headers: {'Idempotency-Key': key},
      mapData: asMap,
    );
    final map = res.data;
    if (map == null) throw ApiException(res.message ?? 'تعذر إرسال الرسالة.');
    return MessageModel.fromJson(map).copyWith(clientId: key);
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
      await _local?.upsertConversations(workspaceId, [model]);
    }
    return model;
  }
}
