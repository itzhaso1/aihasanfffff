import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/settings/data/agent_persona_model.dart';
import 'package:hasim/features/settings/data/ai_setting_model.dart';

class PlanRepository {
  PlanRepository(this._api);
  final ApiClient _api;

  Future<PlanSnapshot> current() async {
    final res = await _api.get('/plan');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر جلب الباقة الحالية.');
    return PlanSnapshot.fromJson(map);
  }

  Future<PlansCatalog> catalog() async {
    final res = await _api.get('/plans');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر جلب الباقات.');
    return PlansCatalog.fromJson(map);
  }
}

class ChannelRepository {
  ChannelRepository(this._api);
  final ApiClient _api;

  Future<List<ChannelModel>> list() async {
    final res = await _api.get('/channels');
    return asMapList(res.data).map(ChannelModel.fromJson).toList();
  }
}

class BrandingRepository {
  BrandingRepository(this._api);
  final ApiClient _api;

  Future<Map<String, dynamic>> fetch() async {
    final res = await _api.get('/branding');
    return asMap(res.data) ?? {};
  }
}

class CustomerRepository {
  CustomerRepository(this._api);
  final ApiClient _api;

  Future<CustomerModel> show(int id) async {
    final res = await _api.get('/customers/$id');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'العميل غير موجود.');
    return CustomerModel.fromJson(map);
  }

  Future<List<ConversationModel>> conversations(int id) async {
    final res = await _api.get('/customers/$id/conversations', query: {'per_page': 20});
    return asMapList(res.data).map(ConversationModel.fromJson).toList();
  }
}

class AiRepository {
  AiRepository(this._api);
  final ApiClient _api;

  Future<AiSettingsPayload> fetchSettings() async {
    final res = await _api.get('/ai/settings');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحميل إعدادات الذكاء الاصطناعي.');
    final meta = res.meta ?? {};
    return AiSettingsPayload(
      settings: AiSettingModel.fromJson(map),
      catalog: AgentPersonaCatalog.fromJson(
        meta['persona_catalog'] is Map ? Map<String, dynamic>.from(meta['persona_catalog'] as Map) : null,
      ),
      aiRoles: meta['ai_roles'] is List
          ? (meta['ai_roles'] as List).map((e) => e.toString()).toList()
          : const [
              'sales_agent',
              'customer_support',
              'booking_agent',
              'receptionist',
              'product_advisor',
              'general_business_assistant',
            ],
    );
  }

  Future<AiSettingModel> updateSettings(Map<String, dynamic> body) async {
    final res = await _api.put('/ai/settings', body: body);
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر حفظ إعدادات الذكاء الاصطناعي.');
    return AiSettingModel.fromJson(map);
  }

  Future<String> suggestReply(int conversationId, {String? content}) async {
    final res = await _api.post('/ai/suggest-reply', body: {
      'conversation_id': conversationId,
      if (content != null && content.isNotEmpty) 'content': content,
      'persist': false,
    });
    final map = asMap(res.data);
    final suggestion = map?['suggestion']?.toString();
    if (suggestion == null || suggestion.isEmpty) {
      throw ApiException(res.message ?? 'تعذر توليد اقتراح الرد.');
    }
    return suggestion;
  }

  Future<String> summarize(int conversationId) async {
    final res = await _api.post('/ai/summarize-conversation', body: {
      'conversation_id': conversationId,
    });
    final map = asMap(res.data);
    final summary = map?['summary']?.toString();
    if (summary == null || summary.isEmpty) {
      throw ApiException(res.message ?? 'تعذر تلخيص المحادثة.');
    }
    return summary;
  }
}
