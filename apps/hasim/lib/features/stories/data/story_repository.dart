import 'dart:io';

import 'package:dio/dio.dart';
import 'package:hasim/core/local_db/feed_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class StoryRepository {
  StoryRepository(
    this._api, {
    FeedLocalStore? local,
    int? Function()? workspaceIdOf,
  }) : _local = local,
       _workspaceIdOf = workspaceIdOf;

  final ApiClient _api;
  final FeedLocalStore? _local;
  final int? Function()? _workspaceIdOf;
  bool lastReadFromCache = false;

  int? get _workspace => _workspaceIdOf?.call();

  Future<List<StoryModel>> cached() async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const [];
    return _local.stories(workspaceId);
  }

  Future<List<StoryModel>> list() async {
    final workspaceId = _workspace;
    try {
      final res = await _api.get('/stories');
      final items = asMapList(res.data).map(StoryModel.fromJson).toList();
      lastReadFromCache = false;
      if (workspaceId != null) await _local?.upsertStories(workspaceId, items);
      return items;
    } on ApiException catch (error) {
      if (error.statusCode != null) rethrow;
      final saved = await cached();
      if (saved.isNotEmpty) {
        lastReadFromCache = true;
        return saved;
      }
      rethrow;
    } catch (_) {
      final saved = await cached();
      if (saved.isNotEmpty) {
        lastReadFromCache = true;
        return saved;
      }
      rethrow;
    }
  }

  Future<void> clearLocalWorkspaceData() async {
    final workspaceId = _workspace;
    if (workspaceId == null) return;
    await _local?.clearWorkspaceStories(workspaceId);
  }

  Future<StoryModel> show(int id) async {
    final res = await _api.get('/stories/$id');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'القصة غير موجودة.');
    final story = StoryModel.fromJson(map);
    final workspaceId = _workspace;
    if (workspaceId != null) await _local?.upsertStories(workspaceId, [story]);
    return story;
  }

  Future<StoryModel> create({
    required String type,
    String? caption,
    String? bodyText,
    String? backgroundColor,
    String visibility = 'workspace',
    List<int>? selectedUserIds,
    List<int>? hiddenUserIds,
    int expiresInHours = 24,
    File? file,
    void Function(int sent, int total)? onSendProgress,
  }) async {
    final form = FormData.fromMap({
      'type': type,
      'visibility': visibility,
      'expires_in_hours': expiresInHours,
      if (caption != null && caption.isNotEmpty) 'caption': caption,
      if (bodyText != null && bodyText.isNotEmpty) 'body_text': bodyText,
      if (backgroundColor != null && backgroundColor.isNotEmpty)
        'background_color': backgroundColor,
      if (selectedUserIds != null)
        for (var i = 0; i < selectedUserIds.length; i++)
          'selected_user_ids[$i]': selectedUserIds[i],
      if (hiddenUserIds != null)
        for (var i = 0; i < hiddenUserIds.length; i++)
          'hidden_user_ids[$i]': hiddenUserIds[i],
      if (file != null)
        'file': await MultipartFile.fromFile(
          file.path,
          filename: file.uri.pathSegments.last,
        ),
    });

    final res = await _api.upload(
      '/stories',
      formData: form,
      onSendProgress: onSendProgress,
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر نشر القصة.');
    final story = StoryModel.fromJson(map);
    final workspaceId = _workspace;
    if (workspaceId != null) await _local?.upsertStories(workspaceId, [story]);
    return story;
  }

  Future<StoryModel> markViewed(int id) async {
    final workspaceId = _workspace;
    if (workspaceId != null) await _local?.markStoryViewed(workspaceId, id);
    try {
      final res = await _api.post('/stories/$id/view');
      final map = asMap(res.data);
      if (map == null) {
        throw ApiException(res.message ?? 'تعذر تسجيل المشاهدة.');
      }
      final story = StoryModel.fromJson(map);
      if (workspaceId != null) {
        await _local?.upsertStories(workspaceId, [story]);
      }
      return story;
    } catch (_) {
      rethrow;
    }
  }

  Future<void> delete(int id) async {
    await _api.delete('/stories/$id');
    final workspaceId = _workspace;
    if (workspaceId != null) await _local?.markStoryDeleted(workspaceId, id);
  }

  Future<List<StoryViewerEntry>> viewers(int id) async {
    final workspaceId = _workspace;
    try {
      final res = await _api.get('/stories/$id/viewers');
      final items = asMapList(res.data).map(StoryViewerEntry.fromJson).toList();
      if (workspaceId != null) {
        await _local?.upsertViewers(workspaceId, id, items);
      }
      return items;
    } on ApiException catch (error) {
      if (error.statusCode != null) rethrow;
      if (workspaceId != null) {
        final saved =
            await _local?.viewers(workspaceId, id) ??
            const <StoryViewerEntry>[];
        if (saved.isNotEmpty) return saved;
      }
      rethrow;
    } catch (_) {
      if (workspaceId != null) {
        final saved =
            await _local?.viewers(workspaceId, id) ??
            const <StoryViewerEntry>[];
        if (saved.isNotEmpty) return saved;
      }
      rethrow;
    }
  }
}
