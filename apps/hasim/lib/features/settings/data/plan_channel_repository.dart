import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

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
