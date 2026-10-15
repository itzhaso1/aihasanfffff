
import 'dart:async';

import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';

abstract class RealtimeService {
  Stream<MessageModel> get messageCreated;
  Stream<void> get conversationsChanged;
  Future<void> start();
  Future<void> stop();
  void watchConversation(int? conversationId);
}

/// Practical realtime when Laravel broadcast driver is not live (log/local).
class PollingRealtimeService implements RealtimeService {
  PollingRealtimeService(this._repo, {int? Function()? workspaceIdOf}) : _workspaceIdOf = workspaceIdOf;

  final ConversationRepository _repo;
  final int? Function()? _workspaceIdOf;
  final _messageController = StreamController<MessageModel>.broadcast();
  final _listController = StreamController<void>.broadcast();
  Timer? _listTimer;
  Timer? _chatTimer;
  int? _watchingConversationId;
  final Set<int> _deliveredMessageIds = <int>{};
  int _pollGeneration = 0;

  @override
  Stream<MessageModel> get messageCreated => _messageController.stream;

  @override
  Stream<void> get conversationsChanged => _listController.stream;

  @override
  Future<void> start() async {
    _listTimer?.cancel();
    _listTimer = Timer.periodic(const Duration(seconds: 12), (_) {
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
    _chatTimer?.cancel();
    if (conversationId == null) return;
    _chatTimer = Timer.periodic(const Duration(seconds: 4), (_) => _pollChat());
    _pollChat();
  }

  Future<void> _pollChat() async {
    final generation = _pollGeneration;
    final id = _watchingConversationId;
    if (id == null) return;
    try {
      final page = await _repo.messages(id, workspaceId: _workspaceIdOf?.call());
      if (generation != _pollGeneration || _watchingConversationId != id) return;
      if (page.items.isEmpty) return;
      // Deliver by id, not by "newer than the newest id seen". A fast AI
      // reply can be page[0] before the customer row is present; the lower
      // customer id must still be delivered on a later poll.
      final ordered = page.items.reversed.where((m) => m.id > 0);
      for (final message in ordered) {
        if (_deliveredMessageIds.add(message.id)) {
          _messageController.add(message);
        }
      }
    } catch (_) {
      // ignore transient poll errors
    }
  }
}

class NoopRealtimeService implements RealtimeService {
  final _messageController = StreamController<MessageModel>.broadcast();
  final _listController = StreamController<void>.broadcast();

  @override
  Stream<MessageModel> get messageCreated => _messageController.stream;
  @override
  Stream<void> get conversationsChanged => _listController.stream;
  @override
  Future<void> start() async {}
  @override
  Future<void> stop() async {}
  @override
  void watchConversation(int? conversationId) {}
}
