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
    Duration pingInterval = const Duration(seconds: 25),
    this.onChannelSubscribed,
  })  : _apiClient = apiClient,
        _host = host,
        _port = port,
        _key = key,
        _scheme = scheme,
        _authEndpoint = authEndpoint,
        _channelPrefix = channelPrefix,
        _pingInterval = pingInterval;

  final ApiClient _apiClient;
  final String _host;
  final int _port;
  final String _key;
  final String _scheme;
  final String _authEndpoint;
  final String _channelPrefix;
  final Duration _pingInterval;
  final void Function(String channel)? onChannelSubscribed;

  WebSocket? _socket;
  RealtimeConnectionState _state = RealtimeConnectionState.disconnected;
  String? _socketId;
  int? _workspaceId;
  int? _watchingConversationId;

  final _messageController = StreamController<MessageModel>.broadcast();
  final _conversationController =
      StreamController<ConversationModel>.broadcast();
  final _statusController = StreamController<MessageStatusUpdate>.broadcast();
  final _conversationUpdateController = StreamController<void>.broadcast();
  final _typingController = StreamController<Map<String, dynamic>>.broadcast();
  final _presenceController = StreamController<Map<String, dynamic>>.broadcast();
  final _connectionStateController =
      StreamController<RealtimeConnectionState>.broadcast();

  Timer? _pingTimer;
  Timer? _pingTimeoutTimer;
  Timer? _reconnectTimer;
  final Map<String, Timer> _subscribeRetryTimers = <String, Timer>{};
  final Map<String, int> _subscribeRetryAttempts = <String, int>{};
  int _reconnectAttempts = 0;
  bool _disposed = false;
  bool _manualDisconnect = false;
  final Set<String> _desiredChannels = <String>{};
  final Set<String> _confirmedChannels = <String>{};
  final Set<String> _inFlightAuthChannels = <String>{};

  Stream<MessageModel> get messageCreated => _messageController.stream;
  Stream<ConversationModel> get conversationUpdated =>
      _conversationController.stream;
  Stream<MessageStatusUpdate> get messageStatusUpdated =>
      _statusController.stream;
  Stream<void> get conversationsChanged => _conversationUpdateController.stream;
  Stream<Map<String, dynamic>> get typingActivity => _typingController.stream;
  Stream<Map<String, dynamic>> get presenceActivity => _presenceController.stream;
  Stream<RealtimeConnectionState> get connectionState =>
      _connectionStateController.stream;

  RealtimeConnectionState get state => _state;
  bool get isConnected => _state == RealtimeConnectionState.connected;
  Set<String> get confirmedChannels => Set.unmodifiable(_confirmedChannels);

  void updateWorkspace(int? workspaceId) {
    if (_workspaceId == workspaceId) return;
    _workspaceId = workspaceId;
    _rebuildDesiredChannels();
    if (isConnected) {
      _syncSubscriptions();
    }
  }

  void watchConversation(int? conversationId) {
    final prev = _watchingConversationId;
    if (prev == conversationId) return;
    _watchingConversationId = conversationId;
    _rebuildDesiredChannels();
    if (!isConnected || _workspaceId == null) return;

    if (prev != null && prev > 0) {
      final oldChannel = _conversationChannelName(_workspaceId!, prev);
      _unsubscribe(oldChannel);
    }
    if (conversationId != null && conversationId > 0) {
      final newChannel = _conversationChannelName(
        _workspaceId!,
        conversationId,
      );
      unawaited(_subscribePrivate(newChannel));
    }
  }

  void unwatchConversation(int conversationId) {
    if (_watchingConversationId == conversationId) {
      watchConversation(null);
    }
  }

  Future<void> connect() async {
    if (_disposed) return;
    if (_state == RealtimeConnectionState.connecting ||
        _state == RealtimeConnectionState.connected) {
      return;
    }

    _manualDisconnect = false;
    _reconnectTimer?.cancel();
    _reconnectTimer = null;

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
          'version': '1.1.0',
        },
      );

      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Connecting to $uri');
      }

      final socket = await WebSocket.connect(uri.toString()).timeout(
        const Duration(seconds: 10),
      );

      if (_disposed || _manualDisconnect) {
        try {
          await socket.close();
        } catch (_) {}
        return;
      }

      _socket = socket;
      _reconnectAttempts = 0;

      socket.listen(
        (raw) => _onMessage(socket, raw),
        onError: (Object err) => _onError(socket, err),
        onDone: () => _onDone(socket),
        cancelOnError: true,
      );

      _startPingTimer();
    } catch (e) {
      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Connect failed: $e');
      }
      if (!_manualDisconnect) {
        _scheduleReconnect();
      }
    }
  }

  /// Called on foreground resume or network interface changes to verify liveness fast
  /// or tear down a half-open TCP connection within 2.5s.
  void verifyConnectionOrReconnect({bool forceCycle = false}) {
    if (_disposed) return;
    if (forceCycle && _socket != null) {
      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Cycling socket after network change...');
      }
      _teardownSocketForReconnect(resetAttempts: true);
      return;
    }
    if (!isConnected) {
      if (_state != RealtimeConnectionState.connecting) {
        _reconnectTimer?.cancel();
        _reconnectTimer = null;
        _reconnectAttempts = 0;
        unawaited(connect());
      }
      return;
    }
    _sendHeartbeatPing(timeout: const Duration(milliseconds: 2500));
  }

  Future<void> disconnect() async {
    _manualDisconnect = true;
    _cancelChannelRetries();
    _reconnectTimer?.cancel();
    _reconnectTimer = null;
    _pingTimer?.cancel();
    _pingTimer = null;
    _pingTimeoutTimer?.cancel();
    _pingTimeoutTimer = null;
    _confirmedChannels.clear();
    _inFlightAuthChannels.clear();
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
    unawaited(disconnect());
    _messageController.close();
    _conversationController.close();
    _statusController.close();
    _conversationUpdateController.close();
    _typingController.close();
    _presenceController.close();
    _connectionStateController.close();
  }

  void _onMessage(WebSocket socket, dynamic raw) {
    if (!identical(_socket, socket) || raw == null) return;
    _pingTimeoutTimer?.cancel();
    _pingTimeoutTimer = null;
    try {
      final str = raw is String ? raw : utf8.decode(raw as List<int>);
      final map = jsonDecode(str);
      if (map is! Map) return;

      final event = map['event']?.toString() ?? '';
      final channel = map['channel']?.toString() ?? '';
      final rawData = map['data'];

      switch (event) {
        case 'pusher:connection_established':
          final data = _parseDataMap(rawData);
          _socketId = data['socket_id']?.toString();
          if (kDebugMode) {
            debugPrint(
              '[REALTIME_WS] Connected socket_id=$_socketId at ${DateTime.now().toIso8601String()}',
            );
          }
          _setState(RealtimeConnectionState.connected);
          _rebuildDesiredChannels();
          _syncSubscriptions();

        case 'pusher:ping':
          _send('pusher:pong', {});

        case 'pusher:pong':
          break;

        case 'pusher_internal:subscription_succeeded':
          if (channel.isNotEmpty) {
            _confirmedChannels.add(channel);
            _inFlightAuthChannels.remove(channel);
            _subscribeRetryAttempts.remove(channel);
            _subscribeRetryTimers.remove(channel)?.cancel();
            if (kDebugMode) {
              debugPrint('[REALTIME_WS] Subscription confirmed: $channel');
            }
            onChannelSubscribed?.call(channel);
          }

        case 'pusher:subscription_error':
        case 'pusher:error':
          final data = _parseDataMap(rawData);
          final code = asNullableInt(data['code']);
          if (kDebugMode) {
            debugPrint(
              '[REALTIME_WS] Protocol error channel=$channel code=$code data=$rawData',
            );
          }
          if (channel.isNotEmpty) {
            _confirmedChannels.remove(channel);
            _inFlightAuthChannels.remove(channel);
            _scheduleChannelSubscribeRetry(channel);
          }

        case 'message.created':
        case 'App\\Events\\Realtime\\MessageCreated':
          final data = _parseDataMap(rawData);
          if (data.isNotEmpty) {
            try {
              final message = MessageModel.fromJson(data);
              _logEventLatency(
                event: event,
                conversationId: message.conversationId,
                messageId: message.id,
                data: data,
              );
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
          final data = _parseDataMap(rawData);
          if (data.isNotEmpty) {
            try {
              final conversation = ConversationModel.fromJson(data);
              _logEventLatency(
                event: event,
                conversationId: conversation.id,
                data: data,
              );
              _conversationController.add(conversation);
            } catch (e) {
              if (kDebugMode) {
                debugPrint('[REALTIME_WS] Conversation parse error: $e');
              }
            }
          }
          _conversationUpdateController.add(null);

        case 'message.status_updated':
        case 'App\\Events\\Realtime\\MessageStatusUpdated':
          final data = _parseDataMap(rawData);
          if (data.isNotEmpty) {
            try {
              final update = MessageStatusUpdate.fromJson(data);
              _logEventLatency(
                event: event,
                conversationId: update.conversationId,
                messageId: update.id,
                data: data,
              );
              _statusController.add(update);
            } catch (e) {
              if (kDebugMode) {
                debugPrint('[REALTIME_WS] Status parse error: $e');
              }
            }
          }

        case 'typing.activity':
        case 'App\\Events\\Realtime\\TypingActivity':
          final data = _parseDataMap(rawData);
          if (data.isNotEmpty) {
            _typingController.add(data);
          }

        case 'employee.joined':
        case 'App\\Events\\Realtime\\EmployeeJoinedConversation':
        case 'employee.left':
        case 'App\\Events\\Realtime\\EmployeeLeftConversation':
        case 'employee.presence_updated':
        case 'App\\Events\\Realtime\\EmployeePresenceUpdated':
          final data = _parseDataMap(rawData);
          if (data.isNotEmpty) {
            _presenceController.add(data);
          }

        default:
          break;
      }
    } catch (e) {
      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Failed to handle incoming message: $e');
      }
    }
  }

  void _logEventLatency({
    required String event,
    required int conversationId,
    int? messageId,
    required Map<String, dynamic> data,
  }) {
    if (!kDebugMode) return;
    final broadcastAt = DateTime.tryParse(data['broadcast_at']?.toString() ?? '');
    final latencyMs = broadcastAt == null
        ? null
        : DateTime.now().toUtc().difference(broadcastAt.toUtc()).inMilliseconds;
    final correlationId = data['correlation_id']?.toString();
    debugPrint(
      '[REALTIME_OBS] event=$event conv=$conversationId '
      '${messageId != null ? 'msg=$messageId ' : ''}'
      '${correlationId != null ? 'corr=$correlationId ' : ''}'
      '${latencyMs != null ? 'skewed_wire_ms=$latencyMs' : ''}',
    );
  }

  void _onError(WebSocket socket, Object error) {
    if (!identical(_socket, socket) || _manualDisconnect) return;
    if (kDebugMode) {
      debugPrint('[REALTIME_WS] Socket error: $error');
    }
    _scheduleReconnect();
  }

  void _onDone(WebSocket socket) {
    if (!identical(_socket, socket) || _manualDisconnect) return;
    if (kDebugMode) {
      debugPrint('[REALTIME_WS] Socket closed');
    }
    _scheduleReconnect();
  }

  void _teardownSocketForReconnect({bool resetAttempts = false}) {
    final s = _socket;
    _socket = null;
    if (resetAttempts) {
      _reconnectAttempts = 0;
    }
    try {
      s?.close();
    } catch (_) {}
    _scheduleReconnect();
  }

  void _scheduleReconnect() {
    if (_disposed || _manualDisconnect) return;
    if (_reconnectTimer != null) return;

    _socket = null;
    _socketId = null;
    _pingTimer?.cancel();
    _pingTimer = null;
    _pingTimeoutTimer?.cancel();
    _pingTimeoutTimer = null;
    _cancelChannelRetries();
    _confirmedChannels.clear();
    _inFlightAuthChannels.clear();
    _setState(RealtimeConnectionState.reconnecting);

    // Exponential backoff: 1s, 2s, 4s, 8s, max 12s
    final delaySeconds = (1 << _reconnectAttempts).clamp(1, 12);
    _reconnectAttempts = (_reconnectAttempts + 1).clamp(0, 5);

    if (kDebugMode) {
      debugPrint(
        '[REALTIME_WS] Reconnecting in ${delaySeconds}s (attempt $_reconnectAttempts)...',
      );
    }

    _reconnectTimer = Timer(Duration(seconds: delaySeconds), () {
      _reconnectTimer = null;
      if (!_disposed && !_manualDisconnect) {
        unawaited(connect());
      }
    });
  }

  void _rebuildDesiredChannels() {
    _desiredChannels.clear();
    final wsId = _workspaceId;
    if (wsId == null || wsId <= 0) return;
    _desiredChannels.add(_workspaceChannelName(wsId));
    final convId = _watchingConversationId;
    if (convId != null && convId > 0) {
      _desiredChannels.add(_conversationChannelName(wsId, convId));
    }
  }

  void _syncSubscriptions() {
    final stale = _confirmedChannels
        .where((ch) => !_desiredChannels.contains(ch))
        .toList();
    for (final ch in stale) {
      _unsubscribe(ch);
    }

    for (final ch in _desiredChannels) {
      if (!_confirmedChannels.contains(ch)) {
        unawaited(_subscribePrivate(ch));
      }
    }
  }

  Future<void> _subscribePrivate(String channel) async {
    if (!isConnected ||
        _socketId == null ||
        _confirmedChannels.contains(channel) ||
        _inFlightAuthChannels.contains(channel) ||
        !_desiredChannels.contains(channel)) {
      return;
    }

    _inFlightAuthChannels.add(channel);
    final authSocketId = _socketId;

    try {
      final result = await _authorizeChannel(channel);
      if (_disposed ||
          !isConnected ||
          _socketId != authSocketId ||
          !_desiredChannels.contains(channel)) {
        _inFlightAuthChannels.remove(channel);
        return;
      }

      if (result.permanentFailure) {
        _inFlightAuthChannels.remove(channel);
        return;
      }

      final authData = result.data;
      if (authData == null || authData['auth'] == null) {
        _inFlightAuthChannels.remove(channel);
        _scheduleChannelSubscribeRetry(channel);
        return;
      }

      _send('pusher:subscribe', {
        'channel': channel,
        'auth': authData['auth'],
        if (authData['channel_data'] != null)
          'channel_data': authData['channel_data'],
      });
      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Subscribing to $channel...');
      }
    } catch (e) {
      _inFlightAuthChannels.remove(channel);
      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Subscribe failed for $channel: $e');
      }
      _scheduleChannelSubscribeRetry(channel);
    }
  }

  void _scheduleChannelSubscribeRetry(String channel) {
    if (_disposed ||
        !isConnected ||
        !_desiredChannels.contains(channel) ||
        _confirmedChannels.contains(channel)) {
      return;
    }
    if (_subscribeRetryTimers.containsKey(channel)) return;
    final attempts = (_subscribeRetryAttempts[channel] ?? 0) + 1;
    if (attempts > 5) return;
    _subscribeRetryAttempts[channel] = attempts;
    final delay = Duration(seconds: (1 << (attempts - 1)).clamp(1, 15));
    _subscribeRetryTimers[channel] = Timer(delay, () {
      _subscribeRetryTimers.remove(channel);
      if (!_disposed && isConnected && _desiredChannels.contains(channel)) {
        unawaited(_subscribePrivate(channel));
      }
    });
  }

  void _cancelChannelRetries() {
    for (final timer in _subscribeRetryTimers.values) {
      timer.cancel();
    }
    _subscribeRetryTimers.clear();
    _subscribeRetryAttempts.clear();
  }

  void _unsubscribe(String channel) {
    _subscribeRetryTimers.remove(channel)?.cancel();
    _subscribeRetryAttempts.remove(channel);
    _inFlightAuthChannels.remove(channel);
    final wasConfirmed = _confirmedChannels.remove(channel);
    if (!wasConfirmed && !isConnected) return;
    _send('pusher:unsubscribe', {'channel': channel});
    if (kDebugMode) {
      debugPrint('[REALTIME_WS] Unsubscribed from $channel');
    }
  }

  Future<({Map<String, dynamic>? data, bool permanentFailure})>
  _authorizeChannel(String channel) async {
    final sId = _socketId;
    if (sId == null) return (data: null, permanentFailure: false);

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
      final data = res.data;
      if (data != null) {
        if (data.containsKey('auth')) {
          return (data: data, permanentFailure: false);
        }
        if (data['data'] is Map && (data['data'] as Map).containsKey('auth')) {
          return (
            data: Map<String, dynamic>.from(data['data'] as Map),
            permanentFailure: false,
          );
        }
      }
      return (data: data, permanentFailure: false);
    } on ApiException catch (e) {
      if (kDebugMode) {
        debugPrint(
          '[REALTIME_WS] Auth channel error channel=$channel status=${e.statusCode}: ${e.message}',
        );
      }
      final permanent =
          e.statusCode == 401 || e.statusCode == 403 || e.statusCode == 404;
      return (data: null, permanentFailure: permanent);
    } catch (e) {
      if (kDebugMode) {
        debugPrint('[REALTIME_WS] Auth channel unexpected error: $e');
      }
      return (data: null, permanentFailure: false);
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
    _pingTimer = Timer.periodic(_pingInterval, (_) {
      _sendHeartbeatPing();
    });
  }

  void _sendHeartbeatPing({Duration timeout = const Duration(seconds: 10)}) {
    if (!isConnected) return;
    final activeSocket = _socket;
    if (activeSocket == null) return;
    _send('pusher:ping', {});
    _pingTimeoutTimer?.cancel();
    _pingTimeoutTimer = Timer(timeout, () {
      if (!identical(_socket, activeSocket) || !isConnected || _disposed) {
        return;
      }
      if (kDebugMode) {
        debugPrint(
          '[REALTIME_WS] Heartbeat timeout (${timeout.inMilliseconds}ms). Reconnecting...',
        );
      }
      _teardownSocketForReconnect();
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
