import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:hasim/realtime/reverb_websocket_client.dart';

/// WebSocket-first inbox updates with seamless Polling fallback.
///
/// Architecture:
/// - When Laravel Reverb / Pusher is configured (`/realtime/config` enabled=true),
///   the client establishes a persistent WebSocket connection.
/// - When WebSocket is active, polling is automatically paused to eliminate
///   unnecessary network requests, save battery, and prevent SQLite write locks.
/// - If WebSocket disconnects or drops, polling resumes immediately to guarantee
///   zero missed messages.
/// - Upon reconnection, missed messages are fetched and real-time streaming resumes.
class HybridRealtimeService implements RealtimeService {
  HybridRealtimeService(
    this._polling, {
    required ApiClient apiClient,
    int? Function()? workspaceIdOf,
  })  : _apiClient = apiClient,
        _workspaceIdOf = workspaceIdOf {
    _listenToPolling();
  }

  void _listenToPolling() {
    _pollingMessageSub ??= _polling.messageCreated.listen(_onIncomingMessage);
    _pollingListSub ??= _polling.conversationsChanged.listen((_) {
      _listController.add(null);
    });
  }

  final PollingRealtimeService _polling;
  final ApiClient _apiClient;
  final int? Function()? _workspaceIdOf;

  ReverbWebSocketClient? _wsClient;
  StreamSubscription<MessageModel>? _pollingMessageSub;
  StreamSubscription<void>? _pollingListSub;
  StreamSubscription<MessageModel>? _wsMessageSub;
  StreamSubscription<void>? _wsListSub;
  StreamSubscription<RealtimeConnectionState>? _wsStateSub;

  final _messageController = StreamController<MessageModel>.broadcast();
  final _listController = StreamController<void>.broadcast();
  final Set<int> _seenMessageIds = <int>{};
  final Set<String> _seenClientIds = <String>{};

  bool _websocketConfigured = false;

  @override
  Stream<MessageModel> get messageCreated => _messageController.stream;

  @override
  Stream<void> get conversationsChanged => _listController.stream;

  @override
  Stream<Map<String, dynamic>> get typingActivity =>
      _wsClient?.typingActivity ?? _polling.typingActivity;

  @override
  Stream<Map<String, dynamic>> get presenceActivity =>
      _wsClient?.presenceActivity ?? _polling.presenceActivity;

  @override
  Future<void> start() async {
    _listenToPolling();
    final config = await _loadRemoteConfig();
    if (config != null && config['enabled'] == true) {
      _websocketConfigured = true;
      _initWebSocket(config);
    } else {
      _websocketConfigured = false;
      _polling.paused = false;
    }
    await _polling.start();
  }

  void _initWebSocket(Map<String, dynamic> config) {
    _wsMessageSub?.cancel();
    _wsListSub?.cancel();
    _wsStateSub?.cancel();
    _wsClient?.dispose();

    final host = config['host']?.toString() ?? 'localhost';
    final port = (config['port'] as num?)?.toInt() ?? 443;
    final key = config['key']?.toString() ?? '';
    final scheme = config['scheme']?.toString() ?? 'https';
    final authEndpoint =
        config['auth_endpoint']?.toString() ?? '/api/broadcasting/auth';
    final channelPrefix =
        config['channel_prefix']?.toString() ?? 'private-workspace.';

    final client = ReverbWebSocketClient(
      apiClient: _apiClient,
      host: host,
      port: port,
      key: key,
      scheme: scheme,
      authEndpoint: authEndpoint,
      channelPrefix: channelPrefix,
    );

    _wsClient = client;

    _wsMessageSub = client.messageCreated.listen(_onIncomingMessage);
    _wsListSub = client.conversationsChanged.listen((_) {
      _listController.add(null);
    });

    _wsStateSub = client.connectionState.listen((state) {
      if (state == RealtimeConnectionState.connected) {
        if (kDebugMode) {
          debugPrint('[HYBRID_REALTIME] WebSocket connected. Pausing polling.');
        }
        _polling.paused = true;
        _listController.add(null);
        _polling.triggerPoll();
      } else {
        if (kDebugMode) {
          debugPrint(
            '[HYBRID_REALTIME] WebSocket disconnected ($state). Resuming polling fallback.',
          );
        }
        _polling.paused = false;
        _polling.triggerPoll();
      }
    });

    client.updateWorkspace(_workspaceIdOf?.call());
    unawaited(client.connect());
  }

  void _onIncomingMessage(MessageModel message) {
    if (message.id > 0 && !_seenMessageIds.add(message.id)) {
      return;
    }
    final cid = message.clientId;
    if (cid != null && cid.isNotEmpty && !_seenClientIds.add(cid)) {
      return;
    }
    _messageController.add(message);
    _listController.add(null);
  }

  @override
  Future<void> stop() async {
    await _polling.stop();
    await _pollingMessageSub?.cancel();
    await _pollingListSub?.cancel();
    _pollingMessageSub = null;
    _pollingListSub = null;
    await _wsMessageSub?.cancel();
    await _wsListSub?.cancel();
    await _wsStateSub?.cancel();
    _wsMessageSub = null;
    _wsListSub = null;
    _wsStateSub = null;
    await _wsClient?.disconnect();
  }

  @override
  void watchConversation(int? conversationId) {
    _wsClient?.updateWorkspace(_workspaceIdOf?.call());
    _wsClient?.watchConversation(conversationId);
    _polling.watchConversation(conversationId);
  }

  @override
  void unwatchConversation(int conversationId) {
    _wsClient?.updateWorkspace(_workspaceIdOf?.call());
    _wsClient?.unwatchConversation(conversationId);
    _polling.unwatchConversation(conversationId);
  }

  @override
  void triggerPoll() {
    _wsClient?.updateWorkspace(_workspaceIdOf?.call());
    _polling.triggerPoll();
  }

  Future<Map<String, dynamic>?> _loadRemoteConfig() async {
    try {
      final response = await _apiClient
          .get<Map<String, dynamic>>(
            'realtime/config',
            mapData: (raw) =>
                raw is Map
                    ? Map<String, dynamic>.from(raw)
                    : <String, dynamic>{},
          )
          .timeout(const Duration(seconds: 3));
      final data = response.data;
      if (data == null || data.isEmpty) {
        return null;
      }
      return data;
    } on ApiException {
      return null;
    } catch (_) {
      return null;
    }
  }

  bool get websocketConfigured => _websocketConfigured;
  bool get isWebSocketConnected => _wsClient?.isConnected ?? false;

  @override
  void dispose() {
    unawaited(stop());
    _wsClient?.dispose();
    _polling.dispose();
    _messageController.close();
    _listController.close();
  }
}
