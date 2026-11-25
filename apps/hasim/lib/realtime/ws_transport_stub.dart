import 'ws_transport.dart';

Future<WsTransport> connectWsTransport(
  Uri uri, {
  Duration timeout = const Duration(seconds: 10),
}) {
  throw UnsupportedError('WebSocket transport is unsupported on this platform.');
}

bool isFlutterTestEnv() => false;
