import 'dart:convert';

import 'package:hasim/core/local_db/inbox_database.dart';

/// Company settings that already have a mobile read contract.
class SettingsLocalStore {
  SettingsLocalStore(this._db);

  final InboxDatabase _db;

  Future<void> put(int workspaceId, String resource, Object payload) async {
    if (workspaceId <= 0) return;
    await _db.custom(
      '''
      INSERT INTO local_company_cache (workspace_id, resource, payload_json, cached_at)
      VALUES (?, ?, ?, ?)
      ON CONFLICT(workspace_id, resource) DO UPDATE SET
        payload_json = excluded.payload_json,
        cached_at = excluded.cached_at
      ''',
      [workspaceId, resource, jsonEncode(payload), DateTime.now().millisecondsSinceEpoch],
    );
  }

  Future<Object?> get(int workspaceId, String resource) async {
    final rows = await _db.select(
      'SELECT payload_json FROM local_company_cache WHERE workspace_id = ? AND resource = ? LIMIT 1',
      [workspaceId, resource],
    );
    if (rows.isEmpty) return null;
    try {
      return jsonDecode(rows.first['payload_json']?.toString() ?? 'null');
    } catch (_) {
      return null;
    }
  }
}
