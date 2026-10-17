import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/core/storage/prefs_store.dart';

/// تثبيت ونجمة وحذف محلي («حذف لدي») للمحادثات والرسائل.
///
/// لا توجد واجهة خادم لهذه العلامات، لذلك تُحفظ على هذا الجهاز لكل مساحة عمل.
class ConversationMarks {
  const ConversationMarks({
    this.pinnedIds = const {},
    this.starredIds = const {},
    this.hiddenConversations = const {},
    this.pinnedMessageKeys = const {},
    this.starredMessageKeys = const {},
    this.hiddenMessageKeys = const {},
  });

  final Set<int> pinnedIds;
  final Set<int> starredIds;

  /// معرّف المحادثة ← وقت آخر رسالة (ms) لحظة الحذف. تعود عند وصول رسالة أحدث.
  final Map<int, int> hiddenConversations;
  final Set<String> pinnedMessageKeys;
  final Set<String> starredMessageKeys;
  final Set<String> hiddenMessageKeys;

  bool isPinned(int conversationId) => pinnedIds.contains(conversationId);

  bool isStarred(int conversationId) => starredIds.contains(conversationId);

  bool isHidden(ConversationModel conversation) {
    final hiddenAt = hiddenConversations[conversation.id];
    if (hiddenAt == null) {
      return false;
    }
    final lastMessageAt =
        conversation.lastMessageAt?.millisecondsSinceEpoch ?? 0;
    return lastMessageAt <= hiddenAt;
  }

  bool isMessagePinned(MessageModel message) =>
      pinnedMessageKeys.contains(messageKey(message));

  bool isMessageStarred(MessageModel message) =>
      starredMessageKeys.contains(messageKey(message));

  bool isMessageHidden(MessageModel message) =>
      hiddenMessageKeys.contains(messageKey(message));

  ConversationMarks copyWith({
    Set<int>? pinnedIds,
    Set<int>? starredIds,
    Map<int, int>? hiddenConversations,
    Set<String>? pinnedMessageKeys,
    Set<String>? starredMessageKeys,
    Set<String>? hiddenMessageKeys,
  }) {
    return ConversationMarks(
      pinnedIds: pinnedIds ?? this.pinnedIds,
      starredIds: starredIds ?? this.starredIds,
      hiddenConversations: hiddenConversations ?? this.hiddenConversations,
      pinnedMessageKeys: pinnedMessageKeys ?? this.pinnedMessageKeys,
      starredMessageKeys: starredMessageKeys ?? this.starredMessageKeys,
      hiddenMessageKeys: hiddenMessageKeys ?? this.hiddenMessageKeys,
    );
  }

  /// مفتاح ثابت للرسالة: معرّف الخادم، أو مفتاح العميل قبل التأكيد.
  static String messageKey(MessageModel message) {
    if (message.id > 0) {
      return '${message.conversationId}:${message.id}';
    }
    return '${message.conversationId}:c:${message.clientId ?? message.id}';
  }
}

class ConversationMarksController extends StateNotifier<ConversationMarks> {
  ConversationMarksController(this._prefs, this._workspaceId)
    : super(_read(_prefs, _workspaceId));

  final PrefsStore _prefs;
  final int _workspaceId;

  Future<void> togglePinned(int conversationId) {
    return _save(
      state.copyWith(pinnedIds: _toggle(state.pinnedIds, conversationId)),
    );
  }

  Future<void> toggleStarred(int conversationId) {
    return _save(
      state.copyWith(starredIds: _toggle(state.starredIds, conversationId)),
    );
  }

  Future<void> hideConversation(ConversationModel conversation) {
    return _save(
      state.copyWith(
        hiddenConversations: {
          ...state.hiddenConversations,
          conversation.id:
              conversation.lastMessageAt?.millisecondsSinceEpoch ?? 0,
        },
      ),
    );
  }

  Future<void> toggleMessagePinned(MessageModel message) {
    return _save(
      state.copyWith(
        pinnedMessageKeys: _toggle(
          state.pinnedMessageKeys,
          ConversationMarks.messageKey(message),
        ),
      ),
    );
  }

  Future<void> toggleMessageStarred(MessageModel message) {
    return _save(
      state.copyWith(
        starredMessageKeys: _toggle(
          state.starredMessageKeys,
          ConversationMarks.messageKey(message),
        ),
      ),
    );
  }

  Future<void> setMessagesStarred(
    Iterable<MessageModel> messages, {
    required bool starred,
  }) {
    final keys = messages.map(ConversationMarks.messageKey).toSet();
    final next = {...state.starredMessageKeys};
    if (starred) {
      next.addAll(keys);
    } else {
      next.removeAll(keys);
    }
    return _save(state.copyWith(starredMessageKeys: next));
  }

  Future<void> hideMessages(Iterable<MessageModel> messages) {
    final keys = messages.map(ConversationMarks.messageKey).toSet();
    return _save(
      state.copyWith(
        hiddenMessageKeys: {...state.hiddenMessageKeys, ...keys},
        pinnedMessageKeys: state.pinnedMessageKeys.difference(keys),
        starredMessageKeys: state.starredMessageKeys.difference(keys),
      ),
    );
  }

  Future<void> _save(ConversationMarks next) async {
    state = next;
    await _prefs.setConversationMarks(_workspaceId, {
      if (next.pinnedIds.isNotEmpty) 'pinned': next.pinnedIds.toList(),
      if (next.starredIds.isNotEmpty) 'starred': next.starredIds.toList(),
      if (next.hiddenConversations.isNotEmpty)
        'hidden': {
          for (final entry in next.hiddenConversations.entries)
            '${entry.key}': entry.value,
        },
      if (next.pinnedMessageKeys.isNotEmpty)
        'pinned_messages': next.pinnedMessageKeys.toList(),
      if (next.starredMessageKeys.isNotEmpty)
        'starred_messages': next.starredMessageKeys.toList(),
      if (next.hiddenMessageKeys.isNotEmpty)
        'hidden_messages': next.hiddenMessageKeys.toList(),
    });
  }

  static ConversationMarks _read(PrefsStore prefs, int workspaceId) {
    final raw = prefs.conversationMarks(workspaceId);
    return ConversationMarks(
      pinnedIds: _intSet(raw['pinned']),
      starredIds: _intSet(raw['starred']),
      hiddenConversations: _hiddenMap(raw['hidden']),
      pinnedMessageKeys: _stringSet(raw['pinned_messages']),
      starredMessageKeys: _stringSet(raw['starred_messages']),
      hiddenMessageKeys: _stringSet(raw['hidden_messages']),
    );
  }

  static Set<T> _toggle<T>(Set<T> current, T value) {
    final next = {...current};
    if (!next.add(value)) {
      next.remove(value);
    }
    return next;
  }

  static Set<int> _intSet(Object? raw) {
    if (raw is! List) {
      return {};
    }
    return {
      for (final value in raw)
        if (int.tryParse('$value') case final int id) id,
    };
  }

  static Map<int, int> _hiddenMap(Object? raw) {
    if (raw is! Map) {
      return {};
    }
    final hidden = <int, int>{};
    for (final entry in raw.entries) {
      final id = int.tryParse('${entry.key}');
      final at = int.tryParse('${entry.value}');
      if (id != null && at != null) {
        hidden[id] = at;
      }
    }
    return hidden;
  }

  static Set<String> _stringSet(Object? raw) {
    if (raw is! List) {
      return {};
    }
    return {
      for (final value in raw)
        if (value != null && '$value'.trim().isNotEmpty) '$value',
    };
  }
}

final conversationMarksProvider =
    StateNotifierProvider<ConversationMarksController, ConversationMarks>((
      ref,
    ) {
      return ConversationMarksController(
        ref.watch(prefsStoreProvider),
        ref.watch(activeWorkspaceIdProvider) ?? 0,
      );
    });
