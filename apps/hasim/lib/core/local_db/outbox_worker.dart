import 'dart:async';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/logging/messaging_logger.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';

class OutboxChangeEvent {
  const OutboxChangeEvent({
    required this.workspaceId,
    required this.conversationId,
    required this.clientId,
    this.failed = false,
    this.reconciled = false,
    this.error,
  });

  final int workspaceId;
  final int conversationId;
  final String clientId;
  final bool failed;
  final bool reconciled;
  final String? error;
}

/// In-app durable outbox processor with backoff timers, lifecycle wakeups,
/// coalesced FIFO flushing, and uncertain-timeout server reconciliation.
class OutboxWorker with WidgetsBindingObserver {
  OutboxWorker({
    required InboxLocalStore store,
    required ConversationRepository repository,
    required InboxSession session,
    Connectivity? connectivity,
  }) : _store = store,
       _repository = repository,
       _session = session,
       _connectivity = connectivity ?? _defaultConnectivity();

  static Connectivity? _defaultConnectivity() {
    if (!kIsWeb && Platform.environment.containsKey('FLUTTER_TEST')) {
      return null;
    }
    try {
      return Connectivity();
    } catch (_) {
      return null;
    }
  }

  final InboxLocalStore _store;
  final ConversationRepository _repository;
  final InboxSession _session;
  final Connectivity? _connectivity;

  StreamSubscription<List<ConnectivityResult>>? _connectivitySub;
  final _changes = StreamController<OutboxChangeEvent>.broadcast();
  Timer? _retryTimer;
  Future<void>? _activeFlush;
  bool _flushAgain = false;
  final Set<int> _inFlight = {};
  bool _lifecycleObserved = false;

  static const _maxAutoAttempts = 12;

  Stream<OutboxChangeEvent> get changes => _changes.stream;

  void start() {
    if (!_lifecycleObserved) {
      try {
        WidgetsBinding.instance.addObserver(this);
        _lifecycleObserved = true;
      } catch (_) {
        // Non-widget test environments may not have a binding initialized.
      }
    }
    final connectivity = _connectivity;
    if (connectivity != null) {
      try {
        _connectivitySub ??= connectivity.onConnectivityChanged.listen(
          (results) {
            final online = results.any((r) => r != ConnectivityResult.none);
            if (online) {
              unawaited(flush(workspaceId: _session.workspaceId));
            }
          },
          onError: (_) {},
          cancelOnError: true,
        );
      } catch (_) {
        // Tests / missing plugin: connectivity is only a hint.
      }
    }
    unawaited(_startFlush());
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(flush(workspaceId: _session.workspaceId));
    }
  }

  Future<void> _startFlush() async {
    await _store.reclaimSendingOutbox();
    await flush(workspaceId: _session.workspaceId);
  }

  Future<void> stop() async {
    _retryTimer?.cancel();
    _retryTimer = null;
    if (_lifecycleObserved) {
      try {
        WidgetsBinding.instance.removeObserver(this);
      } catch (_) {}
      _lifecycleObserved = false;
    }
    await _connectivitySub?.cancel();
    _connectivitySub = null;
  }

  void dispose() {
    unawaited(stop());
    unawaited(_changes.close());
  }

  /// Flushes due outbox items sequentially in FIFO order.
  /// Callers invoking `flush()` while a pass is already active wait until
  /// both the active pass and their newly requested pass finish.
  Future<void> flush({int? workspaceId}) async {
    final scoped = workspaceId ?? _session.workspaceId;
    if (scoped == null) return;
    if (_activeFlush != null) {
      _flushAgain = true;
      await _activeFlush;
      if (!_flushAgain) return;
      return flush(workspaceId: scoped);
    }

    final completer = Completer<void>();
    _activeFlush = completer.future;
    try {
      await _store.reclaimSendingOutbox(
        staleAfter: const Duration(seconds: 45),
      );
      do {
        _flushAgain = false;
        final due = await _store.dueOutbox(workspaceId: scoped);
        for (final op in due) {
          if (_session.workspaceId != null &&
              _session.workspaceId != op.workspaceId) {
            continue;
          }
          await _process(op);
        }
      } while (_flushAgain);
      await _scheduleNextRetry(scoped);
    } finally {
      _activeFlush = null;
      if (!completer.isCompleted) {
        completer.complete();
      }
    }
  }

  Future<void> _scheduleNextRetry(int workspaceId) async {
    _retryTimer?.cancel();
    _retryTimer = null;
    final nextAt = await _store.nextPendingOutboxAt(workspaceId: workspaceId);
    if (nextAt == null || _changes.isClosed) return;
    var delay = nextAt.difference(DateTime.now());
    if (delay.isNegative || delay < const Duration(milliseconds: 200)) {
      delay = const Duration(milliseconds: 200);
    } else if (delay > const Duration(seconds: 300)) {
      delay = const Duration(seconds: 300);
    }
    _retryTimer = Timer(delay, () {
      _retryTimer = null;
      unawaited(flush(workspaceId: _session.workspaceId ?? workspaceId));
    });
  }

  Future<void> _process(OutboxRecord op) async {
    if (_inFlight.contains(op.id)) return;
    final claimed = await _store.claimOutbox(
      op.id,
      workspaceId: op.workspaceId,
      clientId: op.clientId,
    );
    if (!claimed) return;
    _inFlight.add(op.id);
    final corrId = op.correlationId ?? op.clientId;
    final sw = Stopwatch()..start();
    try {
      if (op.operationType != OutboxOpType.sendText) {
        await _store.failOutbox(
          id: op.id,
          workspaceId: op.workspaceId,
          clientId: op.clientId,
          error: 'Unsupported outbox op: ${op.operationType}',
          permanent: true,
          nextAttempt: DateTime.now(),
          attempts: op.attempts + 1,
        );
        return;
      }

      MessagingLogger.log(
        'HTTP_REQUEST_STARTED',
        correlationId: corrId,
        workspaceId: op.workspaceId,
        conversationId: op.conversationServerId,
        clientId: op.clientId,
      );

      try {
        final saved = await _repository.sendMessageWithKey(
          op.conversationServerId,
          op.content,
          op.clientId,
          correlationId: corrId,
        );
        MessagingLogger.log(
          'HTTP_RESPONSE_RECEIVED',
          correlationId: saved.correlationId ?? corrId,
          workspaceId: op.workspaceId,
          conversationId: op.conversationServerId,
          clientId: op.clientId,
          messageId: saved.id,
          httpStatus: 201,
          deliveryStatus: saved.deliveryStatus,
          durationMs: sw.elapsedMilliseconds,
        );

        await _completeWithServerMessage(op, saved, corrId);
      } on ApiException catch (e) {
        MessagingLogger.log(
          'HTTP_RESPONSE_RECEIVED',
          correlationId: corrId,
          workspaceId: op.workspaceId,
          conversationId: op.conversationServerId,
          clientId: op.clientId,
          httpStatus: e.statusCode,
          errorCode: e.errorCode ?? 'api_exception',
          durationMs: sw.elapsedMilliseconds,
        );
        if (_shouldReconcileServer(e)) {
          final existing = await _tryReconcileFromServer(op, corrId);
          if (existing != null) {
            await _completeWithServerMessage(op, existing, corrId);
            return;
          }
        }
        await _handleFailure(
          op,
          e.userMessage,
          e.statusCode,
          errorCode: e.errorCode,
          retryAfterSeconds: e.retryAfterSeconds,
          corrId: corrId,
        );
      } catch (e) {
        final existing = await _tryReconcileFromServer(op, corrId);
        if (existing != null) {
          await _completeWithServerMessage(op, existing, corrId);
          return;
        }
        await _handleFailure(op, 'تعذر إرسال الرسالة.', null, corrId: corrId);
      }
    } finally {
      _inFlight.remove(op.id);
    }
  }

  Future<void> _completeWithServerMessage(
    OutboxRecord op,
    MessageModel saved,
    String corrId,
  ) async {
    final normalized = saved.copyWith(
      clientId: op.clientId,
      correlationId: saved.correlationId ?? corrId,
      localPending: false,
      localSending: false,
      localFailed: false,
    );
    await _store.completeOutbox(
      workspaceId: op.workspaceId,
      clientId: op.clientId,
      server: normalized,
    );
    final providerFailed = normalized.deliveryStatus == 'failed';
    MessagingLogger.log(
      providerFailed ? 'MESSAGE_FAILED' : 'MESSAGE_RECONCILED',
      correlationId: corrId,
      workspaceId: op.workspaceId,
      conversationId: op.conversationServerId,
      clientId: op.clientId,
      messageId: normalized.id,
      deliveryStatus: normalized.deliveryStatus,
      errorCode: providerFailed ? normalized.localError : null,
    );
    if (!_changes.isClosed) {
      _changes.add(
        OutboxChangeEvent(
          workspaceId: op.workspaceId,
          conversationId: op.conversationServerId,
          clientId: op.clientId,
          failed: providerFailed,
          reconciled: !providerFailed,
          error: providerFailed ? normalized.localError : null,
        ),
      );
    }
  }

  Future<MessageModel?> _tryReconcileFromServer(
    OutboxRecord op,
    String corrId,
  ) async {
    try {
      return await _repository.findMessageByClientIdOnServer(
        op.conversationServerId,
        op.clientId,
      );
    } catch (_) {
      return null;
    }
  }

  Future<void> _handleFailure(
    OutboxRecord op,
    String message,
    int? statusCode, {
    String? errorCode,
    int? retryAfterSeconds,
    required String corrId,
  }) async {
    final nextAttempts = op.attempts + 1;
    final permanent = _isPermanent(statusCode, errorCode: errorCode) ||
        nextAttempts >= _maxAutoAttempts;
    var delaySeconds = backoffSeconds(nextAttempts);
    if (retryAfterSeconds != null && retryAfterSeconds > 0) {
      delaySeconds = delaySeconds < retryAfterSeconds
          ? retryAfterSeconds
          : delaySeconds;
    } else if (statusCode == 409) {
      delaySeconds = 2;
    }
    final nextAttempt = permanent
        ? DateTime.now()
        : DateTime.now().add(Duration(seconds: delaySeconds));

    await _store.failOutbox(
      id: op.id,
      workspaceId: op.workspaceId,
      clientId: op.clientId,
      error: message,
      permanent: permanent,
      nextAttempt: nextAttempt,
      attempts: nextAttempts,
    );
    MessagingLogger.log(
      permanent ? 'MESSAGE_FAILED' : 'MESSAGE_RETRY',
      correlationId: corrId,
      workspaceId: op.workspaceId,
      conversationId: op.conversationServerId,
      clientId: op.clientId,
      httpStatus: statusCode,
      errorCode: errorCode ?? (permanent ? 'permanent_failure' : 'transient_backoff'),
    );
    if (!_changes.isClosed) {
      _changes.add(
        OutboxChangeEvent(
          workspaceId: op.workspaceId,
          conversationId: op.conversationServerId,
          clientId: op.clientId,
          failed: permanent,
          error: permanent ? message : null,
        ),
      );
    }
    if (kDebugMode) {
      debugPrint(
        '[OUTBOX] id=${op.id} permanent=$permanent status=$statusCode '
        'attempts=$nextAttempts',
      );
    }
  }

  @visibleForTesting
  static int backoffSeconds(int attempts) {
    const schedule = [2, 4, 8, 16, 32, 64, 128, 300];
    final idx = (attempts - 1).clamp(0, schedule.length - 1);
    return schedule[idx];
  }

  static bool _shouldReconcileServer(ApiException e) {
    final status = e.statusCode;
    return status == null || status == 409 || status >= 500;
  }

  static bool _isPermanent(int? status, {String? errorCode}) {
    if (status == null) return false;
    if (status == 409) {
      return errorCode == 'idempotency_key_conflict';
    }
    return status == 400 ||
        status == 401 ||
        status == 402 ||
        status == 403 ||
        status == 404 ||
        status == 405 ||
        status == 410 ||
        status == 413 ||
        status == 415 ||
        status == 422 ||
        status == 451;
  }
}
