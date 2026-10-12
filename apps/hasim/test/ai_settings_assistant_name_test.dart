import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/features/settings/data/ai_employee_model.dart';
import 'package:hasim/features/settings/data/ai_setting_model.dart';
import 'package:hasim/features/settings/presentation/ai_settings_screen.dart';
import 'package:hasim/features/settings/providers/ai_employees_controller.dart';
import 'package:hasim/features/settings/providers/ai_settings_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';

class _SeededAiSettingsController extends AiSettingsController {
  _SeededAiSettingsController(super.ref, this._initial);

  final AiSettingsState _initial;
  AiSettingModel? lastSaved;
  bool failSave = false;

  @override
  Future<void> reload() async {
    state = _initial;
  }

  @override
  Future<bool> save(AiSettingModel draft) async {
    lastSaved = draft;
    if (failSave) {
      state = state.copyWith(saving: false, error: 'تعذر حفظ الإعدادات.');
      return false;
    }
    state = AiSettingsState(
      settings: draft,
      catalog: state.catalog,
      aiRoles: state.aiRoles.isEmpty
          ? const ['sales_agent', 'general_business_assistant']
          : state.aiRoles,
    );
    return true;
  }
}

class _SeededAiEmployeesController extends AiEmployeesController {
  _SeededAiEmployeesController(super.ref);

  AiEmployeeDraft? lastSaved;

  @override
  Future<void> reload() async {
    state = AiEmployeesState(
      employees: [
        AiEmployeeModel.fromJson({
          'id': 7,
          'name': 'ساره',
          'role': 'sales_agent',
          'status': 'active',
          'is_default': true,
          'persona': {
            'language': 'ar',
            'dialect': 'saudi',
            'formality': 'casual_professional',
            'tone': 'friendly',
          },
          'model': {'response_length': 'short', 'temperature': 0.4},
        }),
      ],
      selectedId: 7,
    );
  }

  @override
  Future<bool> update(int id, AiEmployeeDraft draft) async {
    lastSaved = draft;
    state = state.copyWith(
      employees: [
        AiEmployeeModel.fromJson({
          'id': id,
          'name': draft.name,
          'role': draft.role,
          'status': 'active',
          'is_default': true,
          'instructions': draft.instructions,
          'persona': {
            'language': draft.language,
            'dialect': draft.dialect,
            'formality': draft.formality,
            'tone': draft.tone,
          },
          'model': {
            'response_length': draft.responseLength,
            'temperature': draft.temperature,
          },
        }),
      ],
      selectedId: id,
    );
    return true;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  AiSettingModel sample({String name = 'ساره'}) => AiSettingModel.fromJson({
    'id': 1,
    'name': name,
    'assistant_name': name,
    'ai_role': 'sales_agent',
    'is_active': true,
  });

  Future<void> pumpScreen(
    WidgetTester tester, {
    required AiSettingsState initial,
    required void Function(
      _SeededAiSettingsController settings,
      _SeededAiEmployeesController employees,
    )
    capture,
  }) async {
    tester.view.physicalSize = const Size(390, 2000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    late _SeededAiSettingsController settings;
    late _SeededAiEmployeesController employees;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          aiSettingsControllerProvider.overrideWith((ref) {
            settings = _SeededAiSettingsController(ref, initial);
            return settings;
          }),
          aiEmployeesControllerProvider.overrideWith((ref) {
            employees = _SeededAiEmployeesController(ref);
            return employees;
          }),
        ],
        child: MaterialApp(
          locale: const Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: const AiSettingsScreen(),
        ),
      ),
    );
    await tester.pumpAndSettle();
    capture(settings, employees);
  }

  testWidgets('loads and displays assistant name', (tester) async {
    await pumpScreen(
      tester,
      initial: AiSettingsState(
        settings: sample(),
        aiRoles: const ['sales_agent', 'general_business_assistant'],
      ),
      capture: (_, _) {},
    );

    expect(find.text('اسم الموظف'), findsWidgets);
    expect(find.text('ساره'), findsWidgets);
    expect(
      find.byKey(const ValueKey('ai-general-employee-heading')),
      findsOneWidget,
    );
  });

  testWidgets('edits and saves assistant name', (tester) async {
    late _SeededAiEmployeesController employees;
    await pumpScreen(
      tester,
      initial: AiSettingsState(
        settings: sample(),
        aiRoles: const ['sales_agent', 'general_business_assistant'],
      ),
      capture: (_, e) => employees = e,
    );

    await tester.enterText(
      find.byKey(const ValueKey('ai-general-employee-name')),
      'حسن',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'حفظ التغييرات'));
    await tester.pumpAndSettle();

    expect(employees.lastSaved?.name, 'حسن');
    expect(employees.lastSaved?.toJson().containsKey('provider'), isFalse);
    expect(find.text('تم حفظ الموظف بنجاح'), findsOneWidget);
  });

  testWidgets('shows validation error for markup names', (tester) async {
    await pumpScreen(
      tester,
      initial: AiSettingsState(
        settings: sample(),
        aiRoles: const ['sales_agent', 'general_business_assistant'],
      ),
      capture: (_, _) {},
    );

    await tester.enterText(
      find.byType(TextField).first,
      '<script>محمد</script>',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'حفظ التغييرات'));
    await tester.pump();

    expect(
      find.text(
        'اسم المساعد غير صالح. استخدم حروفًا ومسافات فقط دون رموز أو وسوم.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('shows the selected employee when workspace settings fail', (
    tester,
  ) async {
    await pumpScreen(
      tester,
      initial: const AiSettingsState(
        error: 'تعذر تحميل إعدادات الذكاء الاصطناعي.',
      ),
      capture: (_, _) {},
    );

    expect(find.text('ساره'), findsWidgets);
    expect(
      find.byKey(const ValueKey('ai-general-employee-heading')),
      findsOneWidget,
    );
  });

  testWidgets('shows Arabic labels instead of English enum keys', (
    tester,
  ) async {
    await pumpScreen(
      tester,
      initial: AiSettingsState(
        settings: sample(),
        aiRoles: const [
          'sales_agent',
          'customer_support',
          'general_business_assistant',
        ],
      ),
      capture: (_, _) {},
    );

    expect(find.text('مندوب مبيعات'), findsOneWidget);
    expect(find.text('sales_agent'), findsNothing);
    expect(find.text('customer_support'), findsNothing);

    await tester.tap(find.text('موظفو الذكاء الاصطناعي'));
    await tester.pumpAndSettle();

    expect(find.text('مندوب مبيعات · سعودية'), findsOneWidget);
    expect(find.text('saudi'), findsNothing);

    await tester.tap(find.widgetWithText(OutlinedButton, 'تعديل'));
    await tester.pumpAndSettle();

    expect(find.text('ودود'), findsWidgets);
    expect(find.text('عفوي مهني'), findsWidgets);
    expect(find.text('قصير'), findsWidgets);
    expect(find.text('friendly'), findsNothing);
    expect(find.text('casual_professional'), findsNothing);
    expect(find.text('short'), findsNothing);
  });

  testWidgets('lists Gemini and OpenAI and saves the selected provider', (
    tester,
  ) async {
    late _SeededAiEmployeesController employees;
    await pumpScreen(
      tester,
      initial: AiSettingsState(
        settings: sample(),
        aiRoles: const ['sales_agent', 'general_business_assistant'],
        providers: AiProviderOption.defaults,
      ),
      capture: (_, e) => employees = e,
    );

    expect(find.text('النموذج'), findsNothing);
    expect(find.text('المزود'), findsNothing);
    expect(find.text('Google AI Studio (Gemini)'), findsNothing);
    expect(find.text('gemini-2.5-flash'), findsNothing);

    await tester.tap(find.widgetWithText(FilledButton, 'حفظ التغييرات'));
    await tester.pumpAndSettle();

    expect(employees.lastSaved?.toJson()['model'], isNot(contains('provider')));
    expect(employees.lastSaved?.toJson()['model'], isNot(contains('model')));
  });
}
