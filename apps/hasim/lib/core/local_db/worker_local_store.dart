import 'dart:convert';

import 'package:hasim/core/local_db/inbox_database.dart';

/// Independent Worker read models. Keyed by worker profile id, not membership.
class WorkerLocalStore {
  WorkerLocalStore(this._db);

  final InboxDatabase _db;

  Future<void> put(
    int workerProfileId,
    String resource,
    Object? payload, {
    int scopeId = 0,
  }) async {
    if (workerProfileId <= 0 || payload == null) return;
    await _db.custom(
      '''
      INSERT INTO local_worker_records (
        worker_profile_id, resource, scope_id, payload_json, cached_at
      ) VALUES (?, ?, ?, ?, ?)
      ON CONFLICT(worker_profile_id, resource, scope_id) DO UPDATE SET
        payload_json = excluded.payload_json,
        cached_at = excluded.cached_at
      ''',
      [
        workerProfileId,
        resource,
        scopeId,
        jsonEncode(payload),
        DateTime.now().millisecondsSinceEpoch,
      ],
    );
  }

  Future<Object?> get(
    int workerProfileId,
    String resource, {
    int scopeId = 0,
  }) async {
    final rows = await _db.select(
      '''
      SELECT payload_json FROM local_worker_records
      WHERE worker_profile_id = ? AND resource = ? AND scope_id = ?
      LIMIT 1
      ''',
      [workerProfileId, resource, scopeId],
    );
    if (rows.isEmpty) return null;
    try {
      return jsonDecode(rows.first['payload_json']?.toString() ?? 'null');
    } catch (_) {
      return null;
    }
  }
}
