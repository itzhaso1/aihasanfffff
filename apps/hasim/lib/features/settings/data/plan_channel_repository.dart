import 'package:hasim/core/local_db/domain_local_store.dart';
import 'package:hasim/core/local_db/settings_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/settings/data/agent_persona_model.dart';
import 'package:hasim/features/settings/data/ai_customer_facing_text.dart';
import 'package:hasim/features/settings/data/ai_employee_model.dart';
import 'package:hasim/features/settings/data/ai_setting_model.dart';
import 'package:hasim/features/settings/data/channel_connection.dart';
import 'package:hasim/features/settings/data/company_profile_model.dart';

Map<String, dynamic> channelCachePayload(Map<String, dynamic> json) {
  final copy = Map<String, dynamic>.from(json);
  const banned = [
    'client_secret',
    'app_secret',
    'access_token',
    'refresh_token',
    'bot_token',
  ];
  copy.removeWhere((key, _) => banned.contains(key.toLowerCase()));
  final url = copy['manage_url']?.toString().toLowerCase() ?? '';
  if (url.contains('token') ||
      url.contains('secret') ||
      url.contains('code=')) {
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
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر جلب الباقة الحالية.');
    }
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

  static List<ChannelModel> get defaultSupportedChannels => const [
    ChannelModel(
      key: 'whatsapp',
      name: 'WhatsApp',
      icon: 'whatsapp',
      hint: 'اربط عبر Meta Embedded Signup لإدارة رسائل واتساب.',
      connectionMode: 'embedded_signup',
      canConnectInApp: true,
      canManage: true,
    ),
    ChannelModel(
      key: 'facebook_messenger',
      name: 'Facebook Messenger',
      icon: 'messenger',
      hint: 'اربط صفحة Facebook لاستقبال رسائل Messenger.',
      connectionMode: 'oauth',
      canConnectInApp: true,
      canManage: true,
    ),
    ChannelModel(
      key: 'instagram',
      name: 'Instagram',
      icon: 'instagram',
      hint: 'اربط عبر Meta Business Login للرد على رسائل إنستغرام.',
      connectionMode: 'oauth',
      canConnectInApp: true,
      canManage: true,
    ),
    ChannelModel(
      key: 'telegram',
      name: 'Telegram',
      icon: 'telegram',
      hint: 'أدخل رمز البوت من BotFather لربط تيليجرام.',
      connectionMode: 'bot_token',
      canConnectInApp: true,
      canManage: true,
    ),
    ChannelModel(
      key: 'tiktok',
      name: 'TikTok',
      icon: 'tiktok',
      hint: 'اربط عبر TikTok Business OAuth.',
      connectionMode: 'oauth',
      canConnectInApp: true,
      canManage: true,
    ),
    ChannelModel(
      key: 'email',
      name: 'Email',
      icon: 'email',
      hint: 'ربط وإدارة حساب البريد الإلكتروني.',
      connectionMode: 'web_only',
      canConnectInApp: false,
      canManage: true,
    ),
    ChannelModel(
      key: 'web',
      name: 'الدردشة المباشرة (ويب)',
      icon: 'web',
      connected: true,
      status: 'connected',
      hint: 'الدردشة المباشرة على موقع الويب نشطة ومتاحة.',
      connectionMode: 'web_only',
      canConnectInApp: false,
      canManage: true,
    ),
  ];

  Future<List<ChannelModel>> cached() async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const [];
    final raw = await _local.get(workspaceId, 'channels');
    if (raw is! List || raw.isEmpty) return const [];
    return asMapList(raw).map(ChannelModel.fromJson).toList();
  }

  Future<List<ChannelModel>> list() async {
    final workspaceId = _workspace;
    try {
      final res = await _api.get('/channels');
      final rawList = asMapList(res.data);
      if (rawList.isEmpty) {
        final saved = await cached();
        return saved.isNotEmpty ? saved : defaultSupportedChannels;
      }
      final safe = [for (final item in rawList) channelCachePayload(item)];
      lastReadFromCache = false;
      if (workspaceId != null) await _local?.put(workspaceId, 'channels', safe);
      return safe.map(ChannelModel.fromJson).toList();
    } catch (_) {
      final saved = await cached();
      if (saved.isNotEmpty) {
        lastReadFromCache = true;
        return saved;
      }
      return defaultSupportedChannels;
    }
  }

  Future<ChannelConnectStart> startConnect(
    String channel, {
    String? botToken,
  }) async {
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

  Future<ChannelConnectStart> selectFacebookPage(
    String attempt,
    String pageId,
  ) async {
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
  CustomerRepository(
    this._api, {
    DomainLocalStore? local,
    int? Function()? workspaceIdOf,
  }) : _local = local,
       _workspaceIdOf = workspaceIdOf;

  final ApiClient _api;
  final DomainLocalStore? _local;
  final int? Function()? _workspaceIdOf;
  bool lastReadFromCache = false;

  int? get _workspace => _workspaceIdOf?.call();

  Future<List<CustomerModel>> cachedCustomers({String? search}) async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const [];
    return _local.customers(workspaceId, search: search);
  }

  Stream<List<CustomerModel>> watchCustomers({String? search, String? status}) {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const Stream.empty();
    return _local.watchCustomers(workspaceId, search: search, status: status);
  }

  Future<List<CustomerModel>> list({
    String? search,
    String? cursor,
    int perPage = 20,
  }) async {
    final workspaceId = _workspace;
    try {
      final query = <String, dynamic>{'per_page': perPage};
      if (search != null && search.trim().isNotEmpty) {
        query['search'] = search.trim();
      }
      if (cursor != null && cursor.isNotEmpty) {
        query['cursor'] = cursor;
      }
      final res = await _api.get('/customers', query: query);
      final items = asMapList(res.data).map(CustomerModel.fromJson).toList();
      lastReadFromCache = false;
      if (workspaceId != null && items.isNotEmpty) {
        await _local?.upsertCustomers(workspaceId, items);
      }
      return items;
    } catch (_) {
      if (workspaceId != null && _local != null) {
        final cached = await _local.customers(workspaceId, search: search);
        if (cached.isNotEmpty) {
          lastReadFromCache = true;
          return cached;
        }
      }
      rethrow;
    }
  }

  Future<CustomerModel> show(int id) async {
    final workspaceId = _workspace;
    try {
      final res = await _api.get('/customers/$id');
      final map = asMap(res.data);
      if (map == null) throw ApiException(res.message ?? 'العميل غير موجود.');
      final customer = CustomerModel.fromJson(map);
      lastReadFromCache = false;
      if (workspaceId != null) {
        await _local?.upsertCustomers(workspaceId, [customer]);
      }
      return customer;
    } catch (_) {
      if (workspaceId != null && _local != null) {
        final cached = await _local.customer(workspaceId, id);
        if (cached != null) {
          lastReadFromCache = true;
          return cached;
        }
      }
      rethrow;
    }
  }

  Future<CustomerModel> create({
    required String name,
    String? phone,
    String? email,
    String? whatsapp,
    String? city,
    String? notes,
  }) async {
    final body = <String, dynamic>{
      'name': name.trim(),
      if (phone != null && phone.trim().isNotEmpty) 'phone': phone.trim(),
      if (email != null && email.trim().isNotEmpty) 'email': email.trim(),
      if (whatsapp != null && whatsapp.trim().isNotEmpty)
        'whatsapp': whatsapp.trim(),
      if (city != null && city.trim().isNotEmpty) 'city': city.trim(),
      if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
    };
    final res = await _api.post('/customers', body: body);
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر إنشاء العميل.');
    final customer = CustomerModel.fromJson(map);
    final workspaceId = _workspace;
    if (workspaceId != null) {
      await _local?.upsertCustomers(workspaceId, [customer]);
    }
    return customer;
  }

  Future<CustomerModel> update(
    int id, {
    String? name,
    String? phone,
    String? email,
    String? whatsapp,
    String? city,
  }) async {
    final body = <String, dynamic>{
      if (name != null) 'name': name.trim(),
      if (phone != null) 'phone': phone.trim(),
      if (email != null) 'email': email.trim(),
      if (whatsapp != null) 'whatsapp': whatsapp.trim(),
      if (city != null) 'city': city.trim(),
    };
    final res = await _api.patch('/customers/$id', body: body);
    final map = asMap(res.data);
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر تحديث بيانات العميل.');
    }
    final customer = CustomerModel.fromJson(map);
    final workspaceId = _workspace;
    if (workspaceId != null) {
      await _local?.upsertCustomers(workspaceId, [customer]);
    }
    return customer;
  }

  Future<void> addNote(int id, String note) async {
    await _api.post('/customers/$id/notes', body: {'body': note.trim()});
  }

  Future<List<ConversationModel>> conversations(int id) async {
    final res = await _api.get(
      '/customers/$id/conversations',
      query: {'per_page': 20},
    );
    return asMapList(res.data).map(ConversationModel.fromJson).toList();
  }

  Future<void> delete(int id) async {
    await _api.delete('/customers/$id');
  }
}

class AiRepository {
  AiRepository(this._api);
  final ApiClient _api;

  Future<AiSettingsPayload> fetchSettings() async {
    final res = await _api.get('/ai/settings');
    final map = asMap(res.data);
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر تحميل إعدادات الذكاء الاصطناعي.');
    }
    final meta = res.meta ?? {};
    return AiSettingsPayload(
      settings: AiSettingModel.fromJson(map),
      catalog: AgentPersonaCatalog.fromJson(
        meta['persona_catalog'] is Map
            ? Map<String, dynamic>.from(meta['persona_catalog'] as Map)
            : null,
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
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر حفظ إعدادات الذكاء الاصطناعي.');
    }
    return AiSettingModel.fromJson(map);
  }

  Future<CompanyProfileModel> fetchCompanyProfile() async {
    final res = await _api.get('/ai/company-profile');
    final map = asMap(res.data);
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر تحميل نبذة الشركة.');
    }
    return CompanyProfileModel.fromJson(map);
  }

  Future<CompanyProfileModel> updateCompanyProfile(
    Map<String, dynamic> body,
  ) async {
    final res = await _api.put('/ai/company-profile', body: body);
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر حفظ نبذة الشركة.');
    return CompanyProfileModel.fromJson(map);
  }

  Future<AiProbeResult> probe({String message = 'مرحبا، عرفني بنفسك'}) async {
    final res = await _api.post('/ai/probe', body: {'message': message});
    final map = asMap(res.data);
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر اختبار الذكاء الاصطناعي.');
    }
    return AiProbeResult.fromJson(map);
  }

  Future<String> suggestReply(int conversationId, {String? content}) async {
    final res = await _api.post(
      '/ai/suggest-reply',
      body: {
        'conversation_id': conversationId,
        if (content != null && content.isNotEmpty) 'content': content,
        'persist': false,
      },
    );
    final suggestion = AiCustomerFacingText.parse(
      res.data,
      field: 'suggestion',
    );
    if (suggestion == null || suggestion.isEmpty) {
      throw ApiException(res.message ?? 'تعذر توليد اقتراح الرد.');
    }
    return suggestion;
  }

  Future<AiEmployeesPayload> fetchEmployees() async {
    final res = await _api.get('/ai/employees');
    final meta = res.meta ?? {};
    final persona = meta['persona_options'] is Map
        ? Map<String, dynamic>.from(meta['persona_options'] as Map)
        : null;
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

  Future<AiEmployeeModel> createEmployee({
    String? presetKey,
    Map<String, dynamic>? fields,
  }) async {
    final res = await _api.post(
      '/ai/employees',
      body: {'preset_key': ?presetKey, ...?fields},
    );
    return _employee(res.data, res.message);
  }

  Future<AiEmployeeModel> updateEmployee(
    int id,
    Map<String, dynamic> changes,
  ) async {
    final res = await _api.patch('/ai/employees/$id', body: changes);
    return _employee(res.data, res.message);
  }

  Future<AiEmployeeModel> setDefaultEmployee(int id) async {
    final res = await _api.post('/ai/employees/$id/default');
    return _employee(res.data, res.message);
  }

  Future<AiEmployeeModel> setEmployeeActive(
    int id, {
    required bool active,
  }) async {
    final res = await _api.post(
      '/ai/employees/$id/${active ? 'activate' : 'deactivate'}',
    );
    return _employee(res.data, res.message);
  }

  Future<void> deleteEmployee(int id) async {
    await _api.delete('/ai/employees/$id');
  }

  Future<AiEmployeeTestResult> testEmployee(int id, String message) async {
    final res = await _api.post(
      '/ai/employees/$id/test',
      body: {'message': message},
    );
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
    final res = await _api.post(
      '/ai/summarize-conversation',
      body: {'conversation_id': conversationId},
    );
    final summary = AiCustomerFacingText.parse(res.data, field: 'summary');
    if (summary == null || summary.isEmpty) {
      throw ApiException(res.message ?? 'تعذر تلخيص المحادثة.');
    }
    return summary;
  }
}
