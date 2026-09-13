import 'dart:async';
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/network/api_response.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/utils/idempotency.dart';

typedef UnauthorizedCallback = void Function();

/// Broadcast stream for 401 responses — avoids circular Riverpod DI.
final unauthorizedEvents = StreamController<void>.broadcast();

class ApiClient {
  ApiClient({
    required SecureStore secureStore,
    required PrefsStore prefsStore,
    Dio? dio,
    UnauthorizedCallback? onUnauthorized,
  })  : _secureStore = secureStore,
        _prefsStore = prefsStore,
        _onUnauthorized = onUnauthorized {
    final base = AppConfig.normalizeHostBase(
      prefsStore.apiBaseOverride ?? AppConfig.apiBase,
    );
    _dio = dio ??
        Dio(
          BaseOptions(
            // Trailing slash required: Dio must keep /api/mobile/v1/ as path prefix.
            baseUrl: '$base/api/mobile/v1/',
            connectTimeout: const Duration(seconds: 20),
            receiveTimeout: const Duration(seconds: 30),
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
            },
          ),
        );

    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          try {
            final token = await _secureStore.readToken();
            if (token != null && token.isNotEmpty) {
              options.headers['Authorization'] = 'Bearer $token';
            }
          } catch (e, st) {
            // Web secure-storage failures must not abort the whole request as "unexpected".
            debugPrint('[HasimAPI] secureStore.readToken failed: $e');
            debugPrint('$st');
          }
          final workspaceId = _prefsStore.workspaceId;
          if (workspaceId != null) {
            options.headers['X-Workspace-Id'] = workspaceId.toString();
          }
          if (kDebugMode) {
            debugPrint('[HasimAPI] → ${options.method} ${options.uri}');
          }
          return handler.next(options);
        },
        onResponse: (response, handler) {
          if (kDebugMode) {
            _debugLogExchange(
              label: '← OK',
              request: response.requestOptions,
              statusCode: response.statusCode,
              rawBody: response.data,
              dioType: null,
            );
          }
          return handler.next(response);
        },
        onError: (error, handler) {
          if (error.response?.statusCode == 401) {
            _emitUnauthorized();
          }
          if (kDebugMode) {
            _debugLogExchange(
              label: '← ERR',
              request: error.requestOptions,
              statusCode: error.response?.statusCode,
              rawBody: error.response?.data,
              dioType: error.type,
              dioMessage: error.message,
            );
          }
          return handler.next(error);
        },
      ),
    );
  }

  final SecureStore _secureStore;
  final PrefsStore _prefsStore;
  UnauthorizedCallback? _onUnauthorized;
  late final Dio _dio;
  bool _unauthorizedEmitted = false;

  Dio get raw => _dio;

  void setOnUnauthorized(UnauthorizedCallback? callback) {
    _onUnauthorized = callback;
  }

  void resetUnauthorizedGate() {
    _unauthorizedEmitted = false;
  }

  void _emitUnauthorized() {
    if (_unauthorizedEmitted) return;
    _unauthorizedEmitted = true;
    _onUnauthorized?.call();
    if (!unauthorizedEvents.isClosed) {
      unauthorizedEvents.add(null);
    }
  }

  /// Keep paths relative to `baseUrl` (`…/api/mobile/v1/`). Leading `/` would drop the prefix in some URI resolvers.
  String _rel(String path) {
    var p = path.trim();
    while (p.startsWith('/')) {
      p = p.substring(1);
    }
    return p;
  }

  void updateBaseUrl(String apiBase) {
    final host = AppConfig.normalizeHostBase(apiBase);
    _dio.options.baseUrl = '$host/api/mobile/v1/';
  }

  /// Flutter Web / dart2js may decode JSON objects as [Map] that are not
  /// exactly `Map<String, dynamic>`, which breaks `as Map<String, dynamic>`
  /// and Dio's typed `Response<Map<String, dynamic>>` casts → DioException.unknown
  /// → «حدث خطأ غير متوقع».
  Map<String, dynamic> _asStringKeyedMap(dynamic raw, {required String context}) {
    if (raw == null) return <String, dynamic>{};
    if (raw is Map<String, dynamic>) return raw;
    if (raw is Map) {
      return Map<String, dynamic>.from(raw);
    }
    if (raw is String && raw.trim().isNotEmpty) {
      final decoded = jsonDecode(raw);
      if (decoded is Map) return Map<String, dynamic>.from(decoded);
    }
    throw ApiException('استجابة غير صالحة ($context): ${raw.runtimeType}');
  }

  Future<ApiResponse<T>> get<T>(
    String path, {
    Map<String, dynamic>? query,
    T Function(dynamic raw)? mapData,
  }) async {
    try {
      final res = await _dio.get<dynamic>(_rel(path), queryParameters: query);
      final map = _asStringKeyedMap(res.data, context: 'GET $path');
      return ApiResponse.fromJson(map, mapData);
    } on ApiException {
      rethrow;
    } on DioException catch (e) {
      throw _mapDio(e);
    }
  }

  Future<ApiResponse<T>> post<T>(
    String path, {
    Object? body,
    Map<String, dynamic>? query,
    bool idempotent = false,
    T Function(dynamic raw)? mapData,
  }) async {
    try {
      final headers = <String, dynamic>{};
      if (idempotent) {
        headers['Idempotency-Key'] = newIdempotencyKey();
      }
      final res = await _dio.post<dynamic>(
        _rel(path),
        data: body,
        queryParameters: query,
        options: Options(headers: headers),
      );
      final map = _asStringKeyedMap(res.data, context: 'POST $path');
      return ApiResponse.fromJson(map, mapData);
    } on ApiException {
      rethrow;
    } on DioException catch (e) {
      throw _mapDio(e);
    }
  }

  Future<ApiResponse<T>> put<T>(
    String path, {
    Object? body,
    T Function(dynamic raw)? mapData,
  }) async {
    try {
      final res = await _dio.put<dynamic>(_rel(path), data: body);
      final map = _asStringKeyedMap(res.data, context: 'PUT $path');
      return ApiResponse.fromJson(map, mapData);
    } on ApiException {
      rethrow;
    } on DioException catch (e) {
      throw _mapDio(e);
    }
  }

  Future<ApiResponse<T>> patch<T>(
    String path, {
    Object? body,
    T Function(dynamic raw)? mapData,
  }) async {
    try {
      final res = await _dio.patch<dynamic>(_rel(path), data: body);
      final map = _asStringKeyedMap(res.data, context: 'PATCH $path');
      return ApiResponse.fromJson(map, mapData);
    } on ApiException {
      rethrow;
    } on DioException catch (e) {
      throw _mapDio(e);
    }
  }

  Future<ApiResponse<T>> delete<T>(
    String path, {
    T Function(dynamic raw)? mapData,
  }) async {
    try {
      final res = await _dio.delete<dynamic>(_rel(path));
      final map = _asStringKeyedMap(res.data, context: 'DELETE $path');
      return ApiResponse.fromJson(map, mapData);
    } on ApiException {
      rethrow;
    } on DioException catch (e) {
      throw _mapDio(e);
    }
  }

  Future<ApiResponse<T>> upload<T>(
    String path, {
    required FormData formData,
    bool idempotent = true,
    void Function(int sent, int total)? onSendProgress,
    T Function(dynamic raw)? mapData,
  }) async {
    try {
      final headers = <String, dynamic>{};
      if (idempotent) {
        headers['Idempotency-Key'] = newIdempotencyKey();
      }
      final res = await _dio.post<dynamic>(
        _rel(path),
        data: formData,
        onSendProgress: onSendProgress,
        options: Options(headers: headers, contentType: 'multipart/form-data'),
      );
      final map = _asStringKeyedMap(res.data, context: 'UPLOAD $path');
      return ApiResponse.fromJson(map, mapData);
    } on ApiException {
      rethrow;
    } on DioException catch (e) {
      throw _mapDio(e);
    }
  }

  ApiException _mapDio(DioException e) {
    final status = e.response?.statusCode;
    final data = e.response?.data;

    if (status == 401) {
      _emitUnauthorized();
    }

    final map = () {
      try {
        if (data == null) return null;
        if (data is Map) return Map<String, dynamic>.from(data);
        if (data is String && data.trim().startsWith('{')) {
          final decoded = jsonDecode(data);
          if (decoded is Map) return Map<String, dynamic>.from(decoded);
        }
      } catch (_) {}
      return null;
    }();

    if (map != null) {
      final message = map['message']?.toString();
      if (message != null && message.isNotEmpty) {
        return ApiException(
          message,
          statusCode: status,
          errors: map['errors'] is Map
              ? Map<String, dynamic>.from(map['errors'] as Map)
              : null,
        );
      }
    }

    if (e.type == DioExceptionType.connectionTimeout ||
        e.type == DioExceptionType.receiveTimeout ||
        e.type == DioExceptionType.sendTimeout ||
        e.type == DioExceptionType.connectionError) {
      final hint = (e.message ?? '').toLowerCase().contains('cors')
          ? ' (قد يكون السبب CORS من المتصفح)'
          : '';
      return ApiException('تعذر الاتصال بالخادم. تحقق من الشبكة.$hint', statusCode: status);
    }

    if (status == 401) {
      return ApiException('انتهت جلسة تسجيل الدخول.', statusCode: status);
    }
    if (status == 403) {
      return ApiException('لا تملك صلاحية تنفيذ هذا الإجراء.', statusCode: status);
    }
    if (status == 404) {
      return ApiException('العنصر المطلوب غير موجود.', statusCode: status);
    }
    if (status == 422) {
      return ApiException('بيانات غير صالحة.', statusCode: status);
    }
    if (status == 429) {
      return ApiException('محاولات كثيرة. حاول لاحقاً.', statusCode: status);
    }

    if (kDebugMode) {
      debugPrint(
        '[HasimAPI] mapped unexpected DioException type=${e.type} status=$status message=${e.message}',
      );
    }

    // Keep user-facing text stable; details go to console.
    return ApiException('حدث خطأ غير متوقع.', statusCode: status);
  }

  /// Prints request/response diagnostics without secrets (password/token/Authorization).
  static void _debugLogExchange({
    required String label,
    required RequestOptions request,
    required int? statusCode,
    required Object? rawBody,
    required DioExceptionType? dioType,
    String? dioMessage,
  }) {
    final redactedHeaders = Map<String, dynamic>.from(request.headers);
    for (final key in redactedHeaders.keys.toList()) {
      final lower = key.toLowerCase();
      if (lower == 'authorization' || lower.contains('token') || lower.contains('password')) {
        redactedHeaders[key] = '[REDACTED]';
      }
    }

    Object? body = request.data;
    if (body is Map) {
      final copy = Map<String, dynamic>.from(body);
      for (final key in copy.keys.toList()) {
        final lower = key.toLowerCase();
        if (lower.contains('password') || lower.contains('token') || lower == 'access_token') {
          copy[key] = '[REDACTED]';
        }
      }
      body = copy;
    }

    final safeResponse = _redactResponseBody(rawBody);

    debugPrint('[HasimAPI] $label');
    debugPrint('  url: ${request.method} ${request.uri}');
    debugPrint('  status: $statusCode');
    if (dioType != null) debugPrint('  dioType: $dioType');
    if (dioMessage != null && dioMessage.isNotEmpty) debugPrint('  dioMessage: $dioMessage');
    debugPrint('  requestHeaders: $redactedHeaders');
    debugPrint('  requestBody: $body');
    debugPrint('  responseBody: $safeResponse');
  }

  static Object? _redactResponseBody(Object? raw) {
    try {
      if (raw is Map) {
        final copy = Map<String, dynamic>.from(raw);
        if (copy['data'] is Map) {
          final data = Map<String, dynamic>.from(copy['data'] as Map);
          if (data.containsKey('token')) data['token'] = '[REDACTED]';
          if (data.containsKey('access_token')) data['access_token'] = '[REDACTED]';
          copy['data'] = data;
        }
        return copy;
      }
      if (raw is String) {
        final trimmed = raw.length > 800 ? '${raw.substring(0, 800)}…' : raw;
        return trimmed.replaceAll(RegExp(r'"token"\s*:\s*"[^"]*"'), '"token":"[REDACTED]"');
      }
      return raw;
    } catch (_) {
      return raw?.runtimeType;
    }
  }
}
