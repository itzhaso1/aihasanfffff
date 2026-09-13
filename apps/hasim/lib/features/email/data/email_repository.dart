
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class EmailRepository {
  EmailRepository(this._api);
  final ApiClient _api;

  Future<({List<EmailMessageModel> items, String? nextCursor})> _list(String path, {String? cursor, String? search}) async {
    final res = await _api.get(path, query: {
      'cursor': ?cursor,
      if (search != null && search.isNotEmpty) 'search': search,
      'per_page': 20,
    });
    return (items: asMapList(res.data).map(EmailMessageModel.fromJson).toList(), nextCursor: res.nextCursor);
  }

  Future<({List<EmailMessageModel> items, String? nextCursor})> inbox({String? cursor, String? search}) =>
      _list('/emails/inbox', cursor: cursor, search: search);
  Future<({List<EmailMessageModel> items, String? nextCursor})> sent({String? cursor, String? search}) =>
      _list('/emails/sent', cursor: cursor, search: search);
  Future<({List<EmailMessageModel> items, String? nextCursor})> drafts({String? cursor, String? search}) =>
      _list('/emails/drafts', cursor: cursor, search: search);

  Future<EmailMessageModel> show(int id) async {
    final res = await _api.get('/emails/$id');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'الرسالة غير موجودة.');
    return EmailMessageModel.fromJson(map);
  }

  Future<EmailMessageModel> send({
    required int emailAccountId,
    required String to,
    required String subject,
    required String body,
    int? replyToMessageId,
  }) async {
    final res = await _api.post(
      '/emails',
      idempotent: true,
      body: {
        'email_account_id': emailAccountId,
        'to': to,
        'subject': subject,
        'body': body,
        'reply_to_message_id': ?replyToMessageId,
      },
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر إرسال البريد.');
    return EmailMessageModel.fromJson(map);
  }

  Future<List<EmailAccountModel>> accounts() async {
    final res = await _api.get('/emails/accounts');
    return asMapList(res.data).map(EmailAccountModel.fromJson).toList();
  }

  Future<void> markRead(int id) async => _api.post('/emails/$id/read');
  Future<void> star(int id) async => _api.post('/emails/$id/star');
}
