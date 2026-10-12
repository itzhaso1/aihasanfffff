import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class ChatLockRecord {
  const ChatLockRecord({
    this.pinSalt,
    this.pinHash,
    this.appLockEnabled = false,
    this.appBiometricEnabled = false,
    this.lockedChatsBiometricEnabled = false,
    this.lockedIdsByWorkspace = const {},
  });

  static const empty = ChatLockRecord();

  final String? pinSalt;
  final String? pinHash;
  final bool appLockEnabled;
  final bool appBiometricEnabled;
  final bool lockedChatsBiometricEnabled;
  final Map<int, Set<int>> lockedIdsByWorkspace;

  bool get pinSet => pinSalt != null && pinSalt!.isNotEmpty && pinHash != null && pinHash!.isNotEmpty;

  ChatLockRecord copyWith({
    String? pinSalt,
    String? pinHash,
    bool? appLockEnabled,
    bool? appBiometricEnabled,
    bool? lockedChatsBiometricEnabled,
    Map<int, Set<int>>? lockedIdsByWorkspace,
  }) {
    return ChatLockRecord(
      pinSalt: pinSalt ?? this.pinSalt,
      pinHash: pinHash ?? this.pinHash,
      appLockEnabled: appLockEnabled ?? this.appLockEnabled,
      appBiometricEnabled: appBiometricEnabled ?? this.appBiometricEnabled,
      lockedChatsBiometricEnabled: lockedChatsBiometricEnabled ?? this.lockedChatsBiometricEnabled,
      lockedIdsByWorkspace: lockedIdsByWorkspace ?? this.lockedIdsByWorkspace,
    );
  }

  Map<String, Object?> toJson() {
    return {
      'pinSalt': pinSalt,
      'pinHash': pinHash,
      'appLock': appLockEnabled,
      'appBiometric': appBiometricEnabled,
      'chatsBiometric': lockedChatsBiometricEnabled,
      'locked': {
        for (final entry in lockedIdsByWorkspace.entries) '${entry.key}': entry.value.toList(),
      },
    };
  }

  factory ChatLockRecord.fromJson(Object? raw) {
    if (raw is! Map) return ChatLockRecord.empty;
    final locked = <int, Set<int>>{};
    final lockedRaw = raw['locked'];
    if (lockedRaw is Map) {
      for (final entry in lockedRaw.entries) {
        final workspaceId = int.tryParse('${entry.key}');
        if (workspaceId == null || entry.value is! List) continue;
        final ids = <int>{};
        for (final value in entry.value as List) {
          if (value is int) {
            ids.add(value);
          } else {
            final parsed = int.tryParse('$value');
            if (parsed != null) ids.add(parsed);
          }
        }
        locked[workspaceId] = ids;
      }
    }
    return ChatLockRecord(
      pinSalt: raw['pinSalt'] as String?,
      pinHash: raw['pinHash'] as String?,
      appLockEnabled: raw['appLock'] == true,
      appBiometricEnabled: raw['appBiometric'] == true,
      lockedChatsBiometricEnabled: raw['chatsBiometric'] == true,
      lockedIdsByWorkspace: locked,
    );
  }
}

abstract class ChatLockStore {
  Future<ChatLockRecord> read();
  Future<void> write(ChatLockRecord record);
}

class MemoryChatLockStore implements ChatLockStore {
  ChatLockRecord record = ChatLockRecord.empty;

  @override
  Future<ChatLockRecord> read() async => record;

  @override
  Future<void> write(ChatLockRecord record) async {
    this.record = record;
  }
}

class SecureChatLockStore implements ChatLockStore {
  SecureChatLockStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            );

  static const _key = 'hasim_chat_lock_v1';
  final FlutterSecureStorage _storage;

  @override
  Future<ChatLockRecord> read() async {
    try {
      final raw = await _storage.read(key: _key);
      if (raw == null || raw.isEmpty) return ChatLockRecord.empty;
      return ChatLockRecord.fromJson(jsonDecode(raw));
    } catch (_) {
      return ChatLockRecord.empty;
    }
  }

  @override
  Future<void> write(ChatLockRecord record) async {
    try {
      await _storage.write(key: _key, value: jsonEncode(record.toJson()));
    } catch (_) {}
  }
}
