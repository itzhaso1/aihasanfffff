import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/stories/data/story_repository.dart';
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

  Future<({StoryRepository repo, List<RequestOptions> calls})> repository({
    int status = 200,
    dynamic data,
    String? message,
  }) async {
    SharedPreferences.setMockInitialValues({'workspace_id': 4});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    final calls = <RequestOptions>[];
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1/api/mobile/v1/'));
    final client = ApiClient(
      secureStore: _MemorySecureStore(),
      prefsStore: prefs,
      dio: dio,
    );
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          calls.add(options);
          if (status >= 400) {
            handler.reject(
              DioException(
                requestOptions: options,
                type: DioExceptionType.badResponse,
                response: Response(
                  requestOptions: options,
                  statusCode: status,
                  data: {
                    'success': false,
                    'message': message ?? 'error',
                  },
                ),
              ),
            );
            return;
          }
          handler.resolve(
            Response(
              requestOptions: options,
              statusCode: status,
              data: {
                'success': true,
                'data': data,
                'message': ?message,
              },
            ),
          );
        },
      ),
    );
    return (repo: StoryRepository(client), calls: calls);
  }

  test('delete uses DELETE /stories/{id}', () async {
    final setup = await repository(data: null, message: 'تم حذف القصة.');
    await setup.repo.delete(12);
    expect(setup.calls.single.method, 'DELETE');
    expect(setup.calls.single.path, 'stories/12');
    expect(setup.calls.single.headers['X-Workspace-Id'], '4');
  });

  test('viewers uses GET /stories/{id}/viewers and parses mobile fields', () async {
    final setup = await repository(
      data: [
        {
          'user_id': 987654,
          'viewed_at': '2026-10-05T08:15:00.000000Z',
          'user': {
            'id': 987654,
            'name': 'سارة',
            'avatar_path': '/storage/avatars/sara.png',
          },
        },
      ],
    );
    final viewers = await setup.repo.viewers(12);
    expect(setup.calls.single.method, 'GET');
    expect(setup.calls.single.path, 'stories/12/viewers');
    expect(setup.calls.single.headers['X-Workspace-Id'], '4');
    expect(viewers, hasLength(1));
    expect(viewers.single.name, 'سارة');
    expect(viewers.single.avatarUrl, AppConfig.resolvePublicUrl('/storage/avatars/sara.png'));
    expect(viewers.single.viewedAt?.toUtc().toIso8601String(), '2026-10-05T08:15:00.000Z');
  });

  test('delete surfaces author-only 403 from Laravel', () async {
    final setup = await repository(
      status: 403,
      message: 'يمكنك حذف قصصك فقط.',
    );
    expect(
      () => setup.repo.delete(12),
      throwsA(
        isA<ApiException>()
            .having((e) => e.statusCode, 'status', 403)
            .having((e) => e.userMessage, 'message', 'يمكنك حذف قصصك فقط.'),
      ),
    );
  });

  test('viewers surfaces author-only 403 from Laravel', () async {
    final setup = await repository(
      status: 403,
      message: 'عرض المشاهدين متاح للكاتب فقط.',
    );
    expect(
      () => setup.repo.viewers(12),
      throwsA(
        isA<ApiException>()
            .having((e) => e.statusCode, 'status', 403)
            .having(
              (e) => e.userMessage,
              'message',
              'عرض المشاهدين متاح للكاتب فقط.',
            ),
      ),
    );
  });

  test('delete surfaces workspace-scoped 404', () async {
    final setup = await repository(
      status: 404,
      message: 'No query results for model [App\\Models\\Story] 12',
    );
    expect(
      () => setup.repo.delete(12),
      throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 404)),
    );
  });
}
