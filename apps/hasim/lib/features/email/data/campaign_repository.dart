import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class CampaignRepository {
  CampaignRepository(this._api);
  final ApiClient _api;

  Future<EmailCampaignModel> create({
    required int emailAccountId,
    required String subject,
    required String body,
    List<int>? contactIds,
    List<int>? groupIds,
    List<String>? emails,
    bool allContacts = false,
    bool confirmAll = false,
  }) async {
    final res = await _api.post(
      '/email/campaigns',
      idempotent: true,
      body: {
        'email_account_id': emailAccountId,
        'subject': subject,
        'body': body,
        if (contactIds != null && contactIds.isNotEmpty) 'contact_ids': contactIds,
        if (groupIds != null && groupIds.isNotEmpty) 'group_ids': groupIds,
        if (emails != null && emails.isNotEmpty) 'emails': emails,
        if (allContacts) 'all_contacts': true,
        if (confirmAll) 'confirm_all': true,
      },
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر جدولة الحملة.');
    return EmailCampaignModel.fromJson(map);
  }

  Future<EmailCampaignModel> show(int id) async {
    final res = await _api.get('/email/campaigns/$id');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'الحملة غير موجودة.');
    return EmailCampaignModel.fromJson(map);
  }

  Future<EmailCampaignModel> cancel(int id) async {
    final res = await _api.post('/email/campaigns/$id/cancel');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر إلغاء الحملة.');
    return EmailCampaignModel.fromJson(map);
  }
}
