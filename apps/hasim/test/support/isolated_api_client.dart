import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';

/// Dio that fails the test on any outbound HTTP call (test isolation guard).
Dio isolatedTestDio({String baseUrl = 'http://127.0.0.1:1/'}) {
  final dio = Dio(
    BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(milliseconds: 50),
      receiveTimeout: const Duration(milliseconds: 50),
    ),
  );
  dio.interceptors.add(
    InterceptorsWrapper(
      onRequest: (options, handler) {
        fail(
          'Unexpected HTTP in test: ${options.method} ${options.uri}',
        );
      },
    ),
  );
  return dio;
}

/// [ApiClient] wired to [isolatedTestDio] — safe to pass into fake repository constructors.
ApiClient isolatedTestApiClient({
  required SecureStore secureStore,
  required PrefsStore prefsStore,
}) {
  return ApiClient(
    secureStore: secureStore,
    prefsStore: prefsStore,
    dio: isolatedTestDio(),
  );
}
