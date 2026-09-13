import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../auth/cloud_link_store.dart';
import '../config/app_config.dart';
import '../network/cashier_link.dart';
import 'cashier_network_policy.dart';
import 'cashier_request_auth.dart';
import 'network_guard.dart';

final secureStorageProvider = Provider<FlutterSecureStorage>((ref) {
  return const FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );
});

final cloudLinkStoreProvider = Provider<CloudLinkStore>((ref) {
  return CloudLinkStore.secure(ref.watch(secureStorageProvider));
});

final authTokenProvider = StateProvider<String?>((ref) => null);
final workspaceIdProvider = StateProvider<int?>((ref) => null);
final deviceIdHeaderProvider = StateProvider<String?>((ref) => null);

/// In-memory Sanctum link. PIN `cashier_token` stays standalone after bind.
final cloudLinkSessionProvider = StateProvider<CloudLinkSnapshot?>((ref) => null);

final dioProvider = Provider<Dio>((ref) {
  final dio = Dio(
    BaseOptions(
      baseUrl: AppConfig.apiRoot,
      connectTimeout: AppConfig.connectTimeout,
      receiveTimeout: AppConfig.receiveTimeout,
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
    ),
  );

  dio.interceptors.add(
    InterceptorsWrapper(
      onRequest: (options, handler) {
        NetworkGuard.recordAttempt();
        final cloud = CashierRequestAuth.activeLink(
          ref.read(cloudLinkSessionProvider),
        );
        final token = CashierRequestAuth.bearerToken(
          sessionToken: ref.read(authTokenProvider),
          cloud: cloud,
        );
        final workspaceId = CashierRequestAuth.workspaceId(
          sessionWorkspaceId: ref.read(workspaceIdProvider),
          cloud: cloud,
        );
        final deviceId = CashierRequestAuth.deviceId(
          sessionDeviceId: ref.read(deviceIdHeaderProvider),
          cloud: cloud,
        );
        // Standalone PIN sessions may still push takeaway ops when a Sanctum
        // cloud link is hydrated. offlineOnly still blocks invoices /
        // payments / `/orders` writes. Phase 1A setup, Phase 1B snapshot,
        // and Phase 2B takeaway `/sync/push` are allowed with that token.
        if (!CashierNetworkPolicy.allowRequest(
          offlineOnly: AppConfig.offlineOnly,
          token: token,
          path: options.path,
          method: options.method,
        )) {
          handler.reject(
            DioException(
              requestOptions: options,
              type: DioExceptionType.connectionError,
              error: 'offline_only_no_network',
              message: AppConfig.offlineOnly
                  ? 'التطبيق يعمل أوفلاين بالكامل — لا اتصال بالخادم.'
                  : 'الوضع المحلي لا يتصل بـ Laravel.',
            ),
          );
          return;
        }
        if (token != null && token.isNotEmpty) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        if (workspaceId != null) {
          options.headers['X-Workspace-Id'] = workspaceId.toString();
        }
        if (deviceId != null && deviceId.isNotEmpty) {
          options.headers['X-Device-Id'] = deviceId;
        }
        handler.next(options);
      },
      onResponse: (response, handler) {
        ref.read(cashierLinkProvider.notifier).onApiSuccess();
        handler.next(response);
      },
      onError: (error, handler) {
        final status =
            error.response?.statusCode ??
            ((error.type == DioExceptionType.connectionError ||
                    error.type == DioExceptionType.connectionTimeout ||
                    error.type == DioExceptionType.receiveTimeout ||
                    (error.type == DioExceptionType.unknown &&
                        error.response == null))
                ? 0
                : null);
        if (status == 401) {
          final token = ref.read(authTokenProvider);
          if (token == null ||
              (!token.startsWith('standalone:') && token != 'local-offline')) {
            ref.read(authTokenProvider.notifier).state = null;
          }
        }
        ref.read(cashierLinkProvider.notifier).onApiFailure(statusCode: status);
        handler.next(error);
      },
    ),
  );

  return dio;
});

class ApiException implements Exception {
  ApiException(this.message, {this.statusCode, this.errors});

  final String message;
  final int? statusCode;
  final Map<String, dynamic>? errors;

  bool get isUnauthorized => statusCode == 401;
  bool get isForbidden => statusCode == 403;
  bool get isNetwork => statusCode == 0;
  bool get isServer => statusCode != null && statusCode! >= 500;
  bool get isUnavailable => isNetwork || isServer;

  @override
  String toString() => message;
}

class CashierApiClient {
  CashierApiClient(this._dio);

  final Dio _dio;

  Future<Map<String, dynamic>> post(
    String path, {
    Map<String, dynamic>? data,
    String? idempotencyKey,
  }) async {
    try {
      final response = await _dio.post(
        path,
        data: data,
        options: Options(
          headers: {
            if (idempotencyKey != null) 'Idempotency-Key': idempotencyKey,
          },
        ),
      );
      return _unwrap(response);
    } on DioException catch (e) {
      throw _mapError(e);
    }
  }

  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
  }) async {
    try {
      final response = await _dio.get(path, queryParameters: query);
      return _unwrap(response);
    } on DioException catch (e) {
      throw _mapError(e);
    }
  }

  Future<Map<String, dynamic>> put(
    String path, {
    Map<String, dynamic>? data,
  }) async {
    try {
      final response = await _dio.put(path, data: data);
      return _unwrap(response);
    } on DioException catch (e) {
      throw _mapError(e);
    }
  }

  Future<Map<String, dynamic>> patch(
    String path, {
    Map<String, dynamic>? data,
  }) async {
    try {
      final response = await _dio.patch(path, data: data);
      return _unwrap(response);
    } on DioException catch (e) {
      throw _mapError(e);
    }
  }

  Future<Map<String, dynamic>> postMultipart(
    String path, {
    required FormData data,
    String? idempotencyKey,
  }) async {
    try {
      final response = await _dio.post(
        path,
        data: data,
        options: Options(
          contentType: 'multipart/form-data',
          headers: {
            if (idempotencyKey != null) 'Idempotency-Key': idempotencyKey,
          },
        ),
      );
      return _unwrap(response);
    } on DioException catch (e) {
      throw _mapError(e);
    }
  }

  Future<Map<String, dynamic>> delete(String path) async {
    try {
      final response = await _dio.delete(path);
      return _unwrap(response);
    } on DioException catch (e) {
      throw _mapError(e);
    }
  }

  Map<String, dynamic> _unwrap(Response response) {
    final body = response.data;
    if (body is! Map) {
      throw ApiException('استجابة غير صالحة من الخادم.');
    }
    final map = Map<String, dynamic>.from(body);
    if (map['success'] == false) {
      throw ApiException(
        (map['message'] as String?) ?? 'فشلت العملية.',
        statusCode: response.statusCode,
        errors: map['errors'] is Map
            ? Map<String, dynamic>.from(map['errors'] as Map)
            : null,
      );
    }
    final data = map['data'];
    final result = <String, dynamic>{};
    if (data is Map) {
      result.addAll(Map<String, dynamic>.from(data));
    } else {
      result['value'] = data;
    }
    if (map['message'] is String && result['message'] == null) {
      result['message'] = map['message'];
    }
    // Laravel puts pagination on the envelope (`meta`), not inside `data`.
    if (map['meta'] is Map) {
      result['meta'] = Map<String, dynamic>.from(map['meta'] as Map);
    }
    return result;
  }

  ApiException _mapError(DioException e) {
    final status = e.response?.statusCode;
    final data = e.response?.data;
    Map<String, dynamic>? errors;
    if (data is Map && data['errors'] is Map) {
      errors = Map<String, dynamic>.from(data['errors'] as Map);
    }

    String? serverMessage;
    if (data is Map && data['message'] is String) {
      serverMessage = data['message'] as String;
    }

    // Prefer field-level 422 validation messages when present.
    if (status == 422 && errors != null && errors.isNotEmpty) {
      final parts = <String>[];
      for (final entry in errors.entries) {
        final value = entry.value;
        if (value is List && value.isNotEmpty) {
          parts.add(value.first.toString());
        } else if (value != null) {
          parts.add(value.toString());
        }
      }
      if (parts.isNotEmpty) {
        return ApiException(
          parts.join('\n'),
          statusCode: status,
          errors: errors,
        );
      }
    }

    // Prefer Arabic copy for throttle / known transport statuses.
    final localized = switch (status) {
      400 => 'طلب غير صالح.',
      401 => 'انتهت الجلسة. سجّل الدخول مجددًا.',
      403 => 'لا تملك صلاحية تنفيذ هذه العملية.',
      404 => 'العنصر غير موجود.',
      409 => 'تعارض في حالة الطلب. حدّث الصفحة وحاول مجددًا.',
      422 => 'تعذر التحقق من البيانات المرسلة.',
      429 => 'محاولات كثيرة. انتظر قليلًا ثم أعد المحاولة.',
      500 || 502 || 503 => 'خطأ في الخادم. حاول لاحقًا.',
      _ => null,
    };
    if (localized != null &&
        (status == 429 ||
            serverMessage == null ||
            serverMessage.trim().isEmpty ||
            serverMessage.toLowerCase().contains('too many'))) {
      return ApiException(localized, statusCode: status, errors: errors);
    }

    if (serverMessage != null && serverMessage.trim().isNotEmpty) {
      return ApiException(serverMessage, statusCode: status, errors: errors);
    }

    if (e.type == DioExceptionType.connectionError ||
        e.type == DioExceptionType.connectionTimeout ||
        e.type == DioExceptionType.receiveTimeout ||
        e.type == DioExceptionType.unknown && e.response == null) {
      return ApiException(
        'تعذر الاتصال بالخادم. تحقق من الإنترنت وحاول مجددًا.',
        statusCode: 0,
      );
    }
    return ApiException(
      localized ?? 'تعذر إكمال الطلب.',
      statusCode: status,
      errors: errors,
    );
  }
}

final cashierApiProvider = Provider<CashierApiClient>((ref) {
  return CashierApiClient(ref.watch(dioProvider));
});
