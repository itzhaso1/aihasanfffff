import 'dart:io';
import 'dart:math' as math;

import 'package:dio/dio.dart';
import 'package:hasim/core/local_db/conversation_reconciler.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/network/api_response.dart';
import 'package:hasim/core/utils/idempotency.dart';

class ConversationRepository {
  ConversationRepository(this._api, {InboxLocalStore? local}) : _local = local;

  final ApiClient _api;
  final InboxLocalStore? _local;

  Future<List<ConversationModel>> cachedConversations(
    int workspaceId, {
    String filter = 'all',
    String? channel,
    String? search,
  }) async {
    final store = _local;
    if (store == null) return const [];
    return store.getConversations(
      workspaceId,
      filter: filter,
      channel: channel,
      search: search,
    );
  }

  Future<List<MessageModel>> cachedMessages(int workspaceId, int conversationId) async {
    return _local?.getMessages(workspaceId, conversationId) ?? const [];
  }

  Future<ConversationModel?> cachedConversation(int workspaceId, int conversationId) {
    return _local?.getConversation(workspaceId, conversationId) ?? Future.value();
  }

  Future<({List<ConversationModel> items, String? nextCursor})> list({
    String filter = 'all',
    String? channel,
    String? search,
    String? cursor,
    int? workspaceId,
  }) async {
    try {
      final res = await _api.get(
        '/conversations',
        query: {
          'filter': filter,
          if (channel != null && channel.isNotEmpty) 'channel': channel,
          if (search != null && search.isNotEmpty) 'search': search,
          if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
          'per_page': 20,
        },
      );
      final items = asMapList(res.data).map(ConversationModel.fromJson).toList();
      if (workspaceId != null) {
        await _local?.upsertConversations(workspaceId, items);
        if (cursor == null || cursor.isEmpty) {
          await _local?.setSyncCursor(
            workspaceId,
            ConversationReconciler.syncResource(filter: filter, channel: channel, search: search),
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
        );
        if (cached.isNotEmpty) {
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
      if (map == null) throw ApiException(res.message ?? 'المحادثة غير موجودة.');
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
        await _local?.upsertMessages(workspaceId, conversationId, chronological);
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
          return (items: cached.reversed.toList(), nextCursor: null);
        }
      }
      rethrow;
    }
  }

  /// Always reuses [idempotencyKey] for both header and body. Do not call
  /// [_api.post] with `idempotent: true` here — that would mint a second key.
  Future<MessageModel> sendMessage(int conversationId, String content, {String? idempotencyKey}) {
    return sendMessageWithKey(
      conversationId,
      content,
      idempotencyKey ?? newIdempotencyKey(),
    );
  }

  Future<MessageModel> sendMessageWithKey(int conversationId, String content, String key) async {
    final headers = {'Idempotency-Key': key};
    try {
      final res = await _api.raw.post<Map<String, dynamic>>(
        'conversations/$conversationId/messages',
        data: {
          'content': content,
          'message_type': 'text',
          'idempotency_key': key,
        },
        options: Options(headers: headers),
      );
      final parsed = ApiResponse.fromJson(res.data ?? {}, asMap);
      final map = parsed.data;
      if (map == null) throw ApiException(parsed.message ?? 'تعذر إرسال الرسالة.');
      return MessageModel.fromJson(map).copyWith(clientId: key);
    } on DioException catch (e) {
      final data = e.response?.data;
      if (data is Map<String, dynamic> && data['message'] != null) {
        throw ApiException(data['message'].toString(), statusCode: e.response?.statusCode);
      }
      throw ApiException('تعذر إرسال الرسالة.', statusCode: e.response?.statusCode);
    }
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

  Future<void> markRead(int conversationId, {int? messageId, int? workspaceId}) async {
    await _api.post(
      '/conversations/$conversationId/read',
      body: {
        'message_id': ?messageId,
      },
    );
    if (workspaceId != null) {
      await _local?.markConversationRead(workspaceId, conversationId);
    }
  }

  Future<HomeSnapshot> overlayHomeUnread(int workspaceId, HomeSnapshot snapshot) async {
    final cleared = await _local?.staleServerUnreadClearedCount(workspaceId) ?? 0;
    if (cleared == 0) return snapshot;
    return snapshot.copyWith(
      unreadConversations: math.max(0, snapshot.unreadConversations - cleared),
    );
  }

  Future<void> archive(int conversationId, {bool archived = true, int? workspaceId}) async {
    await _api.post('/conversations/$conversationId/archive', body: {'archived': archived});
    if (workspaceId != null) {
      final existing = await cachedConversation(workspaceId, conversationId);
      if (existing != null) {
        await _local?.upsertConversations(workspaceId, [existing.copyWith(archived: archived)]);
      }
    }
  }

  Future<void> mute(int conversationId, {bool muted = true, int? workspaceId}) async {
    await _api.post('/conversations/$conversationId/mute', body: {'muted': muted});
    if (workspaceId != null) {
      final existing = await cachedConversation(workspaceId, conversationId);
      if (existing != null) {
        await _local?.upsertConversations(workspaceId, [existing.copyWith(muted: muted)]);
      }
    }
  }

  Future<MessageAttachmentModel> uploadAttachment(int messageId, File file) async {
    final form = FormData.fromMap({
      'file': await MultipartFile.fromFile(file.path, filename: file.uri.pathSegments.last),
    });
    final res = await _api.upload('/messages/$messageId/attachments', formData: form);
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر رفع المرفق.');
    return MessageAttachmentModel.fromJson(map);
  }
}
