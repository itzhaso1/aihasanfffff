import 'dart:async';

import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';

abstract class RealtimeService {
  Stream<MessageModel> get messageCreated;
  Stream<void> get conversationsChanged;
  Stream<Map<String, dynamic>> get typingActivity;
  Stream<Map<String, dynamic>> get presenceActivity;
  Future<void> start();
  Future<void> stop();
  void watchConversation(int? conversationId);
  void triggerPoll();
}

/// Practical realtime when Laravel broadcast driver is not live (log/local)
/// or as a seamless fallback when WebSocket drops.
class PollingRealtimeService implements RealtimeService {
  PollingRealtimeService(this._repo, {int? Function()? workspaceIdOf})
    : _workspaceIdOf = workspaceIdOf;

  final ConversationRepository _repo;
  final int? Function()? _workspaceIdOf;
  final _messageController = StreamController<MessageModel>.broadcast();
  final _listController = StreamController<void>.broadcast();
  final _typingController = StreamController<Map<String, dynamic>>.broadcast();
  final _presenceController = StreamController<Map<String, dynamic>>.broadcast();
  Timer? _listTimer;
  Timer? _chatTimer;
  int? _watchingConversationId;
  final Set<int> _deliveredMessageIds = <int>{};
  int _lastMaxMessageId = 0;
  int _pollGeneration = 0;

  /// When true (e.g. WebSocket is active and streaming), periodic poll calls are skipped
  /// to save CPU, network, and prevent constant SQLite re-reads.
  bool paused = false;

  @override
  Stream<MessageModel> get messageCreated => _messageController.stream;

  @override
  Stream<void> get conversationsChanged => _listController.stream;

  @override
  Stream<Map<String, dynamic>> get typingActivity => _typingController.stream;

  @override
  Stream<Map<String, dynamic>> get presenceActivity => _presenceController.stream;

  @override
  Future<void> start() async {
    _listTimer?.cancel();
    _listTimer = Timer.periodic(const Duration(milliseconds: 8000), (_) {
      if (paused) return;
      if (_watchingConversationId != null) return;
      _listController.add(null);
    });
  }

  @override
  Future<void> stop() async {
    _pollGeneration++;
    _listTimer?.cancel();
    _chatTimer?.cancel();
  }

  @override
  void watchConversation(int? conversationId) {
    _pollGeneration++;
    _watchingConversationId = conversationId;
    _deliveredMessageIds.clear();
    _lastMaxMessageId = 0;
    _chatTimer?.cancel();
    if (conversationId == null || conversationId <= 0) {
      _listController.add(null);
      return;
    }
    _chatTimer = Timer.periodic(
      const Duration(milliseconds: 3500),
      (_) {
        if (!paused) _pollChat();
      },
    );
    if (!paused) {
      _pollChat();
    }
  }

  @override
  void triggerPoll() {
    _pollChat();
    _listController.add(null);
  }

  Future<void> _pollChat() async {
    final generation = _pollGeneration;
    final id = _watchingConversationId;
    if (id == null) return;
    try {
      final page = await _repo.messages(
        id,
        afterId: _lastMaxMessageId > 0 ? _lastMaxMessageId : null,
        workspaceId: _workspaceIdOf?.call(),
      );
      if (generation != _pollGeneration || _watchingConversationId != id) {
        return;
      }
      if (page.items.isEmpty) return;

      final ordered = _lastMaxMessageId > 0
          ? page.items.where((m) => m.id > 0)
          : page.items.reversed.where((m) => m.id > 0);

      var anyNew = false;
      for (final message in ordered) {
        if (message.id > _lastMaxMessageId) {
          _lastMaxMessageId = message.id;
        }
        if (_deliveredMessageIds.add(message.id)) {
          _messageController.add(message);
          anyNew = true;
        }
      }
      if (anyNew) {
        _listController.add(null);
      }
    } catch (_) {
      // ignore transient poll errors
    }
  }
}

class NoopRealtimeService implements RealtimeService {
  final _messageController = StreamController<MessageModel>.broadcast();
  final _listController = StreamController<void>.broadcast();
  final _typingController = StreamController<Map<String, dynamic>>.broadcast();
  final _presenceController = StreamController<Map<String, dynamic>>.broadcast();

  @override
  Stream<MessageModel> get messageCreated => _messageController.stream;
  @override
  Stream<void> get conversationsChanged => _listController.stream;

  @override
  Stream<Map<String, dynamic>> get typingActivity => _typingController.stream;
  @override
  Stream<Map<String, dynamic>> get presenceActivity => _presenceController.stream;
  @override
  Future<void> start() async {}
  @override
  Future<void> stop() async {}
  @override
  void watchConversation(int? conversationId) {}
  @override
  void triggerPoll() {}
}
