import 'dart:async';
import 'dart:collection';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:hasim/realtime/reverb_websocket_client.dart';

/// WebSocket-first inbox updates with resilient config retry, lifecycle recovery,
/// SQLite incremental persistence, and Polling fallback.
class HybridRealtimeService
    with WidgetsBindingObserver
    implements RealtimeService {
  HybridRealtimeService(
    this._polling, {
    required ApiClient apiClient,
    int? Function()? workspaceIdOf,
    ConversationRepository? repository,
    VoidCallback? onLocalInboxChange,
    Connectivity? connectivity,
  })  : _apiClient = apiClient,
        _workspaceIdOf = workspaceIdOf,
        _repository = repository,
        _onLocalInboxChange = onLocalInboxChange,
        _connectivity = connectivity {
    _listenToPolling();
  }

  void _listenToPolling() {
    _pollingMessageSub ??= _polling.messageCreated.listen(_onIncomingMessage);
    _pollingListSub ??= _polling.conversationsChanged.listen((_) {
      if (!isWebSocketConnected) {
        _listController.add(null);
      }
    });
  }

  final PollingRealtimeService _polling;
  final ApiClient _apiClient;
  final int? Function()? _workspaceIdOf;
  final ConversationRepository? _repository;
  final VoidCallback? _onLocalInboxChange;
  final Connectivity? _connectivity;

  ReverbWebSocketClient? _wsClient;
  StreamSubscription<MessageModel>? _pollingMessageSub;
  StreamSubscription<void>? _pollingListSub;
  StreamSubscription<MessageModel>? _wsMessageSub;
  StreamSubscription<ConversationModel>? _wsConversationSub;
  StreamSubscription<MessageStatusUpdate>? _wsStatusSub;
  StreamSubscription<RealtimeConnectionState>? _wsStateSub;
  StreamSubscription<List<ConnectivityResult>>? _connectivitySub;

  Timer? _configRetryTimer;
  Timer? _catchUpDebounce;
  Future<void>? _configInFlight;
  int _configRetryAttempts = 0;
  bool _started = false;
  bool _disposed = false;
  bool _lifecycleObserved = false;
  bool _wasBackgrounded = false;
  bool _needsCatchUpSync = false;
  bool _explicitlyDisabled = false;
  String? _lastConnectivityKey;
  int? _activeConversationId;
  int? _lastWorkspaceId;
  final Set<int> _fetchingConversationIds = <int>{};

  final _messageController = StreamController<MessageModel>.broadcast();
  final _conversationController =
      StreamController<ConversationModel>.broadcast();
  final _statusController = StreamController<MessageStatusUpdate>.broadcast();
  final _connectionStateController =
      StreamController<RealtimeConnectionState>.broadcast();
  final _listController = StreamController<void>.broadcast();
  final _reconnectController = StreamController<void>.broadcast();
  final LinkedHashMap<String, String> _seenSignatures =
      LinkedHashMap<String, String>();

  bool _websocketConfigured = false;
  static const int _maxSeenEntries = 600;

  @override
  Stream<MessageModel> get messageCreated => _messageController.stream;

  @override
  Stream<ConversationModel> get conversationUpdated =>
      _conversationController.stream;

  @override
  Stream<MessageStatusUpdate> get messageStatusUpdated =>
      _statusController.stream;

  @override
  Stream<RealtimeConnectionState> get connectionStateStream =>
      _connectionStateController.stream;

  @override
  RealtimeConnectionState get currentConnectionState =>
      _wsClient?.state ?? RealtimeConnectionState.disconnected;

  @override
  Stream<void> get conversationsChanged => _listController.stream;

  @override
  Stream<void> get reconnectSyncRequested => _reconnectController.stream;

  @override
  Stream<Map<String, dynamic>> get typingActivity =>
      _wsClient?.typingActivity ?? _polling.typingActivity;

  @override
  Stream<Map<String, dynamic>> get presenceActivity =>
      _wsClient?.presenceActivity ?? _polling.presenceActivity;

  @override
  void setSeedMaxMessageId(int conversationId, int maxServerId) {
    _polling.setSeedMaxMessageId(conversationId, maxServerId);
  }

  @override
  Future<void> start() async {
    if (_disposed) return;
    final alreadyStarted = _started;
    _started = true;
    _explicitlyDisabled = false;
    _lastWorkspaceId = _workspaceIdOf?.call();
    _listenToPolling();
    _attachLifecycleObserver();
    _attachConnectivityObserver();

    if (!alreadyStarted) {
      await _polling.start();
    }
    await _attemptConfigAndConnect();
  }

  @override
  void updateWorkspace(int? workspaceId) {
    if (_disposed) return;
    if (workspaceId != _lastWorkspaceId) {
      _lastWorkspaceId = workspaceId;
      _seenSignatures.clear();
      _polling.updateWorkspace(workspaceId);
      _wsClient?.updateWorkspace(workspaceId);
      if (_started && workspaceId != null && workspaceId > 0) {
        _needsCatchUpSync = true;
        if (isWebSocketConnected) {
          _scheduleCatchUpSync();
        } else if (!_disposed) {
          _reconnectController.add(null);
        }
      }
      return;
    }
    _wsClient?.updateWorkspace(workspaceId);
  }

  @override
  Future<void> catchUpConversation(int conversationId, {int afterId = 0}) async {
    if (!_started || _disposed || conversationId <= 0) return;
    var resolvedAfter = afterId;
    final wsId = _workspaceIdOf?.call();
    if (resolvedAfter <= 0 && wsId != null && _repository != null) {
      try {
        resolvedAfter = await _repository.maxCachedServerMessageId(
          wsId,
          conversationId,
        );
      } catch (_) {}
    }
    await _polling.catchUpConversation(conversationId, afterId: resolvedAfter);
  }

  void _attachLifecycleObserver() {
    if (_lifecycleObserved) return;
    try {
      WidgetsBinding.instance.addObserver(this);
      _lifecycleObserved = true;
    } catch (_) {
      // Headless unit tests without WidgetsBinding.
    }
  }

  void _attachConnectivityObserver() {
    if (_connectivitySub != null) return;
    final conn = _connectivity ?? _safeDefaultConnectivity();
    if (conn == null) return;
    try {
      _connectivitySub = conn.onConnectivityChanged.listen(
        (results) {
          if (!_started || _disposed) return;
          final active = results
              .where((r) => r != ConnectivityResult.none)
              .map((r) => r.name)
              .toList()
            ..sort();
          final nextKey = active.join(',');
          final wasOffline = _lastConnectivityKey == '';
          final interfaceSwitched =
              _lastConnectivityKey != null &&
              _lastConnectivityKey != nextKey &&
              nextKey.isNotEmpty;
          _lastConnectivityKey = nextKey;

          if (nextKey.isEmpty) {
            _polling.paused = false;
            return;
          }

          if (!_websocketConfigured && !_explicitlyDisabled) {
            _configRetryTimer?.cancel();
            _configRetryTimer = null;
            _configRetryAttempts = 0;
            unawaited(_attemptConfigAndConnect());
            return;
          }

          if (_wsClient != null) {
            if (kDebugMode) {
              debugPrint(
                '[HYBRID_REALTIME] Connectivity changed ($nextKey); verifying WebSocket...',
              );
            }
            _needsCatchUpSync = true;
            _wsClient!.verifyConnectionOrReconnect(
              forceCycle: interfaceSwitched && !wasOffline,
            );
          }
        },
        onError: (_) {},
        cancelOnError: true,
      );
    } catch (_) {
      // Tests / missing platform channel.
    }
  }

  Connectivity? _safeDefaultConnectivity() {
    if (!kIsWeb && Platform.environment.containsKey('FLUTTER_TEST')) {
      return null;
    }
    try {
      return Connectivity();
    } catch (_) {
      return null;
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!_started || _disposed) return;
    if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.hidden ||
        state == AppLifecycleState.inactive) {
      _wasBackgrounded = true;
      return;
    }
    if (state == AppLifecycleState.resumed && _wasBackgrounded) {
      _wasBackgrounded = false;
      if (kDebugMode) {
        debugPrint('[HYBRID_REALTIME] App resumed; verifying realtime transport...');
      }
      if (!_websocketConfigured && !_explicitlyDisabled) {
        _configRetryAttempts = 0;
        unawaited(_attemptConfigAndConnect());
      } else if (_wsClient != null) {
        _needsCatchUpSync = true;
        _wsClient!.verifyConnectionOrReconnect();
        if (_wsClient!.isConnected) {
          _scheduleCatchUpSync();
        }
      } else {
        _polling.triggerPoll();
      }
    }
  }

  Future<void> _attemptConfigAndConnect() {
    if (!_started || _disposed || _websocketConfigured) {
      return Future<void>.value();
    }
    final existing = _configInFlight;
    if (existing != null) return existing;

    final future = _performConfigAndConnect().whenComplete(() {
      _configInFlight = null;
    });
    _configInFlight = future;
    return future;
  }

  Future<void> _performConfigAndConnect() async {
    if (!_started || _disposed || _websocketConfigured) return;

    final result = await _loadRemoteConfig();
    if (!_started || _disposed || _websocketConfigured) return;

    final config = result.config;
    if (result.explicitDisabled) {
      _explicitlyDisabled = true;
      _websocketConfigured = false;
      _polling.paused = false;
      return;
    }

    if (config != null && config['enabled'] == true) {
      _configRetryTimer?.cancel();
      _configRetryTimer = null;
      _configRetryAttempts = 0;
      _websocketConfigured = true;
      _polling.paused = true;
      _initWebSocket(config);
      return;
    }

    _websocketConfigured = false;
    _polling.paused = false;
    _scheduleConfigRetry();
  }

  void _scheduleConfigRetry() {
    if (!_started || _disposed || _websocketConfigured || _explicitlyDisabled) {
      return;
    }
    if (_configRetryTimer != null) return;
    _configRetryAttempts = (_configRetryAttempts + 1).clamp(1, 5);
    final delaySec = switch (_configRetryAttempts) {
      1 => 2,
      2 => 5,
      3 => 10,
      4 => 20,
      _ => 30,
    };
    _configRetryTimer = Timer(Duration(seconds: delaySec), () {
      _configRetryTimer = null;
      if (_started && !_disposed && !_websocketConfigured) {
        unawaited(_attemptConfigAndConnect());
      }
    });
  }

  void _initWebSocket(Map<String, dynamic> config) {
    _wsMessageSub?.cancel();
    _wsConversationSub?.cancel();
    _wsStatusSub?.cancel();
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
    final pingSec = (config['ping_interval'] as num?)?.toInt() ?? 25;

    final client = ReverbWebSocketClient(
      apiClient: _apiClient,
      host: host,
      port: port,
      key: key,
      scheme: scheme,
      authEndpoint: authEndpoint,
      channelPrefix: channelPrefix,
      pingInterval: Duration(seconds: pingSec.clamp(15, 60)),
      onChannelSubscribed: (_) {
        if (_needsCatchUpSync) {
          _scheduleCatchUpSync();
        }
      },
    );

    _wsClient = client;

    _wsMessageSub = client.messageCreated.listen(_onIncomingMessage);
    _wsConversationSub = client.conversationUpdated.listen(_onConversationUpdated);
    _wsStatusSub = client.messageStatusUpdated.listen(_onStatusUpdated);

    _wsStateSub = client.connectionState.listen((state) {
      if (!_disposed && !_connectionStateController.isClosed) {
        _connectionStateController.add(state);
      }
      if (state == RealtimeConnectionState.connected) {
        if (kDebugMode) {
          debugPrint('[HYBRID_REALTIME] WebSocket connected. Pausing polling.');
        }
        _polling.paused = true;
        if (_needsCatchUpSync) {
          _scheduleCatchUpSync();
        }
      } else if (state == RealtimeConnectionState.reconnecting ||
          state == RealtimeConnectionState.disconnected) {
        _needsCatchUpSync = true;
        if (kDebugMode) {
          debugPrint(
            '[HYBRID_REALTIME] WebSocket $state. Resuming fallback polling.',
          );
        }
        _polling.paused = false;
      }
    });

    client.updateWorkspace(_workspaceIdOf?.call());
    if (_activeConversationId != null) {
      client.watchConversation(_activeConversationId);
    }
    unawaited(client.connect());
  }

  void _scheduleCatchUpSync() {
    _catchUpDebounce?.cancel();
    _catchUpDebounce = Timer(const Duration(milliseconds: 120), () {
      _catchUpDebounce = null;
      if (!_started || _disposed) return;
      _needsCatchUpSync = false;
      unawaited(_runIncrementalCatchUp());
    });
  }

  Future<void> _runIncrementalCatchUp() async {
    final wsId = _workspaceIdOf?.call();
    final activeId = _activeConversationId;
    if (activeId != null && activeId > 0) {
      var seedMax = 0;
      if (wsId != null && _repository != null) {
        try {
          seedMax = await _repository.maxCachedServerMessageId(wsId, activeId);
        } catch (_) {}
      }
      await _polling.catchUpConversation(activeId, afterId: seedMax);
    }
    if (_disposed) return;
    _reconnectController.add(null);
  }

  void _onIncomingMessage(MessageModel message) {
    if (_disposed) return;
    final sigKey = message.id > 0
        ? 's:${message.id}'
        : 'c:${message.clientId ?? message.id}';
    final sigVal =
        '${message.messageType}:${message.attachments.length}:${message.deliveryStatus ?? ''}:${message.content.hashCode}';
    if (_seenSignatures[sigKey] == sigVal) {
      return;
    }
    _seenSignatures.remove(sigKey);
    _seenSignatures[sigKey] = sigVal;
    while (_seenSignatures.length > _maxSeenEntries) {
      _seenSignatures.remove(_seenSignatures.keys.first);
    }

    _messageController.add(message);
    if (kDebugMode) {
      debugPrint(
        '[REALTIME_OBS] Delivered conv=${message.conversationId} msg=${message.id} '
        'sender=${message.senderUserId ?? 0} client=${message.clientId ?? '-'}',
      );
    }
    unawaited(_persistRealtimeMessage(message));
  }

  Future<void> _persistRealtimeMessage(MessageModel message) async {
    final repo = _repository;
    final wsId = _workspaceIdOf?.call();
    if (repo == null || wsId == null) return;

    try {
      final isOpen =
          _activeConversationId != null &&
          _activeConversationId == message.conversationId;
      final needsConvFetch = await repo.applyRealtimeMessage(
        wsId,
        message,
        isConversationOpen: isOpen,
      );
      if (needsConvFetch &&
          _fetchingConversationIds.add(message.conversationId)) {
        try {
          await repo.getById(message.conversationId, workspaceId: wsId);
        } catch (_) {
          // Will sync on next delta if offline.
        } finally {
          _fetchingConversationIds.remove(message.conversationId);
        }
      }
      _onLocalInboxChange?.call();
    } catch (e) {
      if (kDebugMode) {
        debugPrint('[HYBRID_REALTIME] Local message sync failed: $e');
      }
    }
  }

  void _onConversationUpdated(ConversationModel conversation) {
    if (_disposed) return;
    _conversationController.add(conversation);
    unawaited(_persistRealtimeConversation(conversation));
  }

  Future<void> _persistRealtimeConversation(
    ConversationModel conversation,
  ) async {
    final repo = _repository;
    final wsId = _workspaceIdOf?.call();
    if (repo == null || wsId == null) return;

    try {
      final isOpen =
          _activeConversationId != null &&
          _activeConversationId == conversation.id;
      final needsFetch = await repo.applyRealtimeConversation(
        wsId,
        conversation,
        isConversationOpen: isOpen,
      );
      if (needsFetch && _fetchingConversationIds.add(conversation.id)) {
        try {
          await repo.getById(conversation.id, workspaceId: wsId);
        } catch (_) {
        } finally {
          _fetchingConversationIds.remove(conversation.id);
        }
      }
      _onLocalInboxChange?.call();
    } catch (e) {
      if (kDebugMode) {
        debugPrint('[HYBRID_REALTIME] Local conversation sync failed: $e');
      }
    }
  }

  void _onStatusUpdated(MessageStatusUpdate update) {
    if (_disposed) return;
    _statusController.add(update);
    final repo = _repository;
    final wsId = _workspaceIdOf?.call();
    if (repo == null || wsId == null) return;
    unawaited(repo.applyMessageStatusUpdate(wsId, update));
  }

  @override
  Future<void> stop() async {
    _started = false;
    _configRetryTimer?.cancel();
    _configRetryTimer = null;
    _catchUpDebounce?.cancel();
    _catchUpDebounce = null;
    await _polling.stop();
    await _pollingMessageSub?.cancel();
    await _pollingListSub?.cancel();
    _pollingMessageSub = null;
    _pollingListSub = null;
    await _wsMessageSub?.cancel();
    await _wsConversationSub?.cancel();
    await _wsStatusSub?.cancel();
    await _wsStateSub?.cancel();
    _wsMessageSub = null;
    _wsConversationSub = null;
    _wsStatusSub = null;
    _wsStateSub = null;
    await _connectivitySub?.cancel();
    _connectivitySub = null;
    await _wsClient?.disconnect();
  }

  @override
  void watchConversation(int? conversationId) {
    _activeConversationId = conversationId;
    _wsClient?.updateWorkspace(_workspaceIdOf?.call());
    _wsClient?.watchConversation(conversationId);
    _polling.watchConversation(conversationId);

    final wsId = _workspaceIdOf?.call();
    final repo = _repository;
    if (conversationId != null &&
        conversationId > 0 &&
        wsId != null &&
        repo != null) {
      unawaited(
        repo.maxCachedServerMessageId(wsId, conversationId).then((maxId) {
          if (_activeConversationId == conversationId && maxId > 0) {
            _polling.setSeedMaxMessageId(conversationId, maxId);
          }
        }).catchError((_) {}),
      );
    }
  }

  @override
  void unwatchConversation(int conversationId) {
    if (_activeConversationId == conversationId) {
      _activeConversationId = null;
    }
    _wsClient?.updateWorkspace(_workspaceIdOf?.call());
    _wsClient?.unwatchConversation(conversationId);
    _polling.unwatchConversation(conversationId);
  }

  @override
  void triggerPoll() {
    _wsClient?.updateWorkspace(_workspaceIdOf?.call());
    if (isWebSocketConnected) {
      _scheduleCatchUpSync();
      return;
    }
    _polling.triggerPoll();
  }

  Future<({Map<String, dynamic>? config, bool explicitDisabled})>
  _loadRemoteConfig() async {
    try {
      final response = await _apiClient
          .get<Map<String, dynamic>>(
            'realtime/config',
            mapData: (raw) =>
                raw is Map
                    ? Map<String, dynamic>.from(raw)
                    : <String, dynamic>{},
          )
          .timeout(const Duration(seconds: 4));
      final data = response.data;
      if (data == null || data.isEmpty) {
        return (config: null, explicitDisabled: false);
      }
      if (data['enabled'] == false) {
        return (config: data, explicitDisabled: true);
      }
      return (config: data, explicitDisabled: false);
    } on ApiException {
      return (config: null, explicitDisabled: false);
    } catch (_) {
      return (config: null, explicitDisabled: false);
    }
  }

  bool get websocketConfigured => _websocketConfigured;
  @override
  int? get activeConversationId => _activeConversationId;
  @override
  bool get isWebSocketConnected => _wsClient?.isConnected ?? false;

  @override
  void dispose() {
    if (_disposed) return;
    _disposed = true;
    if (_lifecycleObserved) {
      try {
        WidgetsBinding.instance.removeObserver(this);
      } catch (_) {}
      _lifecycleObserved = false;
    }
    unawaited(stop());
    _wsClient?.dispose();
    _polling.dispose();
    _messageController.close();
    _conversationController.close();
    _statusController.close();
    _connectionStateController.close();
    _listController.close();
    _reconnectController.close();
  }
}
