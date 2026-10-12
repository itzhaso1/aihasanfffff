import 'dart:async';

import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/realtime/realtime_service.dart';

/// WebSocket-first inbox updates with polling fallback (P1).
///
/// When `/realtime/config` reports Reverb/Pusher credentials, this service
/// still keeps polling active until native channel subscription is fully verified
/// in production — avoiding a hard cutover.
class HybridRealtimeService implements RealtimeService {
  HybridRealtimeService(
    this._polling, {
    required ApiClient apiClient,
  }) : _apiClient = apiClient;

  final PollingRealtimeService _polling;
  final ApiClient _apiClient;

  bool _websocketConfigured = false;

  @override
  Stream<MessageModel> get messageCreated => _polling.messageCreated;

  @override
  Stream<void> get conversationsChanged => _polling.conversationsChanged;

  @override
  Future<void> start() async {
    _websocketConfigured = await _loadRemoteConfig();
    await _polling.start();
  }

  @override
  Future<void> stop() async {
    await _polling.stop();
  }

  @override
  void watchConversation(int? conversationId) {
    _polling.watchConversation(conversationId);
  }

  Future<bool> _loadRemoteConfig() async {
    try {
      final response = await _apiClient.get<Map<String, dynamic>>(
        'realtime/config',
        mapData: (raw) =>
            raw is Map ? Map<String, dynamic>.from(raw) : <String, dynamic>{},
      );
      final data = response.data;
      if (data == null || data.isEmpty) {
        return false;
      }
      return data['enabled'] == true && (data['key'] as String?)?.isNotEmpty == true;
    } on ApiException {
      return false;
    }
  }

  bool get websocketConfigured => _websocketConfigured;
}
