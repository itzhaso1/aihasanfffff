import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

/// Client connection state for Reverb / Pusher WebSockets.
enum RealtimeConnectionState {
  disconnected,
  connecting,
  connected,
  reconnecting,
}

/// Pure Dart WebSocket client for Laravel Reverb / Pusher protocol (Pusher v7).
///
/// Features:
/// - Native `dart:io` WebSocket connection (no extra third-party SDK needed).
/// - Automatic Sanctum authentication for private channels via `/api/broadcasting/auth`.
/// - Automatic heartbeat (ping/pong) every 30s.
/// - Automatic reconnection with exponential backoff on drops or network switches.
/// - Dynamic channel subscriptions for active workspace and watched conversation.
/// - Real-time event streams for `message.created` and `conversation.updated`.
class ReverbWebSocketClient {
  ReverbWebSocketClient({
    required ApiClient apiClient,
    required String host,
    required int port,
    required String key,
    required String scheme,
    required String authEndpoint,
    required String channelPrefix,
  })  : _apiClient = apiClient,
        _host = host,
        _port = port,
        _key = key,
        _scheme = scheme,
        _authEndpoint = authEndpoint,
        _channelPrefix = channelPrefix;

  final ApiClient _apiClient;
  final String _host;
  final int _port;
  final String _key;
  final String _scheme;
  final String _authEndpoint;
  final String _channelPrefix;

  WebSocket? _socket;
  RealtimeConnectionState _state = RealtimeConnectionState.disconnected;
  String? _socketId;
  int? _workspaceId;
  int? _watchingConversationId;

  final _messageController = StreamController<MessageModel>.broadcast();
  final _conversationUpdateController = StreamController<void>.broadcast();
  final _typingController = StreamController<Map<String, dynamic>>.broadcast();
  final _connectionStateController =
      StreamController<RealtimeConnectionState>.broadcast();

  Timer? _pingTimer;
  Timer? _reconnectTimer;
  int _reconnectAttempts = 0;
  bool _disposed = false;
  final Set<String> _subscribedChannels = <String>{};

  Stream<MessageModel> get messageCreated => _messageController.stream;
  Stream<void> get conversationsChanged => _conversationUpdateController.stream;
  Stream<Map<String, dynamic>> get typingActivity => _typingController.stream;
  Stream<RealtimeConnectionState> get connectionState =>
      _connectionStateController.stream;

  RealtimeConnectionState get state => _state;
  bool get isConnected => _state == RealtimeConnectionState.connected;

  void updateWorkspace(int? workspaceId) {
    if (_workspaceId == workspaceId) return;
    _workspaceId = workspaceId;
    if (isConnected) {
      _resubscribeAll();
    }
  }

  void watchConversation(int? conversationId) {
    final prev = _watchingConversationId;
    _watchingConversationId = conversationId;
    if (!isConnected || _workspaceId == null) return;

    if (prev != null && prev != conversationId) {
      final oldChannel = _conversationChannelName(_workspaceId!, prev);
      _unsubscribe(oldChannel);
    }
    if (conversationId != null && conversationId > 0) {
      final newChannel = _conversationChannelName(
        _workspaceId!,
        conversationId,
      );
      _subscribePrivate(newChannel);
    }
  }

  Future<void> connect() async {
    if (_disposed) return;
    if (_state == RealtimeConnectionState.connecting ||
        _state == RealtimeConnectionState.connected) {
      return;
    }

    _setState(
      _reconnectAttempts > 0
          ? RealtimeConnectionState.reconnecting
          : RealtimeConnectionState.connecting,
    );

    try {
      final wsScheme = _scheme.toLowerCase() == 'http' ? 'ws' : 'wss';
      final uri = Uri(
        scheme: wsScheme,
        host: _host,
        port: _port,
        path: '/app/$_key',
        queryParameters: {
          'protocol': '7',
          'client': 'flutter',
          'version': '1.0.0',
        },
      );

      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Connecting to $uri');
      }

      final socket = await WebSocket.connect(uri.toString()).timeout(
        const Duration(seconds: 10),
      );

      _socket = socket;
      _reconnectAttempts = 0;

      socket.listen(
        _onMessage,
        onError: _onError,
        onDone: _onDone,
        cancelOnError: true,
      );

      _startPingTimer();
    } catch (e) {
      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Connect failed: $e');
      }
      _scheduleReconnect();
    }
  }

  Future<void> disconnect() async {
    _reconnectTimer?.cancel();
    _reconnectTimer = null;
    _pingTimer?.cancel();
    _pingTimer = null;
    _subscribedChannels.clear();
    _socketId = null;

    final s = _socket;
    _socket = null;
    if (s != null) {
      try {
        await s.close();
      } catch (_) {}
    }
    _setState(RealtimeConnectionState.disconnected);
  }

  void dispose() {
    _disposed = true;
    disconnect();
    _messageController.close();
    _conversationUpdateController.close();
    _typingController.close();
    _connectionStateController.close();
  }

  void _onMessage(dynamic raw) {
    if (raw == null) return;
    try {
      final str = raw is String ? raw : utf8.decode(raw as List<int>);
      final map = jsonDecode(str);
      if (map is! Map) return;

      final event = map['event']?.toString() ?? '';
      final channel = map['channel']?.toString() ?? '';
      final rawData = map['data'];

      if (kDebugMode && event.isNotEmpty) {
        debugPrint('[REALTIME_WS] Event: $event on channel: $channel');
      }

      switch (event) {
        case 'pusher:connection_established':
          final data = _parseDataMap(rawData);
          _socketId = data['socket_id']?.toString();
          if (kDebugMode) {
            debugPrint('[REALTIME_WS] Connected. socket_id=$_socketId');
          }
          _setState(RealtimeConnectionState.connected);
          _resubscribeAll();

        case 'pusher:ping':
          _send('pusher:pong', {});

        case 'pusher:error':
          if (kDebugMode) {
            debugPrint('[REALTIME_WS] Protocol error: $rawData');
          }

        case 'message.created':
        case 'App\\Events\\Realtime\\MessageCreated':
          final data = _parseDataMap(rawData);
          if (data.isNotEmpty) {
            try {
              final message = MessageModel.fromJson(data);
              _messageController.add(message);
              _conversationUpdateController.add(null);
            } catch (e) {
              if (kDebugMode) {
                debugPrint('[REALTIME_WS] Message parse error: $e');
              }
            }
          }

        case 'conversation.updated':
        case 'App\\Events\\Realtime\\ConversationUpdated':
          _conversationUpdateController.add(null);

        case 'typing.activity':
        case 'App\\Events\\Realtime\\TypingActivity':
          final data = _parseDataMap(rawData);
          if (data.isNotEmpty) {
            _typingController.add(data);
          }

        default:
          if (event.startsWith('pusher_internal:')) {
            // Subscription confirmations
          }
      }
    } catch (e) {
      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Failed to handle incoming message: $e');
      }
    }
  }

  void _onError(Object error) {
    if (kDebugMode) {
      debugPrint('[REALTIME_WS] Socket error: $error');
    }
    _scheduleReconnect();
  }

  void _onDone() {
    if (kDebugMode) {
      debugPrint('[REALTIME_WS] Socket closed');
    }
    _scheduleReconnect();
  }

  void _scheduleReconnect() {
    _socket = null;
    _socketId = null;
    _pingTimer?.cancel();
    _pingTimer = null;
    _subscribedChannels.clear();
    _setState(RealtimeConnectionState.reconnecting);

    if (_disposed) return;

    _reconnectTimer?.cancel();
    // Exponential backoff: 1s, 2s, 4s, 8s, max 10s
    final delaySeconds = (1 << _reconnectAttempts).clamp(1, 10);
    _reconnectAttempts = (_reconnectAttempts + 1).clamp(0, 5);

    if (kDebugMode) {
      debugPrint('[REALTIME_WS] Reconnecting in ${delaySeconds}s (attempt $_reconnectAttempts)...');
    }

    _reconnectTimer = Timer(Duration(seconds: delaySeconds), () {
      if (!_disposed) {
        connect();
      }
    });
  }

  void _resubscribeAll() {
    _subscribedChannels.clear();
    final wsId = _workspaceId;
    if (wsId == null || wsId <= 0) return;

    // 1. Workspace conversations channel
    final wsChannel = _workspaceChannelName(wsId);
    _subscribePrivate(wsChannel);

    // 2. Currently open conversation channel (if any)
    final convId = _watchingConversationId;
    if (convId != null && convId > 0) {
      final convChannel = _conversationChannelName(wsId, convId);
      _subscribePrivate(convChannel);
    }
  }

  Future<void> _subscribePrivate(String channel) async {
    if (!isConnected || _socketId == null || _subscribedChannels.contains(channel)) {
      return;
    }

    try {
      final authData = await _authorizeChannel(channel);
      if (authData == null || !isConnected) return;

      _send('pusher:subscribe', {
        'channel': channel,
        'auth': authData['auth'],
        if (authData['channel_data'] != null)
          'channel_data': authData['channel_data'],
      });
      _subscribedChannels.add(channel);
      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Subscribed to $channel');
      }
    } catch (e) {
      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Subscribe failed for $channel: $e');
      }
    }
  }

  void _unsubscribe(String channel) {
    if (!_subscribedChannels.remove(channel)) return;
    _send('pusher:unsubscribe', {'channel': channel});
    if (kDebugMode) {
      debugPrint('[REALTIME_WS] Unsubscribed from $channel');
    }
  }

  Future<Map<String, dynamic>?> _authorizeChannel(String channel) async {
    final sId = _socketId;
    if (sId == null) return null;

    try {
      final path = _authEndpoint.contains('broadcasting/auth')
          ? 'broadcasting/auth'
          : (_authEndpoint.startsWith('http')
              ? _authEndpoint
              : _authEndpoint.replaceFirst(RegExp(r'^/?(api/mobile/v1/)?'), ''));

      final res = await _apiClient.post<Map<String, dynamic>>(
        path,
        body: {
          'socket_id': sId,
          'channel_name': channel,
        },
        mapData: (raw) =>
            raw is Map ? Map<String, dynamic>.from(raw) : <String, dynamic>{},
      );
      return res.data;
    } on ApiException catch (e) {
      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Auth channel error: ${e.message}');
      }
      return null;
    } catch (e) {
      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Auth channel unexpected error: $e');
      }
      return null;
    }
  }

  void _send(String event, Map<String, dynamic> data) {
    final s = _socket;
    if (s == null) return;
    try {
      s.add(jsonEncode({'event': event, 'data': data}));
    } catch (e) {
      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Send failed: $e');
      }
    }
  }

  void _startPingTimer() {
    _pingTimer?.cancel();
    _pingTimer = Timer.periodic(const Duration(seconds: 30), (_) {
      if (isConnected) {
        _send('pusher:ping', {});
      }
    });
  }

  void _setState(RealtimeConnectionState next) {
    if (_state == next) return;
    _state = next;
    _connectionStateController.add(next);
  }

  String _workspaceChannelName(int workspaceId) =>
      '$_channelPrefix$workspaceId.conversations';

  String _conversationChannelName(int workspaceId, int conversationId) =>
      '$_channelPrefix$workspaceId.conversation.$conversationId';

  Map<String, dynamic> _parseDataMap(dynamic rawData) {
    if (rawData is Map) {
      return Map<String, dynamic>.from(rawData);
    }
    if (rawData is String) {
      try {
        final decoded = jsonDecode(rawData);
        if (decoded is Map) {
          return Map<String, dynamic>.from(decoded);
        }
      } catch (_) {}
    }
    return const {};
  }
}
