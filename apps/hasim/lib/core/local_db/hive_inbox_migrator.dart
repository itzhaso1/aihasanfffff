import 'dart:convert';

import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hive_flutter/hive_flutter.dart';

class HiveMigrationReport {
  const HiveMigrationReport({
    this.importedConversations = 0,
    this.importedMessages = 0,
    this.discardedKeys = 0,
  });

  final int importedConversations;
  final int importedMessages;
  final int discardedKeys;
}

/// One-time import of Phase 7A workspace-scoped Hive blobs into Drift.
/// Unscoped keys (`list`, `c_{id}`) cannot be attributed to a tenant and are discarded.
class HiveInboxMigrator {
  static final _conversationsKey = RegExp(r'^workspace:(\d+):conversations$');
  static final _messagesKey = RegExp(r'^workspace:(\d+):conversation:(\d+):messages$');

  static int? conversationsWorkspaceId(String key) {
    final match = _conversationsKey.firstMatch(key);
    if (match == null) return null;
    return int.tryParse(match.group(1)!);
  }

  static ({int workspaceId, int conversationId})? messagesIdentity(String key) {
    final match = _messagesKey.firstMatch(key);
    if (match == null) return null;
    final workspaceId = int.tryParse(match.group(1)!);
    final conversationId = int.tryParse(match.group(2)!);
    if (workspaceId == null || conversationId == null) return null;
    return (workspaceId: workspaceId, conversationId: conversationId);
  }

  static Future<HiveMigrationReport> importMaps({
    required InboxLocalStore store,
    required Map<String, String> conversations,
    required Map<String, String> messages,
  }) async {
    var importedConversations = 0;
    var importedMessages = 0;
    var discardedKeys = 0;

    for (final entry in conversations.entries) {
      final workspaceId = conversationsWorkspaceId(entry.key);
      if (workspaceId == null) {
        discardedKeys++;
        continue;
      }
      final items = _decodeConversations(entry.value);
      if (items.isEmpty) continue;
      await store.upsertConversations(workspaceId, items);
      importedConversations += items.length;
    }

    for (final entry in messages.entries) {
      final identity = messagesIdentity(entry.key);
      if (identity == null) {
        discardedKeys++;
        continue;
      }
      final items = _decodeMessages(entry.value);
      if (items.isEmpty) continue;
      await store.upsertMessages(identity.workspaceId, identity.conversationId, items);
      importedMessages += items.length;
    }

    return HiveMigrationReport(
      importedConversations: importedConversations,
      importedMessages: importedMessages,
      discardedKeys: discardedKeys,
    );
  }

  /// Opens leftover Hive inbox boxes once, imports scoped rows, then deletes the boxes.
  static Future<HiveMigrationReport> migrateIfNeeded(InboxLocalStore store) async {
    try {
      await Hive.initFlutter();
    } catch (_) {
      return const HiveMigrationReport();
    }

    final conversationEntries = <String, String>{};
    final messageEntries = <String, String>{};

    if (await Hive.boxExists('conversations_cache')) {
      final box = await Hive.openBox('conversations_cache');
      for (final key in box.keys) {
        final raw = box.get(key);
        if (raw is String && raw.isNotEmpty) {
          conversationEntries[key.toString()] = raw;
        }
      }
      await box.deleteFromDisk();
    }

    if (await Hive.boxExists('messages_cache')) {
      final box = await Hive.openBox('messages_cache');
      for (final key in box.keys) {
        final raw = box.get(key);
        if (raw is String && raw.isNotEmpty) {
          messageEntries[key.toString()] = raw;
        }
      }
      await box.deleteFromDisk();
    }

    if (conversationEntries.isEmpty && messageEntries.isEmpty) {
      return const HiveMigrationReport();
    }
    return importMaps(
      store: store,
      conversations: conversationEntries,
      messages: messageEntries,
    );
  }

  static List<ConversationModel> _decodeConversations(String raw) {
    try {
      final list = jsonDecode(raw);
      if (list is! List) return const [];
      return list
          .whereType<Map>()
          .map((e) => ConversationModel.fromJson(Map<String, dynamic>.from(e)))
          .toList();
    } catch (_) {
      return const [];
    }
  }

  static List<MessageModel> _decodeMessages(String raw) {
    try {
      final list = jsonDecode(raw);
      if (list is! List) return const [];
      return list
          .whereType<Map>()
          .map((e) => MessageModel.fromJson(Map<String, dynamic>.from(e)))
          .toList();
    } catch (_) {
      return const [];
    }
  }
}
