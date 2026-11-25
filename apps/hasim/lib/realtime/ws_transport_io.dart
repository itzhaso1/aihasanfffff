import 'dart:async';
import 'dart:io';

import 'ws_transport.dart';

class _IoWsTransport implements WsTransport {
  _IoWsTransport(this._socket);

  final WebSocket _socket;

  @override
  Stream<dynamic> get stream => _socket;

  @override
  Future<void> get done => _socket.done;

  @override
  void sendText(String text) {
    _socket.add(text);
  }

  @override
  Future<void> close() => _socket.close();
}

Future<WsTransport> connectWsTransport(
  Uri uri, {
  Duration timeout = const Duration(seconds: 10),
}) async {
  final socket = await WebSocket.connect(uri.toString()).timeout(timeout);
  return _IoWsTransport(socket);
}

bool isFlutterTestEnv() => Platform.environment.containsKey('FLUTTER_TEST');
