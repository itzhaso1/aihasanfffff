import 'package:hasim/core/local_db/domain_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class CampaignRepository {
  CampaignRepository(
    this._api, {
    DomainLocalStore? local,
    int? Function()? workspaceIdOf,
  }) : _local = local,
       _workspaceIdOf = workspaceIdOf;

  final ApiClient _api;
  final DomainLocalStore? _local;
  final int? Function()? _workspaceIdOf;

  Future<void> _remember(EmailCampaignModel campaign) async {
    final workspaceId = _workspaceIdOf?.call();
    if (workspaceId == null) return;
    await _local?.upsertCampaign(workspaceId, campaign);
  }

  Future<EmailCampaignModel?> cached(int id) async {
    final workspaceId = _workspaceIdOf?.call();
    if (workspaceId == null || _local == null) return null;
    return _local.campaign(workspaceId, id);
  }

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
    final campaign = EmailCampaignModel.fromJson(map);
    await _remember(campaign);
    return campaign;
  }

  Future<EmailCampaignModel> show(int id) async {
    try {
      final res = await _api.get('/email/campaigns/$id');
      final map = asMap(res.data);
      if (map == null) throw ApiException(res.message ?? 'الحملة غير موجودة.');
      final campaign = EmailCampaignModel.fromJson(map);
      await _remember(campaign);
      return campaign;
    } catch (_) {
      final saved = await cached(id);
      if (saved != null) return saved;
      rethrow;
    }
  }

  Future<EmailCampaignModel> cancel(int id) async {
    final res = await _api.post('/email/campaigns/$id/cancel');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر إلغاء الحملة.');
    final campaign = EmailCampaignModel.fromJson(map);
    await _remember(campaign);
    return campaign;
  }
}
