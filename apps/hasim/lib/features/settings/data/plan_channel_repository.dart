import 'package:hasim/core/local_db/settings_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/settings/data/agent_persona_model.dart';
import 'package:hasim/features/settings/data/ai_customer_facing_text.dart';
import 'package:hasim/features/settings/data/ai_employee_model.dart';
import 'package:hasim/features/settings/data/ai_setting_model.dart';
import 'package:hasim/features/settings/data/channel_connection.dart';

Map<String, dynamic> channelCachePayload(Map<String, dynamic> json) {
  final copy = Map<String, dynamic>.from(json);
  const banned = ['client_secret', 'app_secret', 'access_token', 'refresh_token', 'bot_token'];
  copy.removeWhere((key, _) => banned.contains(key.toLowerCase()));
  final url = copy['manage_url']?.toString().toLowerCase() ?? '';
  if (url.contains('token') || url.contains('secret') || url.contains('code=')) {
    copy.remove('manage_url');
  }
  return copy;
}

class PlanRepository {
  PlanRepository(
    this._api, {
    SettingsLocalStore? local,
    int? Function()? workspaceIdOf,
  }) : _local = local,
       _workspaceIdOf = workspaceIdOf;

  final ApiClient _api;
  final SettingsLocalStore? _local;
  final int? Function()? _workspaceIdOf;

  int? get _workspace => _workspaceIdOf?.call();

  Future<PlanSnapshot?> cachedCurrent() async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return null;
    final raw = await _local.get(workspaceId, 'plan');
    if (raw is! Map) return null;
    return PlanSnapshot.fromJson(Map<String, dynamic>.from(raw));
  }

  Future<PlansCatalog?> cachedCatalog() async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return null;
    final raw = await _local.get(workspaceId, 'plans');
    if (raw is! Map) return null;
    return PlansCatalog.fromJson(Map<String, dynamic>.from(raw));
  }

  Future<PlanSnapshot> current() async {
    final res = await _api.get('/plan');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر جلب الباقة الحالية.');
    final workspaceId = _workspace;
    if (workspaceId != null) await _local?.put(workspaceId, 'plan', map);
    return PlanSnapshot.fromJson(map);
  }

  Future<PlansCatalog> catalog() async {
    final res = await _api.get('/plans');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر جلب الباقات.');
    final workspaceId = _workspace;
    if (workspaceId != null) await _local?.put(workspaceId, 'plans', map);
    return PlansCatalog.fromJson(map);
  }
}

class ChannelRepository {
  ChannelRepository(
    this._api, {
    SettingsLocalStore? local,
    int? Function()? workspaceIdOf,
  }) : _local = local,
       _workspaceIdOf = workspaceIdOf;

  final ApiClient _api;
  final SettingsLocalStore? _local;
  final int? Function()? _workspaceIdOf;
  bool lastReadFromCache = false;

  int? get _workspace => _workspaceIdOf?.call();

  Future<List<ChannelModel>> cached() async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const [];
    final raw = await _local.get(workspaceId, 'channels');
    if (raw is! List) return const [];
    return asMapList(raw).map(ChannelModel.fromJson).toList();
  }

  Future<List<ChannelModel>> list() async {
    final workspaceId = _workspace;
    try {
      final res = await _api.get('/channels');
      final safe = [
        for (final item in asMapList(res.data)) channelCachePayload(item),
      ];
      lastReadFromCache = false;
      if (workspaceId != null) await _local?.put(workspaceId, 'channels', safe);
      return safe.map(ChannelModel.fromJson).toList();
    } catch (_) {
      final saved = await cached();
      if (saved.isNotEmpty) {
        lastReadFromCache = true;
        return saved;
      }
      rethrow;
    }
  }

  Future<ChannelConnectStart> startConnect(String channel, {String? botToken}) async {
    final res = await _api.post(
      '/channels/$channel/connect',
      body: {
        if (botToken != null && botToken.isNotEmpty) 'bot_token': botToken,
      },
    );
    final map = asMap(res.data);
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر بدء ربط القناة.');
    }
    _rejectSecrets(map);
    return ChannelConnectStart.fromJson(map);
  }

  Future<void> disconnect(String channel) async {
    await _api.post('/channels/$channel/disconnect');
  }

  Future<List<FacebookPageChoice>> facebookPages(String attempt) async {
    final res = await _api.get(
      '/channels/facebook_messenger/pages',
      query: {'attempt': attempt},
    );
    final map = asMap(res.data);
    final pages = map?['pages'];
    _rejectSecrets(map ?? {});
    return [
      for (final item in asMapList(pages)) FacebookPageChoice.fromJson(item),
    ];
  }

  Future<ChannelConnectStart> selectFacebookPage(String attempt, String pageId) async {
    final res = await _api.post(
      '/channels/facebook_messenger/pages',
      body: {'attempt': attempt, 'page_id': pageId},
    );
    final map = asMap(res.data);
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر اختيار صفحة Facebook.');
    }
    _rejectSecrets(map);
    return ChannelConnectStart.fromJson({
      'channel': 'facebook_messenger',
      'mode': 'oauth',
      ...map,
    });
  }

  void _rejectSecrets(Map<String, dynamic> payload) {
    final encoded = payload.toString().toLowerCase();
    const banned = [
      'client_secret',
      'app_secret',
      'access_token',
      'refresh_token',
      'bot_token',
    ];
    for (final key in banned) {
      if (payload.keys.any((k) => k.toLowerCase() == key) ||
          encoded.contains('"$key"')) {
        throw ApiException('رفض التطبيق عرض بيانات سرية للقناة.');
      }
    }
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
      providers: AiProviderOption.parse(meta['providers']),
    );
  }

  Future<AiSettingModel> updateSettings(Map<String, dynamic> body) async {
    final res = await _api.put('/ai/settings', body: body);
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر حفظ إعدادات الذكاء الاصطناعي.');
    return AiSettingModel.fromJson(map);
  }

  Future<AiProbeResult> probe({String message = 'مرحبا، عرفني بنفسك'}) async {
    final res = await _api.post('/ai/probe', body: {'message': message});
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر اختبار الذكاء الاصطناعي.');
    return AiProbeResult.fromJson(map);
  }

  Future<String> suggestReply(int conversationId, {String? content}) async {
    final res = await _api.post('/ai/suggest-reply', body: {
      'conversation_id': conversationId,
      if (content != null && content.isNotEmpty) 'content': content,
      'persist': false,
    });
    final suggestion = AiCustomerFacingText.parse(res.data, field: 'suggestion');
    if (suggestion == null || suggestion.isEmpty) {
      throw ApiException(res.message ?? 'تعذر توليد اقتراح الرد.');
    }
    return suggestion;
  }

  Future<AiEmployeesPayload> fetchEmployees() async {
    final res = await _api.get('/ai/employees');
    final meta = res.meta ?? {};
    final persona = meta['persona_options'] is Map ? Map<String, dynamic>.from(meta['persona_options'] as Map) : null;
    return AiEmployeesPayload(
      employees: _employeeList(res.data),
      options: AiEmployeeOptions.fromJson({
        ...?persona,
        'providers': meta['providers'] ?? const [],
      }),
    );
  }

  Future<List<AiEmployeePresetModel>> fetchEmployeePresets() async {
    final res = await _api.get('/ai/employee-presets');
    return [
      if (res.data is List)
        for (final item in res.data as List)
          if (asMap(item) != null) AiEmployeePresetModel.fromJson(asMap(item)!),
    ];
  }

  Future<List<AiSkillDefinition>> fetchSkills() async {
    final res = await _api.get('/ai/skills');
    return [
      for (final item in asMapList(res.data)) AiSkillDefinition.fromJson(item),
    ];
  }

  Future<AiEmployeeModel> setEmployeeSkills(int id, List<String> keys) {
    return updateEmployee(id, {
      'skills': [
        for (final key in keys) {'key': key, 'settings': <String, dynamic>{}},
      ],
    });
  }

  Future<AiEmployeeModel> createEmployee({String? presetKey, Map<String, dynamic>? fields}) async {
    final res = await _api.post('/ai/employees', body: {
      'preset_key': ?presetKey,
      ...?fields,
    });
    return _employee(res.data, res.message);
  }

  Future<AiEmployeeModel> updateEmployee(int id, Map<String, dynamic> changes) async {
    final res = await _api.patch('/ai/employees/$id', body: changes);
    return _employee(res.data, res.message);
  }

  Future<AiEmployeeModel> setDefaultEmployee(int id) async {
    final res = await _api.post('/ai/employees/$id/default');
    return _employee(res.data, res.message);
  }

  Future<AiEmployeeModel> setEmployeeActive(int id, {required bool active}) async {
    final res = await _api.post('/ai/employees/$id/${active ? 'activate' : 'deactivate'}');
    return _employee(res.data, res.message);
  }

  Future<void> deleteEmployee(int id) async {
    await _api.delete('/ai/employees/$id');
  }

  Future<AiEmployeeTestResult> testEmployee(int id, String message) async {
    final res = await _api.post('/ai/employees/$id/test', body: {'message': message});
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر اختبار الموظف.');
    return AiEmployeeTestResult.fromJson(map);
  }

  AiEmployeeModel _employee(dynamic data, String? message) {
    final map = asMap(data);
    if (map == null) throw ApiException(message ?? 'تعذر حفظ الموظف.');
    return AiEmployeeModel.fromJson(map);
  }

  List<AiEmployeeModel> _employeeList(dynamic data) => [
        if (data is List)
          for (final item in data)
            if (asMap(item) != null) AiEmployeeModel.fromJson(asMap(item)!),
      ];

  Future<String> summarize(int conversationId) async {
    final res = await _api.post('/ai/summarize-conversation', body: {
      'conversation_id': conversationId,
    });
    final summary = AiCustomerFacingText.parse(res.data, field: 'summary');
    if (summary == null || summary.isEmpty) {
      throw ApiException(res.message ?? 'تعذر تلخيص المحادثة.');
    }
    return summary;
  }
}
