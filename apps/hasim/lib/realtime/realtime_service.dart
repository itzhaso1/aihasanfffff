import 'dart:async';

import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/realtime/reverb_websocket_client.dart';

abstract class RealtimeService {
  Stream<MessageModel> get messageCreated;
  Stream<ConversationModel> get conversationUpdated => const Stream.empty();
  Stream<MessageStatusUpdate> get messageStatusUpdated => const Stream.empty();
  Stream<RealtimeConnectionState> get connectionStateStream =>
      const Stream.empty();
  Stream<void> get conversationsChanged;
  Stream<void> get reconnectSyncRequested => const Stream.empty();
  Stream<Map<String, dynamic>> get typingActivity;
  Stream<Map<String, dynamic>> get presenceActivity;
  bool get isWebSocketConnected => false;
  RealtimeConnectionState get currentConnectionState =>
      isWebSocketConnected
      ? RealtimeConnectionState.connected
      : RealtimeConnectionState.disconnected;
  int? get activeConversationId => null;
  Future<void> start();
  Future<void> stop();
  void updateWorkspace(int? workspaceId) {}
  void watchConversation(int? conversationId);
  void unwatchConversation(int conversationId);
  void setSeedMaxMessageId(int conversationId, int maxServerId) {}
  Future<void> catchUpConversation(int conversationId, {int afterId = 0}) async {}
  void triggerPoll();
  void dispose();
}

/// Practical realtime when Laravel broadcast driver is not live (log/local)
/// or as a seamless fallback when WebSocket drops.
class PollingRealtimeService implements RealtimeService {
  PollingRealtimeService(this._repo, {int? Function()? workspaceIdOf})
    : _workspaceIdOf = workspaceIdOf;

  final ConversationRepository _repo;
  final int? Function()? _workspaceIdOf;
  final _messageController = StreamController<MessageModel>.broadcast();
  final _conversationController =
      StreamController<ConversationModel>.broadcast();
  final _statusController = StreamController<MessageStatusUpdate>.broadcast();
  final _listController = StreamController<void>.broadcast();
  final _reconnectController = StreamController<void>.broadcast();
  final _typingController = StreamController<Map<String, dynamic>>.broadcast();
  final _presenceController = StreamController<Map<String, dynamic>>.broadcast();
  Timer? _listTimer;
  Timer? _chatTimer;
  int? _watchingConversationId;
  final Set<int> _deliveredMessageIds = <int>{};
  int _lastMaxMessageId = 0;
  int _pollGeneration = 0;
  bool _disposed = false;

  /// When true (e.g. WebSocket is active and streaming), periodic poll calls are skipped
  /// to save CPU, network, and prevent constant SQLite re-reads.
  bool paused = false;

  @override
  Stream<MessageModel> get messageCreated => _messageController.stream;

  @override
  Stream<ConversationModel> get conversationUpdated =>
      _conversationController.stream;

  @override
  Stream<MessageStatusUpdate> get messageStatusUpdated =>
      _statusController.stream;

  @override
  Stream<void> get conversationsChanged => _listController.stream;

  @override
  Stream<void> get reconnectSyncRequested => _reconnectController.stream;

  @override
  Stream<Map<String, dynamic>> get typingActivity => _typingController.stream;

  @override
  Stream<Map<String, dynamic>> get presenceActivity => _presenceController.stream;

  @override
  bool get isWebSocketConnected => false;

  @override
  Stream<RealtimeConnectionState> get connectionStateStream =>
      const Stream.empty();

  @override
  RealtimeConnectionState get currentConnectionState =>
      RealtimeConnectionState.disconnected;

  @override
  int? get activeConversationId => _watchingConversationId;

  @override
  void setSeedMaxMessageId(int conversationId, int maxServerId) {
    if (_watchingConversationId == conversationId &&
        maxServerId > _lastMaxMessageId) {
      _lastMaxMessageId = maxServerId;
    }
  }

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
  void updateWorkspace(int? workspaceId) {
    _pollGeneration++;
    _deliveredMessageIds.clear();
    _lastMaxMessageId = 0;
    if (_watchingConversationId != null && !paused) {
      _pollChat();
    } else if (!paused) {
      _listController.add(null);
    }
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
  void unwatchConversation(int conversationId) {
    if (_watchingConversationId == conversationId) {
      watchConversation(null);
    }
  }

  @override
  void triggerPoll() {
    _pollChat();
    _listController.add(null);
  }

  Future<void> _pollChat({bool emitListOnNew = true}) async {
    final generation = _pollGeneration;
    final id = _watchingConversationId;
    if (id == null) return;
    try {
      var pagesFetched = 0;
      var anyNew = false;

      do {
        final after = _lastMaxMessageId > 0 ? _lastMaxMessageId : null;
        final page = await _repo.messages(
          id,
          afterId: after,
          workspaceId: _workspaceIdOf?.call(),
        );
        if (generation != _pollGeneration || _watchingConversationId != id) {
          return;
        }
        if (page.items.isEmpty) break;

        final ordered = after != null
            ? page.items.where((m) => m.id > 0)
            : page.items.reversed.where((m) => m.id > 0);

        for (final message in ordered) {
          if (message.id > _lastMaxMessageId) {
            _lastMaxMessageId = message.id;
          }
          if (_deliveredMessageIds.add(message.id)) {
            _messageController.add(message);
            anyNew = true;
          }
        }

        pagesFetched++;
        if (after == null || page.items.length < 100 || pagesFetched >= 5) {
          break;
        }
      } while (true);

      if (anyNew && emitListOnNew) {
        _listController.add(null);
      }
    } catch (_) {
      // ignore transient poll errors
    }
  }

  /// Run an explicit incremental catch-up using known [afterId] without flipping polling timers.
  @override
  Future<void> catchUpConversation(int conversationId, {int afterId = 0}) async {
    if (_watchingConversationId != conversationId) return;
    setSeedMaxMessageId(conversationId, afterId);
    await _pollChat(emitListOnNew: false);
  }

  @override
  void dispose() {
    if (_disposed) return;
    _disposed = true;
    stop();
    _messageController.close();
    _conversationController.close();
    _statusController.close();
    _listController.close();
    _reconnectController.close();
    _typingController.close();
    _presenceController.close();
  }
}

class NoopRealtimeService implements RealtimeService {
  final _messageController = StreamController<MessageModel>.broadcast();
  final _conversationController =
      StreamController<ConversationModel>.broadcast();
  final _statusController = StreamController<MessageStatusUpdate>.broadcast();
  final _listController = StreamController<void>.broadcast();
  final _reconnectController = StreamController<void>.broadcast();
  final _typingController = StreamController<Map<String, dynamic>>.broadcast();
  final _presenceController = StreamController<Map<String, dynamic>>.broadcast();

  @override
  Stream<MessageModel> get messageCreated => _messageController.stream;
  @override
  Stream<ConversationModel> get conversationUpdated =>
      _conversationController.stream;
  @override
  Stream<MessageStatusUpdate> get messageStatusUpdated =>
      _statusController.stream;
  @override
  Stream<void> get conversationsChanged => _listController.stream;
  @override
  Stream<void> get reconnectSyncRequested => _reconnectController.stream;

  @override
  Stream<Map<String, dynamic>> get typingActivity => _typingController.stream;
  @override
  Stream<Map<String, dynamic>> get presenceActivity => _presenceController.stream;
  @override
  bool get isWebSocketConnected => false;

  @override
  Stream<RealtimeConnectionState> get connectionStateStream =>
      const Stream.empty();

  @override
  RealtimeConnectionState get currentConnectionState =>
      RealtimeConnectionState.disconnected;

  @override
  int? get activeConversationId => null;
  @override
  Future<void> start() async {}
  @override
  Future<void> stop() async {}
  @override
  void updateWorkspace(int? workspaceId) {}
  @override
  Future<void> catchUpConversation(int conversationId, {int afterId = 0}) async {}

  @override
  void watchConversation(int? conversationId) {}
  @override
  void unwatchConversation(int conversationId) {}
  @override
  void setSeedMaxMessageId(int conversationId, int maxServerId) {}
  @override
  void triggerPoll() {}
  @override
  void dispose() {
    _messageController.close();
    _conversationController.close();
    _statusController.close();
    _listController.close();
    _reconnectController.close();
    _typingController.close();
    _presenceController.close();
  }
}
