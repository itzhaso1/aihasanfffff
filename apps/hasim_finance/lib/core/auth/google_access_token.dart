import 'package:flutter/foundation.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:hasim_finance/core/api/finance_api.dart';
import 'package:hasim_finance/core/auth/google_auth.dart';
import 'package:hasim_finance/core/config/app_config.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:url_launcher/url_launcher.dart';

/// Obtains a Google access token for Laravel Finance social login.
///
/// Mobile/Web: `google_sign_in` plugin (public client ids only).
/// Windows: Laravel browser ticket (`/auth/google/start` + `/status`).
/// The server verifies the token. Flutter never stores Google secrets.
class FinanceGoogleAccessTokenClient implements GoogleAccessTokenSource {
  FinanceGoogleAccessTokenClient(this._api);

  final FinanceApi _api;

  @override
  Future<String> obtainAccessToken() async {
    Object? pluginError;
    if (_pluginSupported) {
      try {
        return await _pluginSignIn();
      } on GoogleSignInCancelled {
        rethrow;
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
      if (e is GoogleSignInCancelled) rethrow;
      if (e is ApiException) rethrow;
      throw _mapPluginError(pluginError ?? e);
    }
  }

  bool get _pluginSupported {
    if (kIsWeb) return true;
    return defaultTargetPlatform == TargetPlatform.android ||
        defaultTargetPlatform == TargetPlatform.iOS;
  }

  bool get _isDesktop {
    if (kIsWeb) return false;
    return defaultTargetPlatform == TargetPlatform.windows ||
        defaultTargetPlatform == TargetPlatform.linux ||
        defaultTargetPlatform == TargetPlatform.macOS;
  }

  Future<String> _laravelBrowserSignIn() async {
    final started = await _api.googleStart();
    final ticket = started.ticket.trim();
    final authUrl = started.authUrl.trim();
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
      final status = await _api.googleStatus(ticket);
      if (status.status == 'pending') continue;
      if (status.status == 'failed') {
        throw ApiException(status.error ?? 'فشل تسجيل الدخول عبر Google.');
      }
      if (status.status == 'ready') {
        final token = status.accessToken?.trim() ?? '';
        if (token.isEmpty) {
          throw ApiException('لم يرجع Google رمز الدخول.');
        }
        return token;
      }
      throw ApiException(status.error ?? 'انتهت جلسة Google. أعد المحاولة.');
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
      throw GoogleSignInCancelled();
    }
    final auth = await account.authentication;
    final token = (auth.accessToken ?? auth.idToken)?.trim() ?? '';
    if (token.isEmpty) {
      throw ApiException('يحتاج إعداد Google');
    }
    return token;
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
