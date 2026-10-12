import 'dart:convert';

import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/models/models.dart';

/// Stories and notifications on the same Drift database as the inbox.
class FeedLocalStore {
  FeedLocalStore(this._db);

  final InboxDatabase _db;

  static const storyLimit = 200;
  static const notificationLimit = 100;

  Future<List<StoryModel>> stories(int workspaceId, {DateTime? now}) async {
    final moment = (now ?? DateTime.now()).millisecondsSinceEpoch;
    final rows = await _db.select(
      '''
      SELECT * FROM local_stories
      WHERE workspace_id = ? AND deleted_at IS NULL
        AND (expires_at IS NULL OR expires_at > ?)
      ORDER BY created_at DESC, server_id DESC
      LIMIT ?
      ''',
      [workspaceId, moment, storyLimit],
    );
    return rows.map(_storyFromRow).toList();
  }

  Future<void> upsertStories(int workspaceId, List<StoryModel> items) async {
    for (final item in items) {
      if (item.id <= 0) continue;
      await _db.custom(
        '''
        INSERT INTO local_stories (
          workspace_id, server_id, type, caption, body_text, background_color,
          media_url, media_mime, media_size, thumbnail_url, visibility, expires_at,
          views_count, status, created_at, author_id, author_name, author_avatar,
          is_mine, viewed_by_me, deleted_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
        ON CONFLICT(workspace_id, server_id) DO UPDATE SET
          type = excluded.type,
          caption = excluded.caption,
          body_text = excluded.body_text,
          background_color = excluded.background_color,
          media_url = COALESCE(excluded.media_url, local_stories.media_url),
          media_mime = excluded.media_mime,
          media_size = excluded.media_size,
          thumbnail_url = COALESCE(excluded.thumbnail_url, local_stories.thumbnail_url),
          visibility = excluded.visibility,
          expires_at = excluded.expires_at,
          views_count = excluded.views_count,
          status = excluded.status,
          created_at = excluded.created_at,
          author_id = excluded.author_id,
          author_name = excluded.author_name,
          author_avatar = excluded.author_avatar,
          is_mine = excluded.is_mine,
          viewed_by_me = excluded.viewed_by_me,
          deleted_at = NULL
        ''',
        [
          workspaceId,
          item.id,
          item.type,
          item.caption,
          item.bodyText,
          item.backgroundColor,
          _cacheableUrl(item.mediaUrl),
          item.mediaMime,
          item.mediaSize,
          _cacheableUrl(item.thumbnailUrl),
          item.visibility,
          item.expiresAt?.millisecondsSinceEpoch,
          item.viewsCount,
          item.status,
          item.createdAt?.millisecondsSinceEpoch,
          item.author?.id,
          item.author?.name,
          item.author?.avatarPath,
          item.isMine ? 1 : 0,
          item.viewedByMe ? 1 : 0,
        ],
      );
    }
  }

  Future<void> clearWorkspaceStories(int workspaceId) async {
    await _db.delete('DELETE FROM local_stories WHERE workspace_id = ?', [
      workspaceId,
    ]);
    await _db.delete('DELETE FROM local_story_viewers WHERE workspace_id = ?', [
      workspaceId,
    ]);
  }

  Future<void> markStoryViewed(int workspaceId, int storyId) async {
    await _db.update(
      'UPDATE local_stories SET viewed_by_me = 1 WHERE workspace_id = ? AND server_id = ?',
      [workspaceId, storyId],
    );
  }

  Future<void> markStoryDeleted(int workspaceId, int storyId) async {
    await _db.update(
      'UPDATE local_stories SET deleted_at = ? WHERE workspace_id = ? AND server_id = ?',
      [DateTime.now().millisecondsSinceEpoch, workspaceId, storyId],
    );
  }

  Future<List<StoryViewerEntry>> viewers(int workspaceId, int storyId) async {
    final rows = await _db.select(
      '''
      SELECT * FROM local_story_viewers
      WHERE workspace_id = ? AND story_server_id = ?
      ORDER BY viewed_at DESC
      LIMIT 100
      ''',
      [workspaceId, storyId],
    );
    return rows
        .map(
          (row) => StoryViewerEntry(
            userId: (row['user_id'] as num?)?.toInt(),
            name: row['name']?.toString() ?? '',
            avatarUrl: row['avatar_url']?.toString(),
            viewedAt: _time(row['viewed_at']),
          ),
        )
        .toList();
  }

  Future<void> upsertViewers(
    int workspaceId,
    int storyId,
    List<StoryViewerEntry> items,
  ) async {
    await _db.delete(
      'DELETE FROM local_story_viewers WHERE workspace_id = ? AND story_server_id = ?',
      [workspaceId, storyId],
    );
    for (final item in items) {
      final userId = item.userId ?? 0;
      if (userId <= 0) continue;
      await _db.custom(
        '''
        INSERT INTO local_story_viewers (
          workspace_id, story_server_id, user_id, name, avatar_url, viewed_at
        ) VALUES (?, ?, ?, ?, ?, ?)
        ''',
        [
          workspaceId,
          storyId,
          userId,
          item.name,
          item.avatarUrl,
          item.viewedAt?.millisecondsSinceEpoch,
        ],
      );
    }
  }

  Future<List<AppNotificationModel>> notifications(int workspaceId) async {
    final rows = await _db.select(
      '''
      SELECT * FROM local_notifications
      WHERE workspace_id = ?
      ORDER BY created_at IS NULL, created_at DESC
      LIMIT ?
      ''',
      [workspaceId, notificationLimit],
    );
    return rows.map(_notificationFromRow).toList();
  }

  Future<void> upsertNotifications(
    int workspaceId,
    List<AppNotificationModel> items,
  ) async {
    for (final item in items) {
      if (item.id.isEmpty) continue;
      await _db.custom(
        '''
        INSERT INTO local_notifications (
          workspace_id, server_id, type, title, body, data_json, read_at, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(workspace_id, server_id) DO UPDATE SET
          type = excluded.type,
          title = excluded.title,
          body = excluded.body,
          data_json = excluded.data_json,
          read_at = excluded.read_at,
          created_at = excluded.created_at
        ''',
        [
          workspaceId,
          item.id,
          item.type,
          item.title,
          item.body,
          jsonEncode(_safe(item.data)),
          item.readAt?.millisecondsSinceEpoch,
          item.createdAt?.millisecondsSinceEpoch,
        ],
      );
    }
  }

  Future<void> markNotificationRead(
    int workspaceId,
    String id, {
    required bool read,
  }) async {
    await _db.update(
      'UPDATE local_notifications SET read_at = ? WHERE workspace_id = ? AND server_id = ?',
      [read ? DateTime.now().millisecondsSinceEpoch : null, workspaceId, id],
    );
  }

  Future<void> markAllNotificationsRead(int workspaceId) async {
    final now = DateTime.now().millisecondsSinceEpoch;
    await _db.update(
      'UPDATE local_notifications SET read_at = COALESCE(read_at, ?) WHERE workspace_id = ?',
      [now, workspaceId],
    );
  }

  Future<Map<String, dynamic>?> notificationPreferences(int workspaceId) async {
    final rows = await _db.select(
      'SELECT * FROM local_notification_preferences WHERE workspace_id = ? LIMIT 1',
      [workspaceId],
    );
    if (rows.isEmpty) return null;
    final row = rows.first;
    return {
      'messages': row['messages'] == 1,
      'email': row['email'] == 1,
      'marketing': row['marketing'] == 1,
    };
  }

  Future<void> saveNotificationPreferences(
    int workspaceId,
    Map<String, dynamic> prefs,
  ) async {
    await _db.custom(
      '''
      INSERT INTO local_notification_preferences (
        workspace_id, messages, email, marketing, cached_at
      ) VALUES (?, ?, ?, ?, ?)
      ON CONFLICT(workspace_id) DO UPDATE SET
        messages = excluded.messages,
        email = excluded.email,
        marketing = excluded.marketing,
        cached_at = excluded.cached_at
      ''',
      [
        workspaceId,
        prefs['messages'] == false ? 0 : 1,
        prefs['email'] == false ? 0 : 1,
        prefs['marketing'] == true ? 1 : 0,
        DateTime.now().millisecondsSinceEpoch,
      ],
    );
  }

  StoryModel _storyFromRow(Map<String, Object?> row) {
    final authorId = (row['author_id'] as num?)?.toInt();
    return StoryModel(
      id: (row['server_id'] as num).toInt(),
      type: row['type']?.toString() ?? 'text',
      caption: row['caption']?.toString(),
      bodyText: row['body_text']?.toString(),
      backgroundColor: row['background_color']?.toString(),
      mediaUrl: row['media_url']?.toString(),
      mediaMime: row['media_mime']?.toString(),
      mediaSize: (row['media_size'] as num?)?.toInt(),
      thumbnailUrl: row['thumbnail_url']?.toString(),
      visibility: row['visibility']?.toString() ?? 'workspace',
      expiresAt: _time(row['expires_at']),
      viewsCount: (row['views_count'] as num?)?.toInt() ?? 0,
      status: row['status']?.toString(),
      createdAt: _time(row['created_at']),
      author: authorId == null
          ? null
          : StoryAuthorModel(
              id: authorId,
              name: row['author_name']?.toString() ?? '',
              avatarPath: row['author_avatar']?.toString(),
            ),
      isMine: row['is_mine'] == 1,
      viewedByMe: row['viewed_by_me'] == 1 || row['is_mine'] == 1,
    );
  }

  AppNotificationModel _notificationFromRow(Map<String, Object?> row) {
    Map<String, dynamic> data = {};
    try {
      final decoded = jsonDecode(row['data_json']?.toString() ?? '{}');
      if (decoded is Map) data = Map<String, dynamic>.from(decoded);
    } catch (_) {
      data = {};
    }
    final title = row['title']?.toString() ?? '';
    final body = row['body']?.toString() ?? '';
    data.putIfAbsent('title', () => title);
    data.putIfAbsent('body', () => body);
    return AppNotificationModel(
      id: row['server_id']?.toString() ?? '',
      type: row['type']?.toString() ?? '',
      data: data,
      readAt: _time(row['read_at']),
      createdAt: _time(row['created_at']),
    );
  }

  Map<String, dynamic> _safe(Map<String, dynamic> raw) {
    const blocked = {
      'secret',
      'token',
      'password',
      'credential',
      'authorization',
    };
    return {
      for (final entry in raw.entries)
        if (!blocked.contains(entry.key.toLowerCase())) entry.key: entry.value,
    };
  }

  String? _cacheableUrl(String? url) {
    if (url == null || url.isEmpty) return null;
    final lower = url.toLowerCase();
    if (lower.contains('x-amz-') ||
        lower.contains('signature=') ||
        lower.contains('expires=')) {
      return null;
    }
    return url;
  }

  static DateTime? _time(Object? value) {
    if (value is num) return DateTime.fromMillisecondsSinceEpoch(value.toInt());
    return null;
  }
}
