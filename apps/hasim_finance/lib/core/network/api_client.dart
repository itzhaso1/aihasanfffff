import 'dart:async';
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:hasim_finance/core/config/app_config.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/network/api_response.dart';
import 'package:hasim_finance/core/storage/prefs_store.dart';
import 'package:hasim_finance/core/storage/secure_store.dart';
import 'package:uuid/uuid.dart';

typedef UnauthorizedCallback = void Function();

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
            baseUrl: AppConfig.financeApiBase(base),
            connectTimeout: const Duration(seconds: 20),
            receiveTimeout: const Duration(seconds: 60),
            headers: {
              'Accept': 'application/json',
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
            debugPrint('[FinanceAPI] secureStore.readToken failed: $e\n$st');
          }
          final workspaceId = _prefsStore.workspaceId;
          if (workspaceId != null) {
            options.headers['X-Workspace-Id'] = workspaceId.toString();
          }
          return handler.next(options);
        },
        onError: (error, handler) {
          if (error.response?.statusCode == 401) {
            _emitUnauthorized();
          }
          return handler.next(error);
        },
      ),
    );
  }

  final SecureStore _secureStore;
  final PrefsStore _prefsStore;
  final UnauthorizedCallback? _onUnauthorized;
  late final Dio _dio;
  bool _unauthorizedEmitted = false;

  void resetUnauthorizedGate() {
    _unauthorizedEmitted = false;
  }

  void updateBaseUrl(String apiBase) {
    _dio.options.baseUrl = AppConfig.financeApiBase(apiBase);
  }

  void _emitUnauthorized() {
    if (_unauthorizedEmitted) return;
    _unauthorizedEmitted = true;
    _onUnauthorized?.call();
    if (!unauthorizedEvents.isClosed) {
      unauthorizedEvents.add(null);
    }
  }

  String _rel(String path) {
    var p = path.trim();
    while (p.startsWith('/')) {
      p = p.substring(1);
    }
    return p;
  }

  Map<String, dynamic> _asMap(dynamic raw, {required String context}) {
    if (raw == null) return <String, dynamic>{};
    if (raw is Map<String, dynamic>) return raw;
    if (raw is Map) return Map<String, dynamic>.from(raw);
    if (raw is String && raw.trim().isNotEmpty) {
      final decoded = jsonDecode(raw);
      if (decoded is Map) return Map<String, dynamic>.from(decoded);
    }
    throw ApiException('استجابة غير صالحة ($context)');
  }

  Future<ApiResponse<T>> get<T>(
    String path, {
    Map<String, dynamic>? query,
    T Function(dynamic raw)? mapData,
  }) async {
    try {
      final res = await _dio.get<dynamic>(_rel(path), queryParameters: query);
      return ApiResponse.fromJson(_asMap(res.data, context: 'GET $path'), mapData);
    } on ApiException {
      rethrow;
    } on DioException catch (e) {
      throw _mapDio(e);
    }
  }

  Future<ApiResponse<T>> post<T>(
    String path, {
    Object? body,
    bool idempotent = false,
    T Function(dynamic raw)? mapData,
  }) async {
    try {
      final headers = <String, dynamic>{};
      if (idempotent) {
        headers['Idempotency-Key'] = const Uuid().v4();
      }
      final res = await _dio.post<dynamic>(
        _rel(path),
        data: body,
        options: Options(headers: headers),
      );
      return ApiResponse.fromJson(_asMap(res.data, context: 'POST $path'), mapData);
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
      return ApiResponse.fromJson(_asMap(res.data, context: 'PUT $path'), mapData);
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
      return ApiResponse.fromJson(_asMap(res.data, context: 'DELETE $path'), mapData);
    } on ApiException {
      rethrow;
    } on DioException catch (e) {
      throw _mapDio(e);
    }
  }

  Future<Uint8List> downloadBytes(String path, {Map<String, dynamic>? query}) async {
    try {
      final res = await _dio.get<List<int>>(
        _rel(path),
        queryParameters: query,
        options: Options(responseType: ResponseType.bytes),
      );
      final data = res.data;
      if (data == null) {
        throw ApiException('الملف فارغ.');
      }
      return Uint8List.fromList(data);
    } on ApiException {
      rethrow;
    } on DioException catch (e) {
      throw _mapDio(e);
    }
  }

  Future<ApiResponse<T>> upload<T>(
    String path, {
    required FormData formData,
    T Function(dynamic raw)? mapData,
  }) async {
    try {
      final res = await _dio.post<dynamic>(
        _rel(path),
        data: formData,
        options: Options(contentType: 'multipart/form-data'),
      );
      return ApiResponse.fromJson(_asMap(res.data, context: 'UPLOAD $path'), mapData);
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

    Map<String, dynamic>? map;
    try {
      if (data is Map) {
        map = Map<String, dynamic>.from(data);
      } else if (data is List<int>) {
        final decoded = jsonDecode(utf8.decode(data));
        if (decoded is Map) map = Map<String, dynamic>.from(decoded);
      } else if (data is String && data.trim().startsWith('{')) {
        final decoded = jsonDecode(data);
        if (decoded is Map) map = Map<String, dynamic>.from(decoded);
      }
    } catch (_) {}

    if (map != null) {
      final message = map['message']?.toString();
      if (message != null && message.isNotEmpty) {
        return ApiException(
          _arabicMessage(message),
          statusCode: status,
          code: map['code']?.toString(),
          errors: map['errors'] is Map ? Map<String, dynamic>.from(map['errors'] as Map) : null,
        );
      }
    }

    if (e.type == DioExceptionType.connectionTimeout ||
        e.type == DioExceptionType.receiveTimeout ||
        e.type == DioExceptionType.sendTimeout ||
        e.type == DioExceptionType.connectionError) {
      return ApiException('تعذر الاتصال بالخادم. تحقق من الشبكة.', statusCode: status);
    }

    return ApiException(switch (status) {
      401 => 'انتهت جلسة تسجيل الدخول.',
      403 => 'لا تملك صلاحية تنفيذ هذا الإجراء.',
      404 => 'العنصر المطلوب غير موجود.',
      409 => 'تعارض في حالة المستند.',
      422 => 'بيانات غير صالحة.',
      429 => 'محاولات كثيرة. حاول لاحقاً.',
      500 => 'حدث خطأ في الخادم.',
      _ => 'حدث خطأ غير متوقع.',
    }, statusCode: status);
  }

  String _arabicMessage(String message) {
    return switch (message) {
      'Unauthenticated.' => 'انتهت جلسة تسجيل الدخول.',
      'Workspace context is required.' => 'يلزم اختيار منشأة مالية.',
      'You are not allowed to access this financial resource.' => 'لا تملك صلاحية تنفيذ هذا الإجراء.',
      'Invoice is already paid.' => 'الفاتورة مسددة بالكامل.',
      _ => message,
    };
  }
}
