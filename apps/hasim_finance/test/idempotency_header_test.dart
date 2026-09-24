import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_finance/core/api/finance_api.dart';
import 'package:hasim_finance/core/network/api_client.dart';
import 'package:hasim_finance/core/storage/prefs_store.dart';
import 'package:hasim_finance/core/storage/secure_store.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('assistant ask reuses provided idempotency key', () async {
    SharedPreferences.setMockInitialValues({});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    final capturedKeys = <String>[];
    final dio = Dio(BaseOptions(baseUrl: 'https://example.test/api/finance/v1/'));
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          final key = options.headers['Idempotency-Key'];
          if (key != null) capturedKeys.add('$key');
          handler.resolve(
            Response(
              requestOptions: options,
              statusCode: 200,
              data: {
                'success': true,
                'data': {
                  'answer': 'ok',
                  'conversation_id': 1,
                  'facts': [],
                },
              },
            ),
          );
        },
      ),
    );

    final client = ApiClient(
      secureStore: SecureStore(),
      prefsStore: prefs,
      dio: dio,
    );
    final api = FinanceApi(client);
    const key = 'fixed-idempotency-key';

    await api.askAssistant(question: 'test', idempotencyKey: key);
    await api.askAssistant(question: 'test', idempotencyKey: key);

    expect(capturedKeys, [key, key]);
  });

  test('assistant ask body excludes tenant identity fields', () async {
    SharedPreferences.setMockInitialValues({'workspace_id': 99});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    Map<String, dynamic>? capturedBody;
    final dio = Dio(BaseOptions(baseUrl: 'https://example.test/api/finance/v1/'));
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          capturedBody = Map<String, dynamic>.from(options.data as Map);
          handler.resolve(
            Response(
              requestOptions: options,
              statusCode: 200,
              data: {
                'success': true,
                'data': {
                  'answer': 'ok',
                  'conversation_id': 2,
                  'facts': [],
                },
              },
            ),
          );
        },
      ),
    );

    final client = ApiClient(
      secureStore: SecureStore(),
      prefsStore: prefs,
      dio: dio,
    );
    final api = FinanceApi(client);

    await api.askAssistant(question: 'كم الربح؟', conversationId: 2);

    expect(capturedBody, {
      'question': 'كم الربح؟',
      'locale': 'ar',
      'conversation_id': 2,
    });
    expect(capturedBody!.containsKey('workspace_id'), isFalse);
    expect(capturedBody!.containsKey('user_id'), isFalse);
  });
}
