import 'ws_transport_stub.dart'
    if (dart.library.io) 'ws_transport_io.dart'
    if (dart.library.js_interop) 'ws_transport_web.dart';

abstract class WsTransport {
  Stream<dynamic> get stream;
  Future<void> get done;
  void sendText(String text);
  Future<void> close();

  static Future<WsTransport> connect(
    Uri uri, {
    Duration timeout = const Duration(seconds: 10),
  }) =>
      connectWsTransport(uri, timeout: timeout);

  static bool get isFlutterTestEnvironment => isFlutterTestEnv();
}
