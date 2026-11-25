import 'dart:async';
import 'dart:js_interop';

import 'ws_transport.dart';

@JS('WebSocket')
extension type _BrowserWebSocket._(JSObject _) implements JSObject {
  external factory _BrowserWebSocket(String url);
  external void send(String data);
  external void close([int? code, String? reason]);
  external set onopen(JSFunction? handler);
  external set onmessage(JSFunction? handler);
  external set onerror(JSFunction? handler);
  external set onclose(JSFunction? handler);
}

@JS()
extension type _MessageEvent._(JSObject _) implements JSObject {
  external JSAny? get data;
}

class _WebWsTransport implements WsTransport {
  _WebWsTransport(this._socket) {
    _socket.onmessage = ((_MessageEvent event) {
      if (!_controller.isClosed) {
        final data = event.data;
        _controller.add(data?.dartify());
      }
    }).toJS;
    _socket.onerror = ((JSAny? err) {
      if (!_controller.isClosed) {
        _controller.addError(StateError('Browser WebSocket error'));
      }
      if (!_doneCompleter.isCompleted) {
        _doneCompleter.complete();
      }
    }).toJS;
    _socket.onclose = ((JSAny? _) {
      if (!_doneCompleter.isCompleted) {
        _doneCompleter.complete();
      }
      if (!_controller.isClosed) {
        unawaited(_controller.close());
      }
    }).toJS;
  }

  final _BrowserWebSocket _socket;
  final _controller = StreamController<dynamic>.broadcast();
  final _doneCompleter = Completer<void>();

  @override
  Stream<dynamic> get stream => _controller.stream;

  @override
  Future<void> get done => _doneCompleter.future;

  @override
  void sendText(String text) {
    _socket.send(text);
  }

  @override
  Future<void> close() async {
    try {
      _socket.close();
    } catch (_) {}
    if (!_doneCompleter.isCompleted) {
      _doneCompleter.complete();
    }
    if (!_controller.isClosed) {
      await _controller.close();
    }
  }
}

Future<WsTransport> connectWsTransport(
  Uri uri, {
  Duration timeout = const Duration(seconds: 10),
}) {
  final completer = Completer<WsTransport>();
  final socket = _BrowserWebSocket(uri.toString());

  socket.onopen = ((JSAny? _) {
    if (!completer.isCompleted) {
      completer.complete(_WebWsTransport(socket));
    }
  }).toJS;

  socket.onerror = ((JSAny? _) {
    if (!completer.isCompleted) {
      completer.completeError(StateError('Failed to open browser WebSocket'));
    }
  }).toJS;

  socket.onclose = ((JSAny? _) {
    if (!completer.isCompleted) {
      completer.completeError(StateError('Browser WebSocket closed before open'));
    }
  }).toJS;

  return completer.future.timeout(timeout, onTimeout: () {
    try {
      socket.close();
    } catch (_) {}
    throw TimeoutException('WebSocket connection timed out', timeout);
  });
}

bool isFlutterTestEnv() => false;
