import 'package:hasim/features/settings/data/ai_customer_facing_text.dart';
import 'package:hasim/features/settings/data/ai_setting_model.dart';

class AiSkillDefinition {
  const AiSkillDefinition({
    required this.key,
    required this.name,
    this.description,
    this.category,
  });

  final String key;
  final String name;
  final String? description;
  final String? category;

  factory AiSkillDefinition.fromJson(Map<String, dynamic> json) => AiSkillDefinition(
    key: json['key']?.toString() ?? '',
    name: json['name']?.toString() ?? '',
    description: json['description']?.toString(),
    category: json['category']?.toString(),
  );
}

/// AI employee as returned by `/ai/employees`. Identity is [id]; [name] is display data.
class AiEmployeeModel {
  const AiEmployeeModel({
    required this.id,
    required this.name,
    required this.role,
    this.jobTitle,
    this.avatarKey,
    this.status = 'active',
    this.isDefault = false,
    this.instructions,
    this.experienceNotes,
    this.presetKey,
    this.configVersion = 1,
    this.persona = const AiEmployeePersona(),
    this.skills = const [],
    this.provider,
    this.model,
    this.modelSource = 'default',
    this.modelIsOverride = false,
    this.responseLength = 'short',
    this.temperature = 0.4,
  });

  final int id;
  final String name;
  final String role;
  final String? jobTitle;
  final String? avatarKey;
  final String status;
  final bool isDefault;
  final String? instructions;
  final String? experienceNotes;
  final String? presetKey;
  final int configVersion;
  final AiEmployeePersona persona;
  final List<String> skills;
  final String? provider;
  final String? model;
  final String modelSource;
  final bool modelIsOverride;
  final String responseLength;
  final double temperature;

  bool get isActive => status == 'active';

  String get effectiveModelLine {
    final value = model ?? '';
    if (value.isEmpty) return '';
    return value;
  }

  static const maxNameLength = 64;

  factory AiEmployeeModel.fromJson(Map<String, dynamic> json) {
    final model = json['model'] is Map ? Map<String, dynamic>.from(json['model'] as Map) : const <String, dynamic>{};
    return AiEmployeeModel(
      id: (json['id'] as num).toInt(),
      name: json['name']?.toString() ?? '',
      role: json['role']?.toString() ?? '',
      jobTitle: _text(json['job_title']),
      avatarKey: _text(json['avatar_key']),
      status: json['status']?.toString() ?? 'active',
      isDefault: json['is_default'] == true,
      instructions: _text(json['instructions']),
      experienceNotes: _text(json['experience_notes']),
      presetKey: _text(json['preset_key']),
      configVersion: (json['config_version'] as num?)?.toInt() ?? 1,
      persona: AiEmployeePersona.fromJson(
        json['persona'] is Map ? Map<String, dynamic>.from(json['persona'] as Map) : null,
      ),
      skills: [
        if (json['skills'] is List)
          for (final skill in json['skills'] as List)
            if (skill is Map && skill['key'] != null) skill['key'].toString(),
      ],
      provider: _text(model['provider']),
      model: _text(model['model']),
      modelSource: model['source']?.toString() ?? (model['is_override'] == true ? 'employee' : 'default'),
      modelIsOverride: model['is_override'] == true,
      responseLength: model['response_length']?.toString() ?? 'short',
      temperature: (model['temperature'] as num?)?.toDouble() ?? 0.4,
    );
  }
}

class AiEmployeePersona {
  const AiEmployeePersona({
    this.language = 'ar',
    this.dialect,
    this.formality = 'casual_professional',
    this.tone = 'friendly',
    this.emojiPolicy = 'minimal',
    this.greetingPolicy = 'introduce_first_contact',
    this.traits = const [],
    this.styleNotes,
  });

  final String language;
  final String? dialect;
  final String formality;
  final String tone;
  final String emojiPolicy;
  final String greetingPolicy;
  final List<String> traits;
  final String? styleNotes;

  factory AiEmployeePersona.fromJson(Map<String, dynamic>? json) {
    if (json == null) return const AiEmployeePersona();
    return AiEmployeePersona(
      language: json['language']?.toString() ?? 'ar',
      dialect: _text(json['dialect']),
      formality: json['formality']?.toString() ?? 'casual_professional',
      tone: json['tone']?.toString() ?? 'friendly',
      emojiPolicy: json['emoji_policy']?.toString() ?? 'minimal',
      greetingPolicy: json['greeting_policy']?.toString() ?? 'introduce_first_contact',
      traits: json['traits'] is List ? (json['traits'] as List).map((e) => e.toString()).toList() : const [],
      styleNotes: _text(json['style_notes']),
    );
  }
}

/// Editable employee fields, sent as a create or PATCH body.
class AiEmployeeDraft {
  const AiEmployeeDraft({
    required this.name,
    required this.role,
    this.jobTitle = '',
    this.instructions = '',
    this.experienceNotes = '',
    this.language = 'ar',
    this.dialect = 'auto',
    this.formality = 'casual_professional',
    this.tone = 'friendly',
    this.emojiPolicy = 'minimal',
    this.traits = const [],
    this.provider = '',
    this.model = '',
    this.responseLength = 'short',
    this.temperature = 0.4,
  });

  final String name;
  final String role;
  final String jobTitle;
  final String instructions;
  final String experienceNotes;
  final String language;
  final String dialect;
  final String formality;
  final String tone;
  final String emojiPolicy;
  final List<String> traits;
  final String provider;
  final String model;
  final String responseLength;
  final double temperature;

  AiEmployeeDraft copyWith({
    String? name,
    String? role,
    String? jobTitle,
    String? instructions,
    String? experienceNotes,
    String? language,
    String? dialect,
    String? formality,
    String? tone,
    String? emojiPolicy,
    List<String>? traits,
    String? responseLength,
    double? temperature,
  }) =>
      AiEmployeeDraft(
        name: name ?? this.name,
        role: role ?? this.role,
        jobTitle: jobTitle ?? this.jobTitle,
        instructions: instructions ?? this.instructions,
        experienceNotes: experienceNotes ?? this.experienceNotes,
        language: language ?? this.language,
        dialect: dialect ?? this.dialect,
        formality: formality ?? this.formality,
        tone: tone ?? this.tone,
        emojiPolicy: emojiPolicy ?? this.emojiPolicy,
        traits: traits ?? this.traits,
        responseLength: responseLength ?? this.responseLength,
        temperature: temperature ?? this.temperature,
      );

  factory AiEmployeeDraft.fromEmployee(AiEmployeeModel employee) => AiEmployeeDraft(
        name: employee.name,
        role: employee.role,
        jobTitle: employee.jobTitle ?? '',
        instructions: employee.instructions ?? '',
        experienceNotes: employee.experienceNotes ?? '',
        language: employee.persona.language,
        dialect: employee.persona.dialect ?? 'auto',
        formality: employee.persona.formality,
        tone: employee.persona.tone,
        emojiPolicy: employee.persona.emojiPolicy,
        traits: employee.persona.traits,
        provider: employee.provider ?? '',
        model: employee.model ?? '',
        responseLength: employee.responseLength,
        temperature: employee.temperature,
      );

  Map<String, dynamic> toJson() => {
        'name': name.trim(),
        'role': role,
        'job_title': jobTitle.trim(),
        'instructions': instructions.trim(),
        'experience_notes': experienceNotes.trim(),
        'persona': {
          'language': language,
          'dialect': dialect,
          'formality': formality,
          'tone': tone,
          'emoji_policy': emojiPolicy,
          'traits': traits,
        },
        'model': {
          'response_length': responseLength,
          'temperature': temperature,
        },
      };
}

/// Starter template from `/ai/employee-presets`. Never an employee itself.
class AiEmployeePresetModel {
  const AiEmployeePresetModel({
    required this.key,
    required this.version,
    required this.displayName,
    this.avatarKey,
    this.summary,
    this.role,
    this.dialect,
  });

  final String key;
  final int version;
  final String displayName;
  final String? avatarKey;
  final String? summary;
  final String? role;
  final String? dialect;

  factory AiEmployeePresetModel.fromJson(Map<String, dynamic> json) {
    final persona = json['persona'] is Map ? Map<String, dynamic>.from(json['persona'] as Map) : const <String, dynamic>{};
    return AiEmployeePresetModel(
      key: json['key']?.toString() ?? '',
      version: (json['version'] as num?)?.toInt() ?? 1,
      displayName: json['display_name']?.toString() ?? '',
      avatarKey: _text(json['avatar_key']),
      summary: _text(json['summary']),
      role: _text(json['role']),
      dialect: _text(persona['dialect']),
    );
  }
}

/// Allowed persona values from `meta.persona_options`.
class AiEmployeeOptions {
  const AiEmployeeOptions({
    this.languages = const ['auto', 'ar', 'en'],
    this.dialects = const ['auto', 'saudi', 'jordanian', 'neutral'],
    this.formality = const ['casual', 'casual_professional', 'professional', 'formal'],
    this.tones = const ['warm', 'friendly', 'neutral', 'calm', 'energetic'],
    this.emojiPolicies = const ['none', 'minimal', 'moderate'],
    this.traits = const ['friendly', 'professional', 'confident', 'empathetic', 'energetic', 'calm', 'concise', 'helpful'],
    this.responseLengths = const ['short', 'medium', 'long'],
    this.providers = const [],
    this.maxTraits = 8,
  });

  final List<String> languages;
  final List<String> dialects;
  final List<String> formality;
  final List<String> tones;
  final List<String> emojiPolicies;
  final List<String> traits;
  final List<String> responseLengths;
  final List<AiProviderOption> providers;
  final int maxTraits;

  factory AiEmployeeOptions.fromJson(Map<String, dynamic>? json) {
    if (json == null) return const AiEmployeeOptions();
    const fallback = AiEmployeeOptions();
    List<String> list(String key, List<String> defaults) =>
        json[key] is List ? (json[key] as List).map((e) => e.toString()).toList() : defaults;
    return AiEmployeeOptions(
      languages: list('languages', fallback.languages),
      dialects: list('dialects', fallback.dialects),
      formality: list('formality', fallback.formality),
      tones: list('tones', fallback.tones),
      emojiPolicies: list('emoji_policies', fallback.emojiPolicies),
      traits: list('traits', fallback.traits),
      responseLengths: list('response_lengths', fallback.responseLengths),
      providers: AiProviderOption.parse(json['providers']),
    );
  }

  List<String> get allowedModels {
    final models = <String>[];
    for (final provider in providers) {
      for (final model in provider.models) {
        if (!models.contains(model)) models.add(model);
      }
    }
    return models;
  }

  String? providerIdForModel(String modelId) {
    for (final provider in providers) {
      if (provider.models.contains(modelId)) return provider.id;
    }
    return null;
  }
}

class AiEmployeesPayload {
  const AiEmployeesPayload({required this.employees, this.options = const AiEmployeeOptions()});

  final List<AiEmployeeModel> employees;
  final AiEmployeeOptions options;
}

/// Result of a preview run: never sent to a customer.
class AiEmployeeTestResult {
  const AiEmployeeTestResult({
    required this.status,
    this.outcome = 'failed',
    this.text,
    this.provider,
    this.model,
    this.usedModel,
    this.settingsSource,
    this.elapsedMs = 0,
    this.tokens,
    this.error,
    this.checks = const [],
  });

  final String status;
  final String outcome;
  final String? text;
  final String? provider;
  final String? model;
  final String? usedModel;
  final String? settingsSource;
  final int elapsedMs;
  final int? tokens;
  final String? error;
  final List<AiProbeCheck> checks;

  bool get replied => (text ?? '').isNotEmpty && outcome != 'failed';

  bool get failed => outcome == 'failed';

  /// Canonical preview line from the backend result only.
  String? get usedModelLine {
    if ((usedModel ?? '').isNotEmpty) return usedModel;
    return model;
  }

  factory AiEmployeeTestResult.fromJson(Map<String, dynamic> json) {
    final rawChecks = json['checks'];
    return AiEmployeeTestResult(
      status: json['status']?.toString() ?? 'failed',
      outcome: json['outcome']?.toString() ?? (json['status']?.toString() == 'replied' ? 'success' : 'failed'),
      text: AiCustomerFacingText.parse(json['text'], field: 'text') ??
          AiCustomerFacingText.parse(json['reply'], field: 'reply') ??
          AiCustomerFacingText.parse(json, field: 'text'),
      provider: _text(json['provider']),
      model: _text(json['model']),
      usedModel: _text(json['used_model']),
      settingsSource: _text(json['settings_source']),
      elapsedMs: (json['elapsed_ms'] as num?)?.toInt() ?? 0,
      tokens: (json['tokens'] as num?)?.toInt(),
      error: _text(json['error']),
      checks: rawChecks is List
          ? rawChecks.whereType<Map>().map((e) => AiProbeCheck.fromJson(Map<String, dynamic>.from(e))).toList()
          : const [],
    );
  }
}

String? _text(Object? value) {
  final text = value?.toString().trim() ?? '';
  return text.isEmpty ? null : text;
}
