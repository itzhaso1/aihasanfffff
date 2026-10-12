import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/settings/data/session_repository.dart';
import 'package:hasim/push/push_service.dart';
import 'package:hasim/push/push_token_source.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _Devices extends DeviceRepository {
  _Devices(super.api);

  int registers = 0;
  int revokes = 0;
  String? lastToken;
  String? lastProvider;
  String? lastPlatform;
  Object? error;
  Completer<void>? gate;

  @override
  Future<int?> register({
    required String token,
    required String provider,
    required String platform,
    String? deviceName,
  }) async {
    registers++;
    lastToken = token;
    lastProvider = provider;
    lastPlatform = platform;
    final pending = gate;
    if (pending != null) await pending.future;
    if (error != null) throw error!;
    return 15;
  }

  @override
  Future<void> revoke(int id) async {
    revokes++;
    expect(id, 15);
  }
}

class _Source implements PushTokenSource {
  _Source(this.token);

  String? token;
  bool allow = true;
  final refreshesController = StreamController<String>.broadcast();

  @override
  bool get supported => allow;

  @override
  Future<String?> currentToken() async => token;

  @override
  Stream<String> get refreshes => refreshesController.stream;

  @override
  Stream<Map<String, dynamic>> get openedNotifications =>
      const Stream<Map<String, dynamic>>.empty();

  @override
  Future<Map<String, dynamic>?> takeLaunchNotification() async => null;
}

class _MemorySecure extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}
  @override
  Future<String?> readToken() async => 'token';
  @override
  Future<void> clearToken() async {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  Future<({CompanyDevicePushService service, _Devices devices, PrefsStore prefs, _Source source})>
  build({String? token, int? workspaceId, bool company = true}) async {
    SharedPreferences.setMockInitialValues({'workspace_id': ?workspaceId});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    final devices = _Devices(
      isolatedTestApiClient(secureStore: _MemorySecure(), prefsStore: prefs),
    );
    final source = _Source(token);
    final service = CompanyDevicePushService(
      devices: devices,
      tokens: source,
      prefs: prefs,
      companyWorkspaceId: () => prefs.workspaceId,
      isCompanyAccount: () => company,
      provider: () => 'fcm',
      platformName: () => 'android',
    );
    return (service: service, devices: devices, prefs: prefs, source: source);
  }

  test('register sends a real token for the company workspace', () async {
    final setup = await build(token: 'device-token', workspaceId: 4);
    await setup.service.initializeAndRegister();
    expect(setup.devices.registers, 1);
    expect(setup.devices.lastToken, 'device-token');
    expect(setup.devices.lastProvider, 'fcm');
    expect(setup.devices.lastPlatform, 'android');
    expect(setup.prefs.pushDeviceId, 15);
    expect(setup.prefs.pushDeviceWorkspaceId, 4);
  });

  test('empty token is not posted', () async {
    final setup = await build(token: '   ', workspaceId: 4);
    await setup.service.initializeAndRegister();
    expect(setup.devices.registers, 0);
    expect(setup.service.statusLabel, contains('لا يوجد رمز'));
  });

  test('missing workspace does not register', () async {
    final setup = await build(token: 'device-token');
    await setup.service.initializeAndRegister();
    expect(setup.devices.registers, 0);
  });

  test('unsupported platform does not register', () async {
    final setup = await build(token: 'device-token', workspaceId: 4);
    setup.source.allow = false;
    await setup.service.initializeAndRegister();
    expect(setup.devices.registers, 0);
  });

  test('duplicate registration is skipped', () async {
    final setup = await build(token: 'device-token', workspaceId: 4);
    await setup.service.initializeAndRegister();
    await setup.service.initializeAndRegister();
    expect(setup.devices.registers, 1);
  });

  test('token refresh replaces the previous registration', () async {
    final setup = await build(token: 'device-token', workspaceId: 4);
    await setup.service.initializeAndRegister();
    setup.source.token = 'next-token';
    setup.source.refreshesController.add('next-token');
    await Future<void>.delayed(const Duration(milliseconds: 20));
    expect(setup.devices.revokes, 1);
    expect(setup.devices.registers, 2);
    expect(setup.devices.lastToken, 'next-token');
  });

  test('register failure keeps the device unstored', () async {
    final setup = await build(token: 'device-token', workspaceId: 4);
    setup.devices.error = ApiException('مرفوض', statusCode: 403);
    await setup.service.initializeAndRegister();
    expect(setup.prefs.pushDeviceId, isNull);
    expect(setup.service.statusLabel, 'مرفوض');
  });

  test('revoke deletes the stored device', () async {
    final setup = await build(token: 'device-token', workspaceId: 4);
    await setup.service.initializeAndRegister();
    await setup.service.revokeCurrent();
    expect(setup.devices.revokes, 1);
    expect(setup.prefs.pushDeviceId, isNull);
  });

  test('a second register while the first is running is one request', () async {
    final setup = await build(token: 'device-token', workspaceId: 4);
    setup.devices.gate = Completer<void>();
    final first = setup.service.initializeAndRegister();
    final second = setup.service.initializeAndRegister();
    setup.devices.gate!.complete();
    await Future.wait([first, second]);
    expect(setup.devices.registers, 1);
  });

  test('independent worker workspace is not registered', () async {
    final setup = await build(token: 'device-token', workspaceId: 4, company: false);
    await setup.service.initializeAndRegister();
    expect(setup.devices.registers, 0);
  });
}
