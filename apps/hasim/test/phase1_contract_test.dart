import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/settings/data/plan_channel_repository.dart';
import 'package:hasim/features/settings/data/session_repository.dart';
import 'package:hasim/router/notification_deep_link.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemorySecureStore extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}
  @override
  Future<String?> readToken() async => 'token';
  @override
  Future<void> clearToken() async {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  Future<({ApiClient client, List<RequestOptions> calls, void Function(dynamic data) respond})>
  client({dynamic data}) async {
    SharedPreferences.setMockInitialValues({'workspace_id': 4});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    final calls = <RequestOptions>[];
    var payload = data;
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1/api/mobile/v1/'));
    final api = ApiClient(secureStore: _MemorySecureStore(), prefsStore: prefs, dio: dio);
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          calls.add(options);
          handler.resolve(
            Response(
              requestOptions: options,
              data: {'success': true, 'data': payload},
            ),
          );
        },
      ),
    );
    return (
      client: api,
      calls: calls,
      respond: (next) => payload = next,
    );
  }

  test('device register and revoke use the mobile device routes', () async {
    final setup = await client(data: {'id': 9, 'provider': 'fcm', 'platform': 'android'});
    final id = await DeviceRepository(setup.client).register(
      token: 'abc',
      provider: 'fcm',
      platform: 'android',
      deviceName: 'android',
    );
    await DeviceRepository(setup.client).revoke(id!);
    expect(setup.calls[0].method, 'POST');
    expect(setup.calls[0].path, 'devices');
    expect(setup.calls[0].data, {
      'token': 'abc',
      'provider': 'fcm',
      'platform': 'android',
      'device_name': 'android',
    });
    expect(setup.calls[0].headers['X-Workspace-Id'], '4');
    expect(setup.calls[1].method, 'DELETE');
    expect(setup.calls[1].path, 'devices/9');
  });

  test('skills list parses catalog fields and assignment replaces keys', () async {
    final setup = await client(
      data: [
        {
          'key': 'sales',
          'name': 'المبيعات',
          'description': 'بيع',
          'category': 'commerce',
          'fingerprint': 'secret',
          'allowed_tools': ['hidden'],
        },
      ],
    );
    final skills = await AiRepository(setup.client).fetchSkills();
    expect(skills.single.key, 'sales');
    expect(skills.single.name, 'المبيعات');
    setup.respond({
      'id': 2,
      'name': 'موظف',
      'role': 'sales_agent',
      'skills': [
        {'key': 'sales', 'settings': {}},
      ],
    });
    await AiRepository(setup.client).setEmployeeSkills(2, ['sales']);
    expect(setup.calls[1].method, 'PATCH');
    expect(setup.calls[1].path, 'ai/employees/2');
    expect(setup.calls[1].data, {
      'skills': [
        {'key': 'sales', 'settings': <String, dynamic>{}},
      ],
    });
  });

  test('push payload uses only known notification keys', () {
    expect(resolvePushPayloadRoute({'conversation_id': 7}), '/conversations/7');
    expect(resolvePushPayloadRoute({'email_id': 4}), '/email/4');
    expect(resolvePushPayloadRoute({'unrelated': 'value'}), isNull);
    expect(resolvePushPayloadRoute({}), isNull);
  });
}
