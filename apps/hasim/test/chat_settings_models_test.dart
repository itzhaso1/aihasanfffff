import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/catalog/data/chat_catalog_settings_model.dart';
import 'package:hasim/features/settings/data/agent_persona_model.dart';
import 'package:hasim/features/settings/data/ai_setting_model.dart';

void main() {
  test('ChatCatalogSettingsModel parses advanced mobile catalog settings', () {
    final model = ChatCatalogSettingsModel.fromJson({
      'id': 3,
      'name': 'كتالوج',
      'status': 'published',
      'default_currency': 'JOD',
      'rtl_mode': true,
      'availability_mode': 'scheduled',
      'display_settings': {'show_sku': true},
      'channel_visibility': {'whatsapp': true, 'web': false},
      'seo': {'title': 'SEO', 'robots': 'noindex'},
      'search_weights': {'sku': 8},
      'customer_service_policy': {'inquiry_mode': 'enabled'},
      'availability_schedule': {'enabled': true},
      'communication_profile': {
        'primary_language': 'ar',
        'arabic_dialect': 'jordanian',
        'formality': 'friendly',
      },
    });

    expect(model.id, 3);
    expect(model.defaultCurrency, 'JOD');
    expect(model.availabilityMode, 'scheduled');
    expect(model.seo['title'], 'SEO');
    expect(model.searchWeights['sku'], 8);
    expect(model.toGeneralPatch()['seo']['title'], 'SEO');
    expect(model.communicationProfile.arabicDialect, 'jordanian');
  });

  test('AgentPersonaModel round-trips Laravel schema', () {
    final persona = AgentPersonaModel.fromJson({
      'version': 1,
      'identity': {'name': 'ليلى', 'role': 'Sales assistant'},
      'traits': ['helpful'],
      'tone': {
        'language': 'ar',
        'dialect': 'jordanian',
        'formality': 'professional',
        'warmth': 'medium',
        'verbosity': 'concise',
        'emoji_usage': 'minimal',
      },
      'style': {
        'greeting_style': 'friendly',
        'sales_style': 'helpful_not_pushy',
        'support_style': 'empathetic_and_direct',
      },
      'behavioral_rules': ['Never invent prices.'],
    });

    expect(persona.identityName, 'ليلى');
    expect(persona.toJson()['tone']['dialect'], 'jordanian');
  });

  test('AiSettingModel includes persona on update when identity is complete', () {
    final model = AiSettingModel.fromJson({
      'id': 1,
      'name': 'Assistant',
      'ai_role': 'sales_agent',
      'is_active': true,
      'agent_persona': {
        'version': 1,
        'identity': {'name': 'ليلى', 'role': 'Sales assistant'},
        'traits': ['helpful'],
        'tone': {
          'language': 'ar',
          'dialect': 'jordanian',
          'formality': 'professional',
          'warmth': 'medium',
          'verbosity': 'concise',
          'emoji_usage': 'minimal',
        },
        'style': {
          'greeting_style': 'friendly',
          'sales_style': 'helpful_not_pushy',
          'support_style': 'empathetic_and_direct',
        },
        'behavioral_rules': [],
      },
    });

    expect(model.toUpdateJson()['agent_persona']['identity']['name'], 'ليلى');
    expect(model.copyWith(name: 'Updated').toUpdateJson()['name'], 'Updated');
  });

  test('AuthState isolationKey changes on logout', () {
    const loggedOut = AuthState(bootstrapping: false);
    const loggedIn = AuthState(bootstrapping: false, token: 't');
    expect(loggedOut.isAuthenticated, isFalse);
    expect(loggedIn.isAuthenticated, isTrue);
    expect(loggedOut.isolationKey, isNot(loggedIn.isolationKey));
  });
}
