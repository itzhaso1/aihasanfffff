import 'dart:io';

import 'package:dio/dio.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class StoryRepository {
  StoryRepository(this._api);
  final ApiClient _api;

  Future<List<StoryModel>> list() async {
    final res = await _api.get('/stories');
    return asMapList(res.data).map(StoryModel.fromJson).toList();
  }

  Future<StoryModel> show(int id) async {
    final res = await _api.get('/stories/$id');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'القصة غير موجودة.');
    return StoryModel.fromJson(map);
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
      if (backgroundColor != null && backgroundColor.isNotEmpty) 'background_color': backgroundColor,
      if (selectedUserIds != null)
        for (var i = 0; i < selectedUserIds.length; i++) 'selected_user_ids[$i]': selectedUserIds[i],
      if (hiddenUserIds != null)
        for (var i = 0; i < hiddenUserIds.length; i++) 'hidden_user_ids[$i]': hiddenUserIds[i],
      if (file != null)
        'file': await MultipartFile.fromFile(file.path, filename: file.uri.pathSegments.last),
    });

    final res = await _api.upload(
      '/stories',
      formData: form,
      onSendProgress: onSendProgress,
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر نشر القصة.');
    return StoryModel.fromJson(map);
  }

  Future<StoryModel> markViewed(int id) async {
    final res = await _api.post('/stories/$id/view');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تسجيل المشاهدة.');
    return StoryModel.fromJson(map);
  }

  Future<void> delete(int id) async {
    await _api.delete('/stories/$id');
  }

  Future<List<Map<String, dynamic>>> viewers(int id) async {
    final res = await _api.get('/stories/$id/viewers');
    return asMapList(res.data);
  }
}
