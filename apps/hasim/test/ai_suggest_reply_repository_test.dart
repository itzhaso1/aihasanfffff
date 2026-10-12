import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/settings/data/plan_channel_repository.dart';
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

  Future<({AiRepository repo, List<RequestOptions> calls})> repository({
    required Object data,
    int status = 200,
    String? message,
  }) async {
    SharedPreferences.setMockInitialValues({'workspace_id': 4});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    final calls = <RequestOptions>[];
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1/api/mobile/v1/'));
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
                    'message': message ?? 'تعذر توليد اقتراح الرد حالياً.',
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
              data: {'success': true, 'data': data},
            ),
          );
        },
      ),
    );
    return (
      repo: AiRepository(
        ApiClient(secureStore: _MemorySecureStore(), prefsStore: prefs, dio: dio),
      ),
      calls: calls,
    );
  }

  test('suggest reply returns the final suggestion string only', () async {
    final setup = await repository(
      data: {
        'suggestion': 'المنتج متوفر عندنا',
        'meta': {'persisted': false},
      },
    );

    final text = await setup.repo.suggestReply(12);

    expect(setup.calls.single.method, 'POST');
    expect(setup.calls.single.path, 'ai/suggest-reply');
    expect(setup.calls.single.data['persist'], false);
    expect(text, 'المنتج متوفر عندنا');
    expect(text.contains('Since the user asked'), isFalse);
    expect(text.contains('meta'), isFalse);
  });

  test('suggest reply ignores reasoning and does not dump the raw payload', () async {
    final setup = await repository(
      data: {
        'suggestion': {
          'reply': 'أهلا، كيف أقدر أساعدك؟',
          'reasoning': 'Since the user asked for help.',
          'system': 'You are Hasim.',
        },
        'meta': {'persisted': false},
      },
    );

    final text = await setup.repo.suggestReply(12);

    expect(text, 'أهلا، كيف أقدر أساعدك؟');
    expect(text.contains('Since the user asked'), isFalse);
    expect(text.contains('You are Hasim'), isFalse);
    expect(text.contains('reasoning'), isFalse);
  });

  test('malformed or empty AI payloads do not become composer text', () async {
    final malformed = await repository(data: {'suggestion': '{"reply":'});
    await expectLater(malformed.repo.suggestReply(12), throwsA(isA<ApiException>()));

    final list = await repository(data: ['Since the user asked', 'أهلا']);
    await expectLater(list.repo.suggestReply(12), throwsA(isA<ApiException>()));

    final empty = await repository(data: {'suggestion': '', 'meta': 'debug'});
    await expectLater(empty.repo.suggestReply(12), throwsA(isA<ApiException>()));
  });

  test('failed AI suggestion does not return leftover payload text', () async {
    final setup = await repository(data: {}, status: 503);

    await expectLater(setup.repo.suggestReply(12), throwsA(isA<ApiException>()));
  });

  test('summarize returns the summary field only', () async {
    final setup = await repository(
      data: {
        'summary': 'العميل يسأل عن السعر',
        'debug': 'SYSTEM: hidden',
      },
    );

    expect(await setup.repo.summarize(12), 'العميل يسأل عن السعر');
  });
}
