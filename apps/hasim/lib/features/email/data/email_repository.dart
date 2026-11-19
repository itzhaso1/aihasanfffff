import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/features/email/data/email_models.dart';

final emailRepositoryProvider = Provider<EmailRepository>((ref) {
  return EmailRepository(ref.watch(apiClientProvider));
});

class EmailRepository {
  const EmailRepository(this._api);

  final ApiClient _api;

  Future<List<EmailAccountModel>> accounts() async {
    final res = await _api.get('emails/accounts');
    final raw = res.data is List ? res.data as List : [];
    return raw
        .whereType<Map<String, dynamic>>()
        .map(EmailAccountModel.fromJson)
        .toList();
  }

  Future<List<EmailMessageModel>> listFolder(String folder) async {
    final endpoint = switch (folder) {
      'sent' => 'emails/sent',
      'drafts' => 'emails/drafts',
      _ => 'emails/inbox',
    };
    final res = await _api.get(endpoint);
    final raw = res.data is List ? res.data as List : [];
    return raw
        .whereType<Map<String, dynamic>>()
        .map(EmailMessageModel.fromJson)
        .toList();
  }

  Future<EmailMessageModel> show(int id) async {
    final res = await _api.get('emails/$id');
    final data = res.data is Map<String, dynamic> ? res.data as Map<String, dynamic> : <String, dynamic>{};
    return EmailMessageModel.fromJson(data);
  }

  Future<EmailMessageModel> send({
    required int emailAccountId,
    required String to,
    required String subject,
    required String body,
    int? replyToMessageId,
  }) async {
    final payload = <String, dynamic>{
      'email_account_id': emailAccountId,
      'to': to,
      'subject': subject,
      'body': body,
    };
    if (replyToMessageId != null) {
      payload['reply_to_message_id'] = replyToMessageId;
    }
    final res = await _api.post(
      'emails',
      body: payload,
    );
    final data = res.data is Map<String, dynamic> ? res.data as Map<String, dynamic> : <String, dynamic>{};
    return EmailMessageModel.fromJson(data);
  }

  Future<void> markRead(int id) async {
    await _api.post('emails/$id/read');
  }

  Future<void> star(int id) async {
    await _api.post('emails/$id/star');
  }
}
