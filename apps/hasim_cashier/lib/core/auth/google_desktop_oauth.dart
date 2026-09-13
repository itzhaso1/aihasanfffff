import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api/cashier_api.dart';
import '../config/app_config.dart';

/// Installed-app Google OAuth (Windows/Linux) → access token for Laravel
/// `POST /api/cashier/v1/auth/social`.
class GoogleDesktopOauth {
  const GoogleDesktopOauth._();

  static Future<String> signIn() async {
    final clientId = AppConfig.googleOAuthClientId.trim();
    if (clientId.isEmpty) {
      throw ApiException('يحتاج إعداد Google');
    }

    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final redirectUri = 'http://127.0.0.1:${server.port}';
    final verifier = _randomToken();
    final challenge = _codeChallenge(verifier);
    final state = _randomToken();

    final authUri = Uri.https('accounts.google.com', '/o/oauth2/v2/auth', {
      'client_id': clientId,
      'redirect_uri': redirectUri,
      'response_type': 'code',
      'scope': 'email profile openid',
      'code_challenge': challenge,
      'code_challenge_method': 'S256',
      'state': state,
      'prompt': 'select_account',
    });

    final launched = await launchUrl(
      authUri,
      mode: LaunchMode.externalApplication,
    );
    if (!launched) {
      await server.close(force: true);
      throw ApiException('تعذر فتح متصفح Google.');
    }

    try {
      final request = await server.first.timeout(const Duration(minutes: 3));
      final params = request.uri.queryParameters;
      request.response
        ..statusCode = 200
        ..headers.contentType = ContentType.html
        ..write(
          '<html lang="ar" dir="rtl"><body style="font-family:sans-serif;padding:32px">'
          'يمكنك إغلاق هذه النافذة والعودة إلى كاشير حاسم.'
          '</body></html>',
        );
      await request.response.close();

      if ((params['error'] ?? '').isNotEmpty) {
        throw ApiException('تم إلغاء تسجيل الدخول عبر Google.');
      }
      if (params['state'] != state) {
        throw ApiException('فشل التحقق من جلسة Google.');
      }
      final code = (params['code'] ?? '').trim();
      if (code.isEmpty) {
        throw ApiException('لم يصل رمز Google.');
      }
      return _exchangeCode(
        clientId: clientId,
        code: code,
        redirectUri: redirectUri,
        verifier: verifier,
      );
    } on ApiException {
      rethrow;
    } on TimeoutException {
      throw ApiException('انتهت مهلة تسجيل الدخول عبر Google.');
    } catch (_) {
      throw ApiException('تعذر تسجيل الدخول عبر Google.');
    } finally {
      await server.close(force: true);
    }
  }

  static Future<String> _exchangeCode({
    required String clientId,
    required String code,
    required String redirectUri,
    required String verifier,
  }) async {
    final body = <String, String>{
      'code': code,
      'client_id': clientId,
      'redirect_uri': redirectUri,
      'grant_type': 'authorization_code',
      'code_verifier': verifier,
    };
    final secret = AppConfig.googleOAuthClientSecret.trim();
    if (secret.isNotEmpty) {
      body['client_secret'] = secret;
    }

    final client = HttpClient();
    try {
      final request = await client.postUrl(
        Uri.parse('https://oauth2.googleapis.com/token'),
      );
      request.headers.contentType = ContentType(
        'application',
        'x-www-form-urlencoded',
        charset: 'utf-8',
      );
      request.write(Uri(queryParameters: body).query);
      final response = await request.close();
      final raw = await utf8.decoder.bind(response).join();
      final decoded = jsonDecode(raw);
      if (decoded is! Map || decoded['access_token'] is! String) {
        throw ApiException('يحتاج إعداد Google');
      }
      final token = (decoded['access_token'] as String).trim();
      if (token.isEmpty) {
        throw ApiException('يحتاج إعداد Google');
      }
      return token;
    } finally {
      client.close(force: true);
    }
  }

  static String _randomToken() {
    const chars =
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
    final random = Random.secure();
    return List<String>.generate(
      64,
      (_) => chars[random.nextInt(chars.length)],
    ).join();
  }

  static String _codeChallenge(String verifier) {
    final digest = sha256.convert(utf8.encode(verifier));
    return base64Url.encode(digest.bytes).replaceAll('=', '');
  }
}
