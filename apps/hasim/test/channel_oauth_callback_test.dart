import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/settings/data/channel_connection.dart';

void main() {
  test('parses native channel flags without treating manage_url as connect', () {
    final channel = ChannelModel.fromJson({
      'key': 'whatsapp',
      'name': 'WhatsApp',
      'connected': false,
      'status': 'disconnected',
      'can_connect_in_app': true,
      'can_manage': true,
      'can_disconnect': false,
      'connection_mode': 'embedded_signup',
      'manage_url': 'https://example.test/workspace/channels',
    });

    expect(channel.canConnectInApp, isTrue);
    expect(channel.isNativeConnect, isTrue);
    expect(channel.manageUrl, contains('/workspace/channels'));
  });

  test('parses oauth callback and ignores unrelated links', () {
    final ok = ChannelOAuthCallback.tryParse(
      Uri.parse('hasim://channels/oauth?channel=instagram&status=success&workspace=9'),
    );
    expect(ok, isNotNull);
    expect(ok!.channel, 'instagram');
    expect(ok.isSuccess, isTrue);
    expect(ok.workspaceId, 9);

    expect(
      ChannelOAuthCallback.tryParse(Uri.parse('https://example.test/workspace/channels')),
      isNull,
    );
  });

  test('select_page callback keeps attempt and never requires a code', () {
    final parsed = ChannelOAuthCallback.tryParse(
      Uri.parse('hasim://channels/oauth?channel=facebook_messenger&status=select_page&attempt=abc'),
    );
    expect(parsed?.isSelectPage, isTrue);
    expect(parsed?.attempt, 'abc');
    expect(parsed?.message, isNull);
  });
}
