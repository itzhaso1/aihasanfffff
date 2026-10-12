import 'package:hasim/core/config/app_config.dart';

class ChannelOAuthCallback {
  const ChannelOAuthCallback({
    required this.channel,
    required this.status,
    this.message,
    this.attempt,
    this.workspaceId,
  });

  final String channel;
  final String status;
  final String? message;
  final String? attempt;
  final int? workspaceId;

  bool get isSuccess => status == 'success';
  bool get isError => status == 'error';
  bool get isSelectPage => status == 'select_page';
  bool get isCancelled => status == 'cancelled';

  static ChannelOAuthCallback? tryParse(Uri uri) {
    if (!AppConfig.isChannelOAuthCallback(uri)) {
      return null;
    }
    final channel = uri.queryParameters['channel']?.trim() ?? '';
    final status = uri.queryParameters['status']?.trim() ?? '';
    if (channel.isEmpty || status.isEmpty) {
      return null;
    }
    return ChannelOAuthCallback(
      channel: channel,
      status: status,
      message: uri.queryParameters['message'],
      attempt: uri.queryParameters['attempt'],
      workspaceId: int.tryParse(uri.queryParameters['workspace'] ?? ''),
    );
  }
}

class ChannelConnectStart {
  const ChannelConnectStart({
    required this.channel,
    required this.mode,
    this.authorizationUrl,
    this.callbackScheme,
    this.connected = false,
    this.displayName,
    this.status,
  });

  final String channel;
  final String mode;
  final String? authorizationUrl;
  final String? callbackScheme;
  final bool connected;
  final String? displayName;
  final String? status;

  bool get opensBrowser =>
      (authorizationUrl != null && authorizationUrl!.isNotEmpty) &&
      (mode == 'oauth' || mode == 'embedded_signup');

  factory ChannelConnectStart.fromJson(Map<String, dynamic> json) =>
      ChannelConnectStart(
        channel: json['channel']?.toString() ?? '',
        mode: json['mode']?.toString() ?? '',
        authorizationUrl: json['authorization_url']?.toString(),
        callbackScheme: json['callback_scheme']?.toString(),
        connected: json['connected'] == true,
        displayName: json['display_name']?.toString(),
        status: json['status']?.toString(),
      );
}

class FacebookPageChoice {
  const FacebookPageChoice({required this.id, required this.name});

  final String id;
  final String name;

  factory FacebookPageChoice.fromJson(Map<String, dynamic> json) =>
      FacebookPageChoice(
        id: json['id']?.toString() ?? '',
        name: json['name']?.toString() ?? 'Facebook Page',
      );
}
