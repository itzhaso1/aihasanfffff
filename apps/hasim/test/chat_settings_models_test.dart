import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/settings/data/agent_persona_model.dart';
import 'package:hasim/features/settings/data/ai_choice_labels.dart';
import 'package:hasim/features/settings/data/ai_setting_model.dart';

void main() {
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

  test('AiSettingModel prefers assistant_name and serializes both keys', () {
    final model = AiSettingModel.fromJson({
      'id': 4,
      'name': 'AI Assistant',
      'assistant_name': 'ساره',
      'is_active': true,
    });

    expect(model.assistantName, 'ساره');
    expect(model.toUpdateJson()['name'], 'ساره');
    expect(model.toUpdateJson()['assistant_name'], 'ساره');
    expect(model.toUpdateJson().containsKey('provider'), isFalse);
    expect(model.toUpdateJson().containsKey('model'), isFalse);
    expect(AiSettingModel.isValidAssistantName('حسن'), isTrue);
    expect(AiSettingModel.isValidAssistantName('هشام'), isTrue);
    expect(AiSettingModel.isValidAssistantName(''), isTrue);
    expect(AiSettingModel.isValidAssistantName('<script>'), isFalse);
    expect(AiSettingModel.isValidAssistantName('محمد@zatca'), isFalse);
    expect(AiSettingModel.assistantNameError('x' * 65), 'length');
  });

  test('AI choice labels are Arabic by default', () {
    expect(aiChoiceLabelForLocale('sales_agent', 'ar'), 'مندوب مبيعات');
    expect(aiChoiceLabelForLocale('general_business_assistant', 'ar'), 'مساعد أعمال عام');
    expect(aiChoiceLabelForLocale('helpful_not_pushy', 'ar'), 'متعاون دون إلحاح');
    expect(aiChoiceLabelForLocale('empathetic_and_direct', 'ar'), 'متعاطف ومباشر');
    expect(aiChoiceLabelForLocale('friendly', 'ar'), 'ودود');
    expect(aiChoiceLabelForLocale('auto', 'ar'), 'تلقائي');
    expect(aiChoiceLabelForLocale('sales_agent', 'en'), 'Sales agent');
  });

  test('AuthState isolationKey changes on logout', () {
    const loggedOut = AuthState(bootstrapping: false);
    const loggedIn = AuthState(bootstrapping: false, token: 't');
    expect(loggedOut.isAuthenticated, isFalse);
    expect(loggedIn.isAuthenticated, isTrue);
    expect(loggedOut.isolationKey, isNot(loggedIn.isolationKey));
  });
}
