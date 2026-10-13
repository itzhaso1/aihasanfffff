import 'package:equatable/equatable.dart';
import 'package:hasim/features/settings/data/agent_persona_model.dart';
import 'package:hasim/features/settings/data/ai_customer_facing_text.dart';

class AiSettingModel extends Equatable {
  const AiSettingModel({
    required this.id,
    required this.name,
    this.aiRole,
    this.instructions,
    this.tone,
    this.replyStyle,
    this.rules = const {},
    this.businessInformation = const {},
    this.agentPersona = const AgentPersonaModel(),
    this.provider = '',
    this.model = '',
    this.maxTokens = 512,
    this.temperature = 0.3,
    this.isActive = true,
  });

  final int id;
  final String name;
  final String? aiRole;
  final String? instructions;
  final String? tone;
  final String? replyStyle;
  final Map<String, dynamic> rules;
  final Map<String, dynamic> businessInformation;
  final AgentPersonaModel agentPersona;
  final String provider;
  final String model;
  final int maxTokens;
  final double temperature;
  final bool isActive;

  static const int maxAssistantNameLength = 64;

  factory AiSettingModel.fromJson(Map<String, dynamic> json) {
    final rawName = json['assistant_name'] ?? json['name'];
    return AiSettingModel(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: rawName?.toString() ?? '',
      aiRole: json['ai_role']?.toString(),
      instructions: json['instructions']?.toString(),
      tone: json['tone']?.toString(),
      replyStyle: json['reply_style']?.toString(),
      rules: json['rules'] is Map ? Map<String, dynamic>.from(json['rules'] as Map) : const {},
      businessInformation: json['business_information'] is Map
          ? Map<String, dynamic>.from(json['business_information'] as Map)
          : const {},
      agentPersona: AgentPersonaModel.fromJson(
        json['agent_persona'] is Map ? Map<String, dynamic>.from(json['agent_persona'] as Map) : null,
      ),
      provider: json['provider']?.toString() ?? '',
      model: json['model']?.toString() ?? '',
      maxTokens: (json['max_tokens'] as num?)?.toInt() ?? 512,
      temperature: (json['temperature'] as num?)?.toDouble() ?? 0.3,
      isActive: json['is_active'] != false,
    );
  }

  String get assistantName => name;

  static bool isValidAssistantName(String raw) => assistantNameError(raw) == null;

  static String? assistantNameError(String raw) {
    final value = raw.trim();
    if (value.isEmpty) return null;
    if (value.length > maxAssistantNameLength) return 'length';
    if (value.contains(RegExp(r'[<>]'))) return 'markup';
    if (RegExp(r'[\x00-\x1F\x7F]').hasMatch(value)) return 'control';
    if (!RegExp(r"^[\p{L}\p{M}\p{N}][\p{L}\p{M}\p{N} .'\-]{0,63}$", unicode: true).hasMatch(value)) {
      return 'charset';
    }
    return null;
  }

  Map<String, dynamic> toUpdateJson() => {
        'name': name,
        'assistant_name': name,
        if (aiRole != null) 'ai_role': aiRole,
        if (instructions != null) 'instructions': instructions,
        if (tone != null) 'tone': tone,
        if (replyStyle != null) 'reply_style': replyStyle,
        'rules': rules,
        'business_information': businessInformation,
        if (agentPersona.identityName.trim().isNotEmpty && agentPersona.identityRole.trim().isNotEmpty)
          'agent_persona': agentPersona.toJson(),
        'max_tokens': maxTokens,
        'temperature': temperature,
        'is_active': isActive,
      };

  String get experience => businessInformation['experience']?.toString() ?? '';

  bool get memoryEnabled => rules['memory_enabled'] != false;

  String get replyLength {
    final style = replyStyle ?? '';
    if (style == 'short' || style == 'medium' || style == 'long') return style;
    if (maxTokens <= 320) return 'short';
    if (maxTokens >= 800) return 'long';
    return 'medium';
  }

  AiSettingModel copyWith({
    String? name,
    String? aiRole,
    String? instructions,
    String? tone,
    String? replyStyle,
    bool? isActive,
    int? maxTokens,
    double? temperature,
    String? provider,
    String? model,
    AgentPersonaModel? agentPersona,
    Map<String, dynamic>? rules,
    Map<String, dynamic>? businessInformation,
  }) {
    return AiSettingModel(
      id: id,
      name: name ?? this.name,
      aiRole: aiRole ?? this.aiRole,
      instructions: instructions ?? this.instructions,
      tone: tone ?? this.tone,
      replyStyle: replyStyle ?? this.replyStyle,
      rules: rules ?? this.rules,
      businessInformation: businessInformation ?? this.businessInformation,
      agentPersona: agentPersona ?? this.agentPersona,
      provider: provider ?? this.provider,
      model: model ?? this.model,
      maxTokens: maxTokens ?? this.maxTokens,
      temperature: temperature ?? this.temperature,
      isActive: isActive ?? this.isActive,
    );
  }

  @override
  List<Object?> get props => [id, name, isActive, aiRole, agentPersona];
}

class AiSettingsPayload {
  const AiSettingsPayload({
    required this.settings,
    this.catalog = const AgentPersonaCatalog(),
    this.aiRoles = const [
      'sales_agent',
      'customer_support',
      'booking_agent',
      'receptionist',
      'product_advisor',
      'general_business_assistant',
    ],
    this.providers = AiProviderOption.defaults,
  });

  final AiSettingModel settings;
  final AgentPersonaCatalog catalog;
  final List<String> aiRoles;
  final List<AiProviderOption> providers;
}

class AiProbeCheck {
  const AiProbeCheck({
    required this.id,
    required this.label,
    required this.ok,
    this.warning = false,
    this.detail = '',
    this.elapsedMs = 0,
  });

  final String id;
  final String label;
  final bool ok;
  final bool warning;
  final String detail;
  final int elapsedMs;

  factory AiProbeCheck.fromJson(Map<String, dynamic> json) {
    return AiProbeCheck(
      id: json['id']?.toString() ?? '',
      label: json['label']?.toString() ?? '',
      ok: json['ok'] == true,
      warning: json['warning'] == true,
      detail: json['detail']?.toString() ?? '',
      elapsedMs: (json['elapsed_ms'] as num?)?.toInt() ?? 0,
    );
  }
}

class AiProbeResult {
  const AiProbeResult({
    required this.connected,
    required this.reply,
    required this.model,
    required this.elapsedMs,
    this.tokens,
    this.checks = const [],
    this.error,
  });

  final bool connected;
  final String reply;
  final String model;
  final int elapsedMs;
  final int? tokens;
  final List<AiProbeCheck> checks;
  final String? error;

  bool get ready => connected && checks.every((check) => check.ok);

  factory AiProbeResult.fromJson(Map<String, dynamic> json) {
    final rawChecks = json['checks'];
    return AiProbeResult(
      connected: json['connected'] == true,
      reply: AiCustomerFacingText.parse(json['reply'], field: 'reply') ??
          AiCustomerFacingText.parse(json, field: 'reply') ??
          '',
      model: json['model']?.toString() ?? '',
      elapsedMs: (json['elapsed_ms'] as num?)?.toInt() ?? 0,
      tokens: (json['tokens'] as num?)?.toInt(),
      error: json['error']?.toString(),
      checks: rawChecks is List
          ? rawChecks.whereType<Map>().map((e) => AiProbeCheck.fromJson(Map<String, dynamic>.from(e))).toList()
          : const [],
    );
  }
}

class AiProviderOption {
  const AiProviderOption({
    required this.id,
    required this.label,
    this.models = const [],
  });

  final String id;
  final String label;
  final List<String> models;

  static const defaults = <AiProviderOption>[
    AiProviderOption(
      id: 'google_ai_studio',
      label: 'Google AI Studio (Gemini)',
      models: [
        'gemini-2.5-flash',
        'gemini-2.5-flash-lite',
        'gemini-2.0-flash',
        'gemini-3-flash-preview',
        'gemini-3.1-flash-lite',
        'gemini-3.5-flash-lite',
        'gemini-3.6-flash',
        'gemini-3.7-flash',
        'gemini-3.8-flash',
      ],
    ),
    AiProviderOption(
      id: 'openai',
      label: 'OpenAI',
      models: ['gpt-4o-mini'],
    ),
  ];

  static List<AiProviderOption> parse(dynamic raw) {
    if (raw is! List) return defaults;
    final parsed = <AiProviderOption>[];
    for (final item in raw) {
      if (item is! Map) continue;
      final map = Map<String, dynamic>.from(item);
      final id = map['id']?.toString() ?? '';
      if (id.isEmpty) continue;
      final models = map['models'] is List
          ? (map['models'] as List).map((e) => e.toString()).where((e) => e.isNotEmpty).toList()
          : const <String>[];
      parsed.add(AiProviderOption(
        id: id,
        label: map['label']?.toString() ?? id,
        models: models,
      ));
    }
    return parsed.isEmpty ? defaults : parsed;
  }
}
