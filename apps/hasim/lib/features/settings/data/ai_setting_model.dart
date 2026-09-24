import 'package:equatable/equatable.dart';
import 'package:hasim/features/settings/data/agent_persona_model.dart';

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
    this.provider = 'google_ai_studio',
    this.model = 'gemini-2.5-flash',
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

  factory AiSettingModel.fromJson(Map<String, dynamic> json) {
    return AiSettingModel(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: json['name']?.toString() ?? '',
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
      provider: json['provider']?.toString() ?? 'google_ai_studio',
      model: json['model']?.toString() ?? 'gemini-2.5-flash',
      maxTokens: (json['max_tokens'] as num?)?.toInt() ?? 512,
      temperature: (json['temperature'] as num?)?.toDouble() ?? 0.3,
      isActive: json['is_active'] != false,
    );
  }

  Map<String, dynamic> toUpdateJson() => {
        'name': name,
        if (aiRole != null) 'ai_role': aiRole,
        if (instructions != null) 'instructions': instructions,
        if (tone != null) 'tone': tone,
        if (replyStyle != null) 'reply_style': replyStyle,
        'rules': rules,
        'business_information': businessInformation,
        if (agentPersona.identityName.trim().isNotEmpty && agentPersona.identityRole.trim().isNotEmpty)
          'agent_persona': agentPersona.toJson(),
        'provider': provider,
        'model': model,
        'max_tokens': maxTokens,
        'temperature': temperature,
        'is_active': isActive,
      };

  AiSettingModel copyWith({
    String? name,
    String? aiRole,
    String? instructions,
    String? tone,
    String? replyStyle,
    bool? isActive,
    int? maxTokens,
    double? temperature,
    AgentPersonaModel? agentPersona,
  }) {
    return AiSettingModel(
      id: id,
      name: name ?? this.name,
      aiRole: aiRole ?? this.aiRole,
      instructions: instructions ?? this.instructions,
      tone: tone ?? this.tone,
      replyStyle: replyStyle ?? this.replyStyle,
      rules: rules,
      businessInformation: businessInformation,
      agentPersona: agentPersona ?? this.agentPersona,
      provider: provider,
      model: model,
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
  });

  final AiSettingModel settings;
  final AgentPersonaCatalog catalog;
  final List<String> aiRoles;
}
