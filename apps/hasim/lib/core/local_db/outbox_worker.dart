import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';

/// In-app durable outbox processor. Connectivity is a hint, not proof of reachability.
class OutboxWorker {
  OutboxWorker({
    required InboxLocalStore store,
    required ConversationRepository repository,
    required InboxSession session,
    Connectivity? connectivity,
  })  : _store = store,
        _repository = repository,
        _session = session,
        _connectivity = connectivity ?? Connectivity();

  final InboxLocalStore _store;
  final ConversationRepository _repository;
  final InboxSession _session;
  final Connectivity _connectivity;

  StreamSubscription<List<ConnectivityResult>>? _connectivitySub;
  bool _flushing = false;
  final Set<int> _inFlight = {};

  static const _maxAutoAttempts = 12;

  bool _flushAgain = false;

  void start() {
    try {
      _connectivitySub ??= _connectivity.onConnectivityChanged.listen((results) {
        final online = results.any((r) => r != ConnectivityResult.none);
        if (online) {
          unawaited(flush(workspaceId: _session.workspaceId));
        }
      });
    } catch (_) {
      // Tests / missing plugin: connectivity is only a hint.
    }
    unawaited(_startFlush());
  }

  Future<void> _startFlush() async {
    await _store.reclaimSendingOutbox();
    await flush(workspaceId: _session.workspaceId);
  }

  Future<void> stop() async {
    await _connectivitySub?.cancel();
    _connectivitySub = null;
  }

  void dispose() {
    unawaited(stop());
  }

  Future<void> flush({int? workspaceId}) async {
    if (_flushing) {
      _flushAgain = true;
      return;
    }
    _flushing = true;
    try {
      do {
        _flushAgain = false;
        final due = await _store.dueOutbox(workspaceId: workspaceId);
        for (final op in due) {
          if (_session.workspaceId != null && _session.workspaceId != op.workspaceId) {
            continue;
          }
          await _process(op);
        }
      } while (_flushAgain);
    } finally {
      _flushing = false;
    }
  }

  Future<void> _process(OutboxRecord op) async {
    if (!_inFlight.add(op.id)) return;
    try {
      final claimed = await _store.claimOutbox(op.id);
      if (!claimed) return;
      if (_session.workspaceId != null && _session.workspaceId != op.workspaceId) {
        await _store.failOutbox(
          id: op.id,
          workspaceId: op.workspaceId,
          clientId: op.clientId,
          error: 'workspace changed',
          permanent: false,
          nextAttempt: DateTime.now().add(const Duration(seconds: 2)),
          attempts: op.attempts,
        );
        return;
      }

      await _store.markMessageSending(op.workspaceId, op.clientId);
      try {
        final saved = await _repository.sendMessageWithKey(
          op.conversationServerId,
          op.content,
          op.clientId,
        );
        await _store.completeOutbox(
          workspaceId: op.workspaceId,
          clientId: op.clientId,
          server: saved.copyWith(clientId: op.clientId, localPending: false, localFailed: false),
        );
      } on ApiException catch (e) {
        await _handleFailure(op, e.message, e.statusCode);
      } catch (_) {
        await _handleFailure(op, 'تعذر إرسال الرسالة.', null);
      }
    } finally {
      _inFlight.remove(op.id);
    }
  }

  Future<void> _handleFailure(OutboxRecord op, String error, int? statusCode) async {
    final attempts = op.attempts + 1;
    final permanent = _isPermanent(statusCode) || attempts >= _maxAutoAttempts;
    final next = permanent
        ? DateTime.now().add(const Duration(days: 365))
        : DateTime.now().add(_backoff(attempts));
    await _store.failOutbox(
      id: op.id,
      workspaceId: op.workspaceId,
      clientId: op.clientId,
      error: error,
      permanent: permanent,
      nextAttempt: next,
      attempts: attempts,
    );
  }

  static bool _isPermanent(int? status) {
    if (status == null) return false;
    return status == 400 ||
        status == 401 ||
        status == 403 ||
        status == 404 ||
        status == 409 ||
        status == 422;
  }

  static Duration _backoff(int attempts) {
    final seconds = 2 * (1 << (attempts - 1).clamp(0, 7));
    return Duration(seconds: seconds.clamp(2, 300));
  }
}
