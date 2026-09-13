import 'package:flutter/foundation.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api/cashier_api.dart';
import '../config/app_config.dart';
import 'google_desktop_oauth.dart'
    if (dart.library.html) 'google_desktop_oauth_unsupported.dart';

/// Obtains a Google access token for Laravel `POST /auth/social`.
///
/// Desktop POS uses Laravel `.env` Google OAuth (browser + ticket poll).
/// Mobile still tries the Google plugin first.
class GoogleAccessTokenClient {
  GoogleAccessTokenClient(this._api);

  final CashierApiClient _api;

  Future<String> obtainAccessToken() async {
    Object? pluginError;
    if (_pluginSupported) {
      try {
        return await _pluginSignIn();
      } catch (e) {
        pluginError = e;
        if (!_isDesktop) {
          try {
            return await _laravelBrowserSignIn();
          } catch (_) {
            throw _mapPluginError(pluginError);
          }
        }
      }
    }
    try {
      return await _laravelBrowserSignIn();
    } catch (e) {
      if (e is ApiException && _looksLikeMissingGoogle(e.message)) {
        try {
          return await GoogleDesktopOauth.signIn();
        } catch (_) {
          throw e;
        }
      }
      if (e is ApiException) rethrow;
      throw _mapPluginError(pluginError ?? e);
    }
  }

  bool get _pluginSupported {
    if (kIsWeb) return true;
    return defaultTargetPlatform == TargetPlatform.android ||
        defaultTargetPlatform == TargetPlatform.iOS ||
        defaultTargetPlatform == TargetPlatform.macOS;
  }

  bool get _isDesktop {
    if (kIsWeb) return false;
    return defaultTargetPlatform == TargetPlatform.windows ||
        defaultTargetPlatform == TargetPlatform.linux ||
        defaultTargetPlatform == TargetPlatform.macOS;
  }

  Future<String> _laravelBrowserSignIn() async {
    final started = await _api.post('/auth/google/start');
    final ticket = '${started['ticket'] ?? ''}'.trim();
    final authUrl = '${started['auth_url'] ?? ''}'.trim();
    if (ticket.isEmpty || authUrl.isEmpty) {
      throw ApiException('يحتاج إعداد Google');
    }
    final uri = Uri.tryParse(authUrl);
    if (uri == null) {
      throw ApiException('تعذر فتح متصفح Google.');
    }
    var launched = false;
    try {
      launched = await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {
      launched = false;
    }
    if (!launched) {
      try {
        launched = await launchUrl(uri, mode: LaunchMode.platformDefault);
      } catch (_) {
        launched = false;
      }
    }
    if (!launched) {
      throw ApiException('تعذر فتح متصفح Google.');
    }

    final deadline = DateTime.now().add(const Duration(minutes: 3));
    while (DateTime.now().isBefore(deadline)) {
      await Future<void>.delayed(const Duration(seconds: 2));
      final data = await _api.get(
        '/auth/google/status',
        query: {'ticket': ticket},
      );
      final status = '${data['status'] ?? ''}'.trim();
      if (status == 'pending') continue;
      if (status == 'failed') {
        throw ApiException(
          '${data['error'] ?? 'فشل تسجيل الدخول عبر Google.'}',
        );
      }
      if (status == 'ready') {
        final token = '${data['access_token'] ?? ''}'.trim();
        if (token.isEmpty) {
          throw ApiException('لم يرجع Google رمز الدخول.');
        }
        return token;
      }
      throw ApiException('${data['error'] ?? 'انتهت جلسة Google. أعد المحاولة.'}');
    }
    throw ApiException('انتهت مهلة تسجيل الدخول عبر Google.');
  }

  Future<String> _pluginSignIn() async {
    final clientId = AppConfig.googleOAuthClientId.trim();
    final serverClientId = AppConfig.googleOAuthServerClientId.trim();
    final google = GoogleSignIn(
      scopes: const ['email', 'profile'],
      clientId: clientId.isEmpty ? null : clientId,
      serverClientId: serverClientId.isEmpty ? null : serverClientId,
    );
    final account = await google.signIn();
    if (account == null) {
      throw ApiException('تم إلغاء تسجيل الدخول عبر Google.');
    }
    final auth = await account.authentication;
    final token = (auth.accessToken ?? auth.idToken)?.trim() ?? '';
    if (token.isEmpty) {
      throw ApiException('يحتاج إعداد Google');
    }
    return token;
  }

  bool _looksLikeMissingGoogle(String message) {
    final msg = message.toLowerCase();
    return msg.contains('google') &&
        (msg.contains('env') ||
            msg.contains('.env') ||
            msg.contains('إعداد') ||
            msg.contains('client'));
  }

  ApiException _mapPluginError(Object error) {
    if (error is ApiException) return error;
    final msg = error.toString().toLowerCase();
    final needsSetup = msg.contains('client') ||
        msg.contains('platform') ||
        msg.contains('missing') ||
        msg.contains('not been configured') ||
        msg.contains('sign_in_failed') ||
        msg.contains('missingpluginexception') ||
        msg.contains('10:') ||
        msg.contains('12500');
    return ApiException(
      needsSetup ? 'يحتاج إعداد Google' : 'تعذر تسجيل الدخول عبر Google.',
    );
  }
}
