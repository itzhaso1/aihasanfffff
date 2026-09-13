import 'dart:io';

import 'package:dio/dio.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class AuthRepository {
  AuthRepository(this._api);
  final ApiClient _api;

  Future<LoginResult> login({
    required String emailOrPhone,
    required String password,
    int? workspaceId,
    String deviceName = 'حاسم Flutter',
    String deviceType = 'mobile',
  }) async {
    final res = await _api.post<LoginResult>(
      '/auth/login',
      body: {
        'email_or_phone': emailOrPhone,
        'password': password,
        'workspace_id': ?workspaceId,
        'device_name': deviceName,
        'device_type': deviceType,
      },
      mapData: (raw) {
        final map = asMap(raw);
        if (map == null) throw ApiException('استجابة تسجيل الدخول غير صالحة.');
        return LoginResult.fromJson(map);
      },
    );
    if (!res.success || res.data == null || res.data!.token.isEmpty) {
      throw ApiException(res.message ?? 'تعذر تسجيل الدخول.');
    }
    return res.data!;
  }

  Future<LoginResult> socialLogin({
    required String provider,
    required String accessToken,
    int? workspaceId,
    String deviceName = 'حاسم Flutter',
    String deviceType = 'mobile',
  }) async {
    final res = await _api.post<LoginResult>(
      '/auth/social',
      body: {
        'provider': provider,
        'access_token': accessToken,
        'workspace_id': ?workspaceId,
        'device_name': deviceName,
        'device_type': deviceType,
      },
      mapData: (raw) {
        final map = asMap(raw);
        if (map == null) throw ApiException('استجابة تسجيل الدخول الاجتماعي غير صالحة.');
        return LoginResult.fromJson(map);
      },
    );
    if (!res.success || res.data == null || res.data!.token.isEmpty) {
      throw ApiException(res.message ?? 'تعذر تسجيل الدخول عبر Google.');
    }
    return res.data!;
  }

  Future<String> forgotPassword(String email) async {
    final res = await _api.post('/auth/forgot-password', body: {'email': email});
    if (!res.success) {
      throw ApiException(res.message ?? 'تعذر إرسال رابط إعادة التعيين.');
    }
    return res.message ?? 'تم إرسال رابط إعادة تعيين كلمة المرور إلى بريدك الإلكتروني.';
  }

  Future<String> resetPassword({
    required String email,
    required String token,
    required String password,
    required String passwordConfirmation,
  }) async {
    final res = await _api.post('/auth/reset-password', body: {
      'email': email,
      'token': token,
      'password': password,
      'password_confirmation': passwordConfirmation,
    });
    if (!res.success) {
      throw ApiException(res.message ?? 'تعذر إعادة تعيين كلمة المرور.');
    }
    return res.message ?? 'تم إعادة تعيين كلمة المرور بنجاح.';
  }

  Future<void> logout() async {
    await _api.post('/auth/logout');
  }

  Future<({UserModel user, WorkspaceModel? workspace, List<WorkspaceModel> workspaces})> me() async {
    final res = await _api.get('/auth/me');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر جلب الملف الشخصي.');
    final workspaces = asMapList(map['workspaces']).map(WorkspaceModel.fromJson).toList();
    return (
      user: UserModel.fromJson(map['user'] as Map<String, dynamic>),
      workspace: map['workspace'] is Map<String, dynamic>
          ? WorkspaceModel.fromJson(map['workspace'] as Map<String, dynamic>)
          : null,
      workspaces: workspaces,
    );
  }

  Future<UserModel> updateProfile({
    required String name,
    String? email,
    String? phone,
    String? locale,
    String? timezone,
  }) async {
    final res = await _api.patch('/auth/profile', body: {
      'name': name,
      'email': ?email,
      'phone': ?phone,
      'locale': ?locale,
      'timezone': ?timezone,
    });
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحديث الملف الشخصي.');
    return UserModel.fromJson(map);
  }

  Future<void> changePassword({
    required String currentPassword,
    required String password,
    required String passwordConfirmation,
  }) async {
    final res = await _api.put('/auth/password', body: {
      'current_password': currentPassword,
      'password': password,
      'password_confirmation': passwordConfirmation,
    });
    if (!res.success) {
      throw ApiException(res.message ?? 'تعذر تغيير كلمة المرور.');
    }
  }

  Future<UserModel> uploadAvatar(File file) async {
    final form = FormData.fromMap({
      'avatar': await MultipartFile.fromFile(file.path, filename: file.uri.pathSegments.last),
    });
    final res = await _api.upload('/auth/avatar', formData: form);
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر رفع الصورة.');
    return UserModel.fromJson(map);
  }
}
