import 'package:equatable/equatable.dart';

class AgentPersonaCatalog {
  const AgentPersonaCatalog({
    this.version = 1,
    this.traits = const [
      'friendly',
      'professional',
      'confident',
      'empathetic',
      'energetic',
      'calm',
      'concise',
      'helpful',
    ],
    this.languages = const ['auto', 'ar', 'en'],
    this.dialects = const ['auto', 'neutral', 'jordanian', 'saudi', 'gulf', 'egyptian'],
    this.formality = const ['casual', 'casual_professional', 'professional', 'formal'],
    this.warmth = const ['low', 'medium', 'high'],
    this.verbosity = const ['concise', 'balanced', 'detailed'],
    this.emojiUsage = const ['none', 'minimal', 'moderate'],
    this.greetingStyles = const ['neutral', 'friendly', 'warm'],
    this.salesStyles = const ['neutral', 'helpful_not_pushy', 'consultative'],
    this.supportStyles = const ['neutral', 'empathetic_and_direct', 'patient_and_detailed'],
    this.maxTraits = 8,
    this.maxBehavioralRules = 12,
    this.maxRuleLength = 200,
    this.maxIdentityNameLength = 64,
  });

  final int version;
  final List<String> traits;
  final List<String> languages;
  final List<String> dialects;
  final List<String> formality;
  final List<String> warmth;
  final List<String> verbosity;
  final List<String> emojiUsage;
  final List<String> greetingStyles;
  final List<String> salesStyles;
  final List<String> supportStyles;
  final int maxTraits;
  final int maxBehavioralRules;
  final int maxRuleLength;
  final int maxIdentityNameLength;

  factory AgentPersonaCatalog.fromJson(Map<String, dynamic>? json) {
    if (json == null || json.isEmpty) return const AgentPersonaCatalog();
    List<String> list(String key, List<String> fallback) {
      final raw = json[key];
      if (raw is List && raw.isNotEmpty) {
        return raw.map((e) => e.toString()).toList();
      }
      return fallback;
    }

    const fallback = AgentPersonaCatalog();
    return AgentPersonaCatalog(
      version: (json['version'] as num?)?.toInt() ?? 1,
      traits: list('traits', fallback.traits),
      languages: list('languages', fallback.languages),
      dialects: list('dialects', fallback.dialects),
      formality: list('formality', fallback.formality),
      warmth: list('warmth', fallback.warmth),
      verbosity: list('verbosity', fallback.verbosity),
      emojiUsage: list('emoji_usage', fallback.emojiUsage),
      greetingStyles: list('greeting_styles', fallback.greetingStyles),
      salesStyles: list('sales_styles', fallback.salesStyles),
      supportStyles: list('support_styles', fallback.supportStyles),
      maxTraits: (json['max_traits'] as num?)?.toInt() ?? 8,
      maxBehavioralRules: (json['max_behavioral_rules'] as num?)?.toInt() ?? 12,
      maxRuleLength: (json['max_rule_length'] as num?)?.toInt() ?? 200,
      maxIdentityNameLength: (json['max_identity_name_length'] as num?)?.toInt() ?? 64,
    );
  }
}

class AgentPersonaModel extends Equatable {
  const AgentPersonaModel({
    this.version = 1,
    this.identityName = '',
    this.identityRole = '',
    this.traits = const [],
    this.language = 'auto',
    this.dialect = 'auto',
    this.formality = 'professional',
    this.warmth = 'medium',
    this.verbosity = 'concise',
    this.emojiUsage = 'minimal',
    this.greetingStyle = 'friendly',
    this.salesStyle = 'helpful_not_pushy',
    this.supportStyle = 'empathetic_and_direct',
    this.behavioralRules = const [],
  });

  final int version;
  final String identityName;
  final String identityRole;
  final List<String> traits;
  final String language;
  final String dialect;
  final String formality;
  final String warmth;
  final String verbosity;
  final String emojiUsage;
  final String greetingStyle;
  final String salesStyle;
  final String supportStyle;
  final List<String> behavioralRules;

  factory AgentPersonaModel.fromJson(Map<String, dynamic>? json) {
    if (json == null || json.isEmpty) return const AgentPersonaModel();
    final identity = _asStringMap(json['identity']);
    final tone = _asStringMap(json['tone']);
    final style = _asStringMap(json['style']);
    return AgentPersonaModel(
      version: (json['version'] as num?)?.toInt() ?? 1,
      identityName: identity['name']?.toString() ?? '',
      identityRole: identity['role']?.toString() ?? '',
      traits: json['traits'] is List ? (json['traits'] as List).map((e) => e.toString()).toList() : const [],
      language: tone['language']?.toString() ?? 'auto',
      dialect: tone['dialect']?.toString() ?? 'auto',
      formality: tone['formality']?.toString() ?? 'professional',
      warmth: tone['warmth']?.toString() ?? 'medium',
      verbosity: tone['verbosity']?.toString() ?? 'concise',
      emojiUsage: tone['emoji_usage']?.toString() ?? 'minimal',
      greetingStyle: style['greeting_style']?.toString() ?? 'friendly',
      salesStyle: style['sales_style']?.toString() ?? 'helpful_not_pushy',
      supportStyle: style['support_style']?.toString() ?? 'empathetic_and_direct',
      behavioralRules: json['behavioral_rules'] is List
          ? (json['behavioral_rules'] as List).map((e) => e.toString()).toList()
          : const [],
    );
  }

  AgentPersonaModel copyWith({
    String? identityName,
    String? identityRole,
    List<String>? traits,
    String? language,
    String? dialect,
    String? formality,
    String? warmth,
    String? verbosity,
    String? emojiUsage,
    String? greetingStyle,
    String? salesStyle,
    String? supportStyle,
    List<String>? behavioralRules,
  }) {
    return AgentPersonaModel(
      version: version,
      identityName: identityName ?? this.identityName,
      identityRole: identityRole ?? this.identityRole,
      traits: traits ?? this.traits,
      language: language ?? this.language,
      dialect: dialect ?? this.dialect,
      formality: formality ?? this.formality,
      warmth: warmth ?? this.warmth,
      verbosity: verbosity ?? this.verbosity,
      emojiUsage: emojiUsage ?? this.emojiUsage,
      greetingStyle: greetingStyle ?? this.greetingStyle,
      salesStyle: salesStyle ?? this.salesStyle,
      supportStyle: supportStyle ?? this.supportStyle,
      behavioralRules: behavioralRules ?? this.behavioralRules,
    );
  }

  Map<String, dynamic> toJson() => {
        'version': version,
        'identity': {
          'name': identityName,
          'role': identityRole,
        },
        'traits': traits,
        'tone': {
          'language': language,
          'dialect': dialect,
          'formality': formality,
          'warmth': warmth,
          'verbosity': verbosity,
          'emoji_usage': emojiUsage,
        },
        'style': {
          'greeting_style': greetingStyle,
          'sales_style': salesStyle,
          'support_style': supportStyle,
        },
        'behavioral_rules': behavioralRules,
      };

  @override
  List<Object?> get props => [identityName, identityRole, language, formality, traits, behavioralRules];
}

Map<String, dynamic> _asStringMap(dynamic raw) {
  if (raw is Map<String, dynamic>) return raw;
  if (raw is Map) return Map<String, dynamic>.from(raw);
  return const {};
}
