import 'package:equatable/equatable.dart';

Map<String, dynamic> asStringMap(dynamic raw) {
  if (raw is Map<String, dynamic>) return Map<String, dynamic>.from(raw);
  if (raw is Map) return Map<String, dynamic>.from(raw);
  return <String, dynamic>{};
}

class ChatCatalogCommunicationProfile extends Equatable {
  const ChatCatalogCommunicationProfile({
    this.primaryLanguage,
    this.supportedLanguages = const [],
    this.arabicDialect,
    this.formality,
    this.communicationStyle,
    this.greetingBehavior,
    this.nameUsage,
    this.languageFallback,
    this.dateFormat,
    this.timeFormat,
    this.numberFormat,
    this.currencyFormat,
    this.units,
    this.responseLength,
    this.punctuationStyle,
    this.emojiPolicy,
  });

  final String? primaryLanguage;
  final List<String> supportedLanguages;
  final String? arabicDialect;
  final String? formality;
  final String? communicationStyle;
  final String? greetingBehavior;
  final String? nameUsage;
  final String? languageFallback;
  final String? dateFormat;
  final String? timeFormat;
  final String? numberFormat;
  final String? currencyFormat;
  final String? units;
  final String? responseLength;
  final String? punctuationStyle;
  final String? emojiPolicy;

  factory ChatCatalogCommunicationProfile.fromJson(Map<String, dynamic>? json) {
    if (json == null) return const ChatCatalogCommunicationProfile();
    return ChatCatalogCommunicationProfile(
      primaryLanguage: json['primary_language']?.toString(),
      supportedLanguages: json['supported_languages'] is List
          ? (json['supported_languages'] as List).map((e) => e.toString()).toList()
          : const [],
      arabicDialect: json['arabic_dialect']?.toString(),
      formality: json['formality']?.toString(),
      communicationStyle: json['communication_style']?.toString(),
      greetingBehavior: json['greeting_behavior']?.toString(),
      nameUsage: json['name_usage']?.toString(),
      languageFallback: json['language_fallback']?.toString(),
      dateFormat: json['date_format']?.toString(),
      timeFormat: json['time_format']?.toString(),
      numberFormat: json['number_format']?.toString(),
      currencyFormat: json['currency_format']?.toString(),
      units: json['units']?.toString(),
      responseLength: json['response_length']?.toString(),
      punctuationStyle: json['punctuation_style']?.toString(),
      emojiPolicy: json['emoji_policy']?.toString(),
    );
  }

  ChatCatalogCommunicationProfile copyWith({
    String? primaryLanguage,
    List<String>? supportedLanguages,
    String? arabicDialect,
    String? formality,
    String? communicationStyle,
    String? greetingBehavior,
    String? nameUsage,
    String? languageFallback,
    String? dateFormat,
    String? timeFormat,
    String? numberFormat,
    String? currencyFormat,
    String? units,
    String? responseLength,
    String? punctuationStyle,
    String? emojiPolicy,
  }) {
    return ChatCatalogCommunicationProfile(
      primaryLanguage: primaryLanguage ?? this.primaryLanguage,
      supportedLanguages: supportedLanguages ?? this.supportedLanguages,
      arabicDialect: arabicDialect ?? this.arabicDialect,
      formality: formality ?? this.formality,
      communicationStyle: communicationStyle ?? this.communicationStyle,
      greetingBehavior: greetingBehavior ?? this.greetingBehavior,
      nameUsage: nameUsage ?? this.nameUsage,
      languageFallback: languageFallback ?? this.languageFallback,
      dateFormat: dateFormat ?? this.dateFormat,
      timeFormat: timeFormat ?? this.timeFormat,
      numberFormat: numberFormat ?? this.numberFormat,
      currencyFormat: currencyFormat ?? this.currencyFormat,
      units: units ?? this.units,
      responseLength: responseLength ?? this.responseLength,
      punctuationStyle: punctuationStyle ?? this.punctuationStyle,
      emojiPolicy: emojiPolicy ?? this.emojiPolicy,
    );
  }

  Map<String, dynamic> toJson() => {
        if (primaryLanguage != null) 'primary_language': primaryLanguage,
        if (supportedLanguages.isNotEmpty) 'supported_languages': supportedLanguages,
        if (arabicDialect != null) 'arabic_dialect': arabicDialect,
        if (formality != null) 'formality': formality,
        if (communicationStyle != null) 'communication_style': communicationStyle,
        if (greetingBehavior != null) 'greeting_behavior': greetingBehavior,
        if (nameUsage != null) 'name_usage': nameUsage,
        if (languageFallback != null) 'language_fallback': languageFallback,
        if (dateFormat != null) 'date_format': dateFormat,
        if (timeFormat != null) 'time_format': timeFormat,
        if (numberFormat != null) 'number_format': numberFormat,
        if (currencyFormat != null) 'currency_format': currencyFormat,
        if (units != null) 'units': units,
        if (responseLength != null) 'response_length': responseLength,
        if (punctuationStyle != null) 'punctuation_style': punctuationStyle,
        if (emojiPolicy != null) 'emoji_policy': emojiPolicy,
      };

  @override
  List<Object?> get props => [primaryLanguage, arabicDialect, formality, emojiPolicy];
}

class ChatCatalogSettingsModel extends Equatable {
  const ChatCatalogSettingsModel({
    required this.id,
    required this.name,
    this.slug,
    this.publicName,
    this.shortDescription,
    this.status,
    this.defaultCurrency = 'SAR',
    this.defaultLanguage,
    this.timezone,
    this.rtlMode = true,
    this.availabilityMode,
    this.unavailableItemBehavior,
    this.sortPolicy,
    this.publicWebsite,
    this.displaySettings = const {},
    this.discoverySettings = const {},
    this.channelVisibility = const {},
    this.customerServicePolicy = const {},
    this.availabilitySchedule = const {},
    this.seo = const {},
    this.searchWeights = const {},
    this.communicationProfile = const ChatCatalogCommunicationProfile(),
  });

  final int id;
  final String name;
  final String? slug;
  final String? publicName;
  final String? shortDescription;
  final String? status;
  final String defaultCurrency;
  final String? defaultLanguage;
  final String? timezone;
  final bool rtlMode;
  final String? availabilityMode;
  final String? unavailableItemBehavior;
  final String? sortPolicy;
  final String? publicWebsite;
  final Map<String, dynamic> displaySettings;
  final Map<String, dynamic> discoverySettings;
  final Map<String, dynamic> channelVisibility;
  final Map<String, dynamic> customerServicePolicy;
  final Map<String, dynamic> availabilitySchedule;
  final Map<String, dynamic> seo;
  final Map<String, dynamic> searchWeights;
  final ChatCatalogCommunicationProfile communicationProfile;

  bool boolSetting(Map<String, dynamic> map, String key, {bool fallback = false}) {
    final v = map[key];
    if (v is bool) return v;
    if (v == null) return fallback;
    return v == true || v == 1 || v == '1';
  }

  String stringSetting(Map<String, dynamic> map, String key) => map[key]?.toString() ?? '';

  int intSetting(Map<String, dynamic> map, String key, {int fallback = 1}) {
    final v = map[key];
    if (v is num) return v.toInt();
    return int.tryParse(v?.toString() ?? '') ?? fallback;
  }

  factory ChatCatalogSettingsModel.fromJson(Map<String, dynamic> json) {
    final commRaw = json['communication_profile'];
    return ChatCatalogSettingsModel(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: json['name']?.toString() ?? '',
      slug: json['slug']?.toString(),
      publicName: json['public_name']?.toString(),
      shortDescription: json['short_description']?.toString(),
      status: json['status']?.toString(),
      defaultCurrency: json['default_currency']?.toString() ?? 'SAR',
      defaultLanguage: json['default_language']?.toString(),
      timezone: json['timezone']?.toString(),
      rtlMode: json['rtl_mode'] != false,
      availabilityMode: json['availability_mode']?.toString(),
      unavailableItemBehavior: json['unavailable_item_behavior']?.toString(),
      sortPolicy: json['sort_policy']?.toString(),
      publicWebsite: json['public_website']?.toString(),
      displaySettings: asStringMap(json['display_settings']),
      discoverySettings: asStringMap(json['discovery_settings']),
      channelVisibility: asStringMap(json['channel_visibility']),
      customerServicePolicy: asStringMap(json['customer_service_policy']),
      availabilitySchedule: asStringMap(json['availability_schedule']),
      seo: asStringMap(json['seo']),
      searchWeights: asStringMap(json['search_weights']),
      communicationProfile: commRaw is Map
          ? ChatCatalogCommunicationProfile.fromJson(asStringMap(commRaw))
          : const ChatCatalogCommunicationProfile(),
    );
  }

  ChatCatalogSettingsModel copyWith({
    String? name,
    String? publicName,
    String? shortDescription,
    String? status,
    String? defaultCurrency,
    String? defaultLanguage,
    String? timezone,
    bool? rtlMode,
    String? availabilityMode,
    String? unavailableItemBehavior,
    String? sortPolicy,
    String? publicWebsite,
    Map<String, dynamic>? displaySettings,
    Map<String, dynamic>? discoverySettings,
    Map<String, dynamic>? channelVisibility,
    Map<String, dynamic>? customerServicePolicy,
    Map<String, dynamic>? availabilitySchedule,
    Map<String, dynamic>? seo,
    Map<String, dynamic>? searchWeights,
    ChatCatalogCommunicationProfile? communicationProfile,
  }) {
    return ChatCatalogSettingsModel(
      id: id,
      name: name ?? this.name,
      slug: slug,
      publicName: publicName ?? this.publicName,
      shortDescription: shortDescription ?? this.shortDescription,
      status: status ?? this.status,
      defaultCurrency: defaultCurrency ?? this.defaultCurrency,
      defaultLanguage: defaultLanguage ?? this.defaultLanguage,
      timezone: timezone ?? this.timezone,
      rtlMode: rtlMode ?? this.rtlMode,
      availabilityMode: availabilityMode ?? this.availabilityMode,
      unavailableItemBehavior: unavailableItemBehavior ?? this.unavailableItemBehavior,
      sortPolicy: sortPolicy ?? this.sortPolicy,
      publicWebsite: publicWebsite ?? this.publicWebsite,
      displaySettings: displaySettings ?? this.displaySettings,
      discoverySettings: discoverySettings ?? this.discoverySettings,
      channelVisibility: channelVisibility ?? this.channelVisibility,
      customerServicePolicy: customerServicePolicy ?? this.customerServicePolicy,
      availabilitySchedule: availabilitySchedule ?? this.availabilitySchedule,
      seo: seo ?? this.seo,
      searchWeights: searchWeights ?? this.searchWeights,
      communicationProfile: communicationProfile ?? this.communicationProfile,
    );
  }

  Map<String, dynamic> toGeneralPatch() => {
        'name': name,
        if (publicName != null) 'public_name': publicName,
        if (shortDescription != null) 'short_description': shortDescription,
        'default_currency': defaultCurrency,
        if (defaultLanguage != null) 'default_language': defaultLanguage,
        if (timezone != null) 'timezone': timezone,
        'rtl_mode': rtlMode,
        if (availabilityMode != null) 'availability_mode': availabilityMode,
        if (unavailableItemBehavior != null) 'unavailable_item_behavior': unavailableItemBehavior,
        if (sortPolicy != null) 'sort_policy': sortPolicy,
        if (publicWebsite != null && publicWebsite!.isNotEmpty) 'public_website': publicWebsite,
        'display_settings': displaySettings,
        'discovery_settings': discoverySettings,
        'channel_visibility': channelVisibility,
        'customer_service_policy': customerServicePolicy,
        'availability_schedule': availabilitySchedule,
        'seo': seo,
        'search_weights': searchWeights,
      };

  @override
  List<Object?> get props => [id, name, status, defaultCurrency, availabilityMode];
}
