import 'dart:collection';

import 'package:flutter/foundation.dart';

class MessagingLogEntry {
  const MessagingLogEntry({
    required this.stage,
    required this.timestamp,
    this.correlationId,
    this.workspaceId,
    this.conversationId,
    this.clientId,
    this.messageId,
    this.httpStatus,
    this.deliveryStatus,
    this.errorCode,
    this.durationMs,
  });

  final String stage;
  final DateTime timestamp;
  final String? correlationId;
  final int? workspaceId;
  final int? conversationId;
  final String? clientId;
  final int? messageId;
  final int? httpStatus;
  final String? deliveryStatus;
  final String? errorCode;
  final int? durationMs;

  @override
  String toString() {
    final parts = <String>[
      '[HasimMsg] $stage',
      if (correlationId != null) 'corr=$correlationId',
      if (workspaceId != null) 'ws=$workspaceId',
      if (conversationId != null) 'conv=$conversationId',
      if (clientId != null) 'client=$clientId',
      if (messageId != null) 'msg=$messageId',
      if (httpStatus != null) 'http=$httpStatus',
      if (deliveryStatus != null) 'delivery=$deliveryStatus',
      if (errorCode != null) 'err=$errorCode',
      if (durationMs != null) 'ms=$durationMs',
    ];
    return parts.join(' ');
  }
}

/// Privacy-safe structured lifecycle logger for messaging stages.
/// Never logs message content, customer PII, phone numbers, or auth tokens.
class MessagingLogger {
  MessagingLogger._();

  static const int _maxEntries = 250;
  static final ListQueue<MessagingLogEntry> _entries = ListQueue<MessagingLogEntry>(
    _maxEntries,
  );
  static final Set<String> _renderedKeys = <String>{};

  static List<MessagingLogEntry> get recentEvents =>
      List<MessagingLogEntry>.unmodifiable(_entries);

  static void clear() {
    _entries.clear();
    _renderedKeys.clear();
  }

  static void log(
    String stage, {
    String? correlationId,
    int? workspaceId,
    int? conversationId,
    String? clientId,
    int? messageId,
    int? httpStatus,
    String? deliveryStatus,
    String? errorCode,
    int? durationMs,
  }) {
    final entry = MessagingLogEntry(
      stage: stage,
      timestamp: DateTime.now(),
      correlationId: correlationId,
      workspaceId: workspaceId,
      conversationId: conversationId,
      clientId: clientId,
      messageId: messageId,
      httpStatus: httpStatus,
      deliveryStatus: deliveryStatus,
      errorCode: errorCode,
      durationMs: durationMs,
    );
    if (_entries.length >= _maxEntries) {
      _entries.removeFirst();
    }
    _entries.addLast(entry);
    if (kDebugMode) {
      debugPrint(entry.toString());
    }
  }

  static void logRenderedOnce({
    required String renderKey,
    String? correlationId,
    int? workspaceId,
    int? conversationId,
    String? clientId,
    int? messageId,
    String? deliveryStatus,
  }) {
    if (!_renderedKeys.add(renderKey)) return;
    if (_renderedKeys.length > 500) {
      _renderedKeys.clear();
      _renderedKeys.add(renderKey);
    }
    log(
      'MESSAGE_RENDERED',
      correlationId: correlationId,
      workspaceId: workspaceId,
      conversationId: conversationId,
      clientId: clientId,
      messageId: messageId,
      deliveryStatus: deliveryStatus,
    );
  }
}
