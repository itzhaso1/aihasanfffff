
import 'package:hasim/core/local_db/feed_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';

class NotificationRepository {
  NotificationRepository(
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

  Future<List<AppNotificationModel>> cached() async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const [];
    return _local.notifications(workspaceId);
  }

  Future<List<AppNotificationModel>> list() async {
    final workspaceId = _workspace;
    try {
      final res = await _api.get('/notifications');
      final items = asMapList(res.data).map(AppNotificationModel.fromJson).toList();
      lastReadFromCache = false;
      if (workspaceId != null) await _local?.upsertNotifications(workspaceId, items);
      return items;
    } catch (_) {
      final saved = await cached();
      if (saved.isNotEmpty) {
        lastReadFromCache = true;
        return saved;
      }
      rethrow;
    }
  }

  Future<void> markRead(String id) async {
    final workspaceId = _workspace;
    if (workspaceId != null) {
      await _local?.markNotificationRead(workspaceId, id, read: true);
    }
    try {
      await _api.post('/notifications/$id/read');
    } catch (_) {
      if (workspaceId != null) {
        await _local?.markNotificationRead(workspaceId, id, read: false);
      }
      rethrow;
    }
  }

  Future<void> markAllRead() async {
    await _api.post('/notifications/read-all');
    final workspaceId = _workspace;
    if (workspaceId != null) await _local?.markAllNotificationsRead(workspaceId);
  }

  Future<Map<String, dynamic>> preferences() async {
    final workspaceId = _workspace;
    try {
      final res = await _api.get('/notification-preferences');
      final prefs = asMap(res.data) ?? {};
      if (workspaceId != null) await _local?.saveNotificationPreferences(workspaceId, prefs);
      return prefs;
    } catch (_) {
      if (workspaceId != null) {
        final saved = await _local?.notificationPreferences(workspaceId);
        if (saved != null) return saved;
      }
      rethrow;
    }
  }

  Future<Map<String, dynamic>> cachedPreferences() async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return {};
    return await _local.notificationPreferences(workspaceId) ?? {};
  }

  Future<Map<String, dynamic>> updatePreferences(Map<String, dynamic> body) async {
    final res = await _api.put('/notification-preferences', body: body);
    final prefs = asMap(res.data) ?? body;
    final workspaceId = _workspace;
    if (workspaceId != null) await _local?.saveNotificationPreferences(workspaceId, prefs);
    return prefs;
  }
}
