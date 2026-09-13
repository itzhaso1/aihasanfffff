
import 'package:hasim/features/settings/data/session_repository.dart';

abstract class PushService {
  Future<void> initializeAndRegister();
  String get statusLabel;
}

/// Architecture placeholder until FCM/APNs credentials exist.
class NoopPushService implements PushService {
  NoopPushService(this._devices);
  final DeviceRepository _devices;

  @override
  String get statusLabel => 'غير مفعّل — يحتاج إعداد FCM/APNs';

  @override
  Future<void> initializeAndRegister() async {
    // Intentionally no-op until FCM/APNs credentials exist.
    // When configured: obtain token then call `_devices.register(...)`.
    // Keep DeviceRepository injected so the wiring stays ready.
    // ignore: unused_local_variable
    final ready = _devices;
    assert(ready == _devices);
  }
}
