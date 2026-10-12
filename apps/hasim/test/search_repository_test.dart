import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/search/data/search_repository.dart';
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

  Future<({SearchRepository repo, List<RequestOptions> calls})> repository({
    Map<String, dynamic>? data,
    Map<String, dynamic>? meta,
    int status = 200,
    int? workspaceId = 4,
  }) async {
    SharedPreferences.setMockInitialValues({
      'workspace_id': ?workspaceId,
    });
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    final calls = <RequestOptions>[];
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1/api/mobile/v1/'));
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          calls.add(options);
          handler.resolve(
            Response(
              requestOptions: options,
              statusCode: status,
              data: {
                'success': true,
                'data': data ??
                    {
                      'conversations': const [],
                      'customers': const [],
                      'emails': const [],
                    },
                'meta': ?meta,
              },
            ),
          );
        },
      ),
    );
    return (
      repo: SearchRepository(
        ApiClient(
          secureStore: _MemorySecureStore(),
          prefsStore: prefs,
          dio: dio,
        ),
      ),
      calls: calls,
    );
  }

  test('search uses GET /search with q and limit only', () async {
    final setup = await repository(
      data: {
        'conversations': [
          {
            'id': 7,
            'channel': 'whatsapp',
            'status': 'open',
            'customer': {'id': 2, 'name': 'أحمد'},
            'last_message': {'id': 1, 'content': 'مرحبا', 'direction': 'inbound'},
          },
        ],
        'customers': [
          {
            'id': 2,
            'name': 'أحمد',
            'phone': '+966500000000',
            'email': 'a@example.com',
          },
        ],
        'emails': [
          {
            'id': 9,
            'sender': 'from@example.com',
            'recipient': 'to@example.com',
            'subject': 'فاتورة',
            'preview': 'مقطف ظاهر',
            'body': 'نص داخلي غير مخصص للموبايل',
          },
        ],
      },
      meta: {'query': 'أحمد', 'workspace_id': 4},
    );

    final payload = await setup.repo.search('أحمد', limit: 10);

    expect(setup.calls, hasLength(1));
    expect(setup.calls[0].method, 'GET');
    expect(setup.calls[0].path, 'search');
    expect(setup.calls[0].queryParameters, {'q': 'أحمد', 'limit': 10});
    expect(setup.calls[0].queryParameters.containsKey('workspace_id'), isFalse);
    expect(payload.conversations, hasLength(1));
    expect(payload.conversations.single.id, 7);
    expect(payload.customers.single.name, 'أحمد');
    expect(payload.emails.single.subject, 'فاتورة');
    expect(payload.emails.single.preview, 'مقطف ظاهر');
    expect(payload.query, 'أحمد');
    expect(payload.workspaceId, 4);
  });

  test('search parses resource-collection wrapped lists', () async {
    final setup = await repository(
      data: {
        'conversations': {
          'data': [
            {'id': 3, 'channel': 'web', 'status': 'open'},
          ],
        },
        'customers': {
          'data': [
            {'id': 8, 'name': 'سارة'},
          ],
        },
        'emails': {
          'data': [
            {
              'id': 11,
              'sender': 's@example.com',
              'recipient': 'r@example.com',
              'subject': 'ترحيب',
            },
          ],
        },
      },
    );

    final payload = await setup.repo.search('سار');
    expect(payload.conversations.single.id, 3);
    expect(payload.customers.single.name, 'سارة');
    expect(payload.emails.single.id, 11);
    expect(setup.calls[0].queryParameters['limit'], 10);
  });

  test('search clamps limit to the mobile contract', () async {
    final setup = await repository();
    await setup.repo.search('أ', limit: 99);
    expect(setup.calls[0].queryParameters, {'q': 'أ', 'limit': 30});
  });
}
