import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/features/settings/data/ai_employee_model.dart';
import 'package:hasim/features/settings/data/ai_setting_model.dart';
import 'package:hasim/features/settings/presentation/ai_settings_screen.dart';
import 'package:hasim/features/settings/presentation/ai_test_screen.dart';
import 'package:hasim/features/settings/providers/ai_employees_controller.dart';
import 'package:hasim/features/settings/providers/ai_settings_controller.dart';
import 'package:hasim/features/settings/providers/company_worker_discovery_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';

AiEmployeeModel _employee(
  int id,
  String name, {
  bool isDefault = false,
  String status = 'active',
  String? avatarKey,
  String? instructions,
  String provider = 'google_ai_studio',
  String model = 'gemini-2.5-flash',
  bool modelIsOverride = true,
}) => AiEmployeeModel.fromJson({
  'id': id,
  'name': name,
  'role': 'sales_agent',
  'status': status,
  'is_default': isDefault,
  'avatar_key': avatarKey,
  'instructions': instructions,
  'persona': {'language': 'ar', 'dialect': 'saudi'},
  'model': {
    'provider': provider,
    'model': model,
    'response_length': 'short',
    'temperature': 0.4,
    'source': modelIsOverride ? 'employee' : 'default',
    'is_override': modelIsOverride,
  },
});

class _QuietDiscoveryController extends CompanyWorkerDiscoveryController {
  _QuietDiscoveryController(super.ref);

  @override
  Future<void> reload() async {}
}

class _SettingsController extends AiSettingsController {
  _SettingsController(super.ref);

  @override
  Future<void> reload() async {
    state = AiSettingsState(
      settings: AiSettingModel.fromJson({
        'id': 1,
        'name': 'Legacy',
        'ai_role': 'sales_agent',
        'is_active': true,
      }),
      aiRoles: const ['sales_agent', 'customer_support'],
    );
  }
}

class _FakeEmployeesController extends AiEmployeesController {
  _FakeEmployeesController(super.ref, this.seed);

  final AiEmployeesState seed;
  final calls = <String>[];

  @override
  Future<void> reload() async => state = seed;

  List<AiEmployeeModel> _replace(AiEmployeeModel next) => [
    for (final e in state.employees) e.id == next.id ? next : e,
  ];

  @override
  Future<bool> createFromPreset(String presetKey, {String? name}) async {
    calls.add('preset:$presetKey');
    final created = _employee(99, 'من القالب', avatarKey: presetKey);
    state = state.copyWith(
      employees: [...state.employees, created],
      selectedId: created.id,
    );
    return true;
  }

  @override
  Future<bool> rename(int id, String name) async {
    calls.add('rename:$id:$name');
    final current = state.employees.firstWhere((e) => e.id == id);
    state = state.copyWith(
      employees: _replace(_copy(current, name: name)),
      selectedId: id,
    );
    return true;
  }

  @override
  Future<bool> update(int id, AiEmployeeDraft draft) async {
    calls.add('update:$id:${draft.name}');
    final current = state.employees.firstWhere((e) => e.id == id);
    state = state.copyWith(
      employees: _replace(
        _copy(current, name: draft.name, instructions: draft.instructions),
      ),
      selectedId: id,
    );
    return true;
  }

  @override
  Future<AiEmployeeTestResult?> test(int id, String message) async {
    calls.add('test:$id');
    final employee = state.employees.firstWhere((e) => e.id == id);
    return AiEmployeeTestResult.fromJson({
      'status': 'replied',
      'outcome': 'success',
      'text': 'أهلا',
      'provider': employee.provider,
      'model': employee.model,
      'used_model': employee.model,
      'settings_source': employee.modelIsOverride ? 'employee' : 'default',
      'elapsed_ms': 12,
    });
  }

  @override
  Future<bool> setDefault(int id) async {
    calls.add('default:$id');
    state = state.copyWith(
      employees: [
        for (final e in state.employees) _copy(e, isDefault: e.id == id),
      ],
    );
    return true;
  }

  AiEmployeeModel _copy(
    AiEmployeeModel employee, {
    String? name,
    String? instructions,
    bool? isDefault,
    String? provider,
    String? model,
  }) => _employee(
    employee.id,
    name ?? employee.name,
    isDefault: isDefault ?? employee.isDefault,
    status: employee.status,
    avatarKey: employee.avatarKey,
    instructions: instructions ?? employee.instructions,
    provider: provider ?? employee.provider ?? 'google_ai_studio',
    model: model ?? employee.model ?? 'gemini-2.5-flash',
    modelIsOverride:
        employee.modelIsOverride || provider != null || model != null,
  );

  @override
  Future<bool> setActive(int id, {required bool active}) async {
    calls.add('${active ? 'activate' : 'deactivate'}:$id');
    return true;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  Future<_FakeEmployeesController> pump(
    WidgetTester tester,
    AiEmployeesState seed,
  ) async {
    tester.view.physicalSize = const Size(390, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    late _FakeEmployeesController controller;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          aiSettingsControllerProvider.overrideWith(
            (ref) => _SettingsController(ref),
          ),
          aiEmployeesControllerProvider.overrideWith((ref) {
            controller = _FakeEmployeesController(ref, seed);
            return controller;
          }),
          companyWorkerDiscoveryControllerProvider.overrideWith(
            _QuietDiscoveryController.new,
          ),
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
    await tester.tap(find.text('موظفو الذكاء الاصطناعي'));
    await tester.pumpAndSettle();
    return controller;
  }

  Future<_FakeEmployeesController> pumpSettings(
    WidgetTester tester,
    AiEmployeesState seed,
  ) async {
    tester.view.physicalSize = const Size(390, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    late _FakeEmployeesController controller;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          aiSettingsControllerProvider.overrideWith(
            (ref) => _SettingsController(ref),
          ),
          aiEmployeesControllerProvider.overrideWith((ref) {
            controller = _FakeEmployeesController(ref, seed);
            return controller;
          }),
          companyWorkerDiscoveryControllerProvider.overrideWith(
            _QuietDiscoveryController.new,
          ),
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
    return controller;
  }

  test('parses an employee without relying on its name', () {
    final model = AiEmployeeModel.fromJson({
      'id': 5,
      'name': 'Anything',
      'role': 'customer_support',
      'status': 'inactive',
      'is_default': false,
      'avatar_key': 'support_jordanian',
      'config_version': 3,
      'persona': {
        'language': 'ar',
        'dialect': 'jordanian',
        'traits': ['calm'],
      },
      'skills': [
        {'key': 'customer_support', 'settings': {}},
      ],
      'model': {
        'provider': 'openai',
        'model': 'gpt-4o-mini',
        'response_length': 'medium',
        'temperature': 0.2,
        'source': 'employee',
        'is_override': true,
      },
    });

    expect(model.id, 5);
    expect(model.isActive, isFalse);
    expect(model.persona.dialect, 'jordanian');
    expect(model.skills, ['customer_support']);
    expect(model.responseLength, 'medium');
    expect(model.provider, 'openai');
    expect(model.model, 'gpt-4o-mini');
    expect(model.modelIsOverride, isTrue);
    expect(
      AiEmployeeDraft.fromEmployee(
        model,
      ).toJson()['model'].containsKey('provider'),
      isFalse,
    );
    expect(
      AiEmployeeDraft.fromEmployee(model).toJson()['persona']['dialect'],
      'jordanian',
    );
  });

  test('preview success with high latency is not a hard failure', () {
    final result = AiEmployeeTestResult.fromJson({
      'status': 'replied',
      'outcome': 'latency_warning',
      'text': 'أهلا',
      'provider': 'openai',
      'model': 'gpt-4o-mini',
      'used_model': 'gpt-4o-mini',
      'elapsed_ms': 4592,
      'tokens': 12,
      'checks': [
        {
          'id': 'latency',
          'label': 'اختبار سرعة الاستجابة',
          'ok': true,
          'warning': true,
          'detail': '4592 ms',
          'elapsed_ms': 4592,
        },
      ],
    });

    expect(result.replied, isTrue);
    expect(result.failed, isFalse);
    expect(result.provider, 'openai');
    expect(result.usedModel, 'gpt-4o-mini');
    expect(result.usedModelLine, 'gpt-4o-mini');
    expect(result.checks.single.ok, isTrue);
    expect(result.checks.single.warning, isTrue);
  });

  testWidgets('lists employees with the default badge', (tester) async {
    await pump(
      tester,
      AiEmployeesState(
        employees: [
          _employee(1, 'نورة', isDefault: true),
          _employee(2, 'سالم'),
        ],
        selectedId: 1,
      ),
    );

    expect(find.text('نورة'), findsOneWidget);
    expect(find.text('سالم'), findsOneWidget);
    expect(find.text('افتراضي'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'تعيين كافتراضي'), findsOneWidget);
  });

  testWidgets('selection is by id even when two employees share a name', (
    tester,
  ) async {
    final controller = await pump(
      tester,
      AiEmployeesState(
        employees: [
          _employee(1, 'نورة', isDefault: true),
          _employee(2, 'نورة'),
        ],
        selectedId: 1,
      ),
    );

    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('ai-employee-2')),
        matching: find.text('نورة'),
      ),
    );
    await tester.pumpAndSettle();

    expect(controller.state.selectedId, 2);
    expect(controller.state.selected?.isDefault, isFalse);
  });

  testWidgets('rename keeps the same employee selected', (tester) async {
    final controller = await pump(
      tester,
      AiEmployeesState(
        employees: [
          _employee(1, 'نورة', isDefault: true),
          _employee(2, 'سالم'),
        ],
        selectedId: 2,
      ),
    );

    await controller.rename(2, 'خالد');
    await tester.pumpAndSettle();

    expect(controller.state.selectedId, 2);
    expect(controller.state.selected?.name, 'خالد');
    expect(find.text('خالد'), findsOneWidget);
    expect(find.text('سالم'), findsNothing);
  });

  testWidgets('set default targets the employee id', (tester) async {
    final controller = await pump(
      tester,
      AiEmployeesState(
        employees: [
          _employee(1, 'نورة', isDefault: true),
          _employee(2, 'سالم'),
        ],
        selectedId: 1,
      ),
    );

    await tester.tap(find.widgetWithText(FilledButton, 'تعيين كافتراضي'));
    await tester.pumpAndSettle();

    expect(controller.calls, ['default:2']);
    expect(find.text('افتراضي'), findsOneWidget);
    expect(
      controller.state.employees.firstWhere((e) => e.id == 2).isDefault,
      isTrue,
    );
  });

  testWidgets('creates an employee from a server preset', (tester) async {
    final controller = await pump(
      tester,
      const AiEmployeesState(
        presets: [
          AiEmployeePresetModel(
            key: 'sales_saudi',
            version: 1,
            displayName: 'قالب المبيعات',
            summary: 'مبيعات',
          ),
        ],
      ),
    );

    expect(
      find.text(
        'لا يوجد موظفون بعد. أضف موظفًا من قالب أو أنشئ موظفًا جديدًا.',
      ),
      findsOneWidget,
    );
    await tester.tap(find.text('إضافة موظف من قالب'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('ai-preset-sales_saudi')));
    await tester.pumpAndSettle();

    expect(controller.calls, ['preset:sales_saudi']);
    expect(controller.state.selectedId, 99);
    expect(find.text('من القالب'), findsOneWidget);
  });

  testWidgets(
    'general tab shows the selected employee and hides model controls',
    (tester) async {
      await pumpSettings(
        tester,
        AiEmployeesState(
          employees: [
            _employee(1, 'نورة', isDefault: true, instructions: 'تعليمات نورة'),
            _employee(2, 'سالم', instructions: 'تعليمات سالم'),
          ],
          selectedId: 1,
        ),
      );

      expect(
        find.byKey(const ValueKey('ai-general-employee-heading')),
        findsOneWidget,
      );
      expect(
        tester
            .widget<Text>(
              find.byKey(const ValueKey('ai-general-employee-heading')),
            )
            .data,
        'نورة',
      );
      expect(
        tester
            .widget<TextField>(
              find.byKey(const ValueKey('ai-general-employee-name')),
            )
            .controller
            ?.text,
        'نورة',
      );
      expect(
        tester
            .widget<TextField>(
              find.byKey(const ValueKey('ai-general-employee-instructions')),
            )
            .controller
            ?.text,
        'تعليمات نورة',
      );
      expect(find.text('النموذج المحدد:'), findsNothing);
      expect(find.text('المزود'), findsNothing);
      expect(find.text('نموذج المنصة'), findsNothing);
      expect(find.textContaining('gemini-2.5-flash'), findsNothing);
      expect(find.textContaining('GEMINI'), findsNothing);
      expect(find.textContaining('api_key'), findsNothing);
    },
  );

  testWidgets('selecting an employee shows that employee in general', (
    tester,
  ) async {
    final controller = await pump(
      tester,
      AiEmployeesState(
        employees: [
          _employee(1, 'نورة', isDefault: true, instructions: 'تعليمات نورة'),
          _employee(2, 'سالم', instructions: 'تعليمات سالم'),
        ],
        selectedId: 1,
      ),
    );

    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('ai-employee-2')),
        matching: find.text('سالم'),
      ),
    );
    await tester.pumpAndSettle();
    expect(controller.state.selectedId, 2);
    expect(
      tester
          .widget<Text>(
            find.byKey(const ValueKey('ai-general-employee-heading')),
          )
          .data,
      'سالم',
    );
    expect(
      tester
          .widget<TextField>(
            find.byKey(const ValueKey('ai-general-employee-name')),
          )
          .controller
          ?.text,
      'سالم',
    );
    expect(
      tester
          .widget<TextField>(
            find.byKey(const ValueKey('ai-general-employee-instructions')),
          )
          .controller
          ?.text,
      'تعليمات سالم',
    );
  });

  testWidgets(
    'switching employees A to B to A restores general employee data',
    (tester) async {
      final controller = await pumpSettings(
        tester,
        AiEmployeesState(
          employees: [
            _employee(1, 'نورة', isDefault: true, instructions: 'تعليمات نورة'),
            _employee(2, 'سالم', instructions: 'تعليمات سالم'),
          ],
          selectedId: 1,
        ),
      );

      expect(
        tester
            .widget<TextField>(
              find.byKey(const ValueKey('ai-general-employee-name')),
            )
            .controller
            ?.text,
        'نورة',
      );

      await tester.tap(find.text('موظفو الذكاء الاصطناعي'));
      await tester.pumpAndSettle();
      await tester.tap(
        find.descendant(
          of: find.byKey(const ValueKey('ai-employee-2')),
          matching: find.text('سالم'),
        ),
      );
      await tester.pumpAndSettle();
      expect(controller.state.selectedId, 2);
      expect(
        tester
            .widget<TextField>(
              find.byKey(const ValueKey('ai-general-employee-name')),
            )
            .controller
            ?.text,
        'سالم',
      );
      expect(
        tester
            .widget<TextField>(
              find.byKey(const ValueKey('ai-general-employee-instructions')),
            )
            .controller
            ?.text,
        'تعليمات سالم',
      );

      await tester.tap(find.text('موظفو الذكاء الاصطناعي'));
      await tester.pumpAndSettle();
      await tester.tap(
        find.descendant(
          of: find.byKey(const ValueKey('ai-employee-1')),
          matching: find.text('نورة'),
        ),
      );
      await tester.pumpAndSettle();
      expect(controller.state.selectedId, 1);
      expect(
        tester
            .widget<TextField>(
              find.byKey(const ValueKey('ai-general-employee-name')),
            )
            .controller
            ?.text,
        'نورة',
      );
      expect(
        tester
            .widget<TextField>(
              find.byKey(const ValueKey('ai-general-employee-instructions')),
            )
            .controller
            ?.text,
        'تعليمات نورة',
      );
    },
  );

  testWidgets('preview dialog hides the model returned by the backend', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 1200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          aiEmployeesControllerProvider.overrideWith(
            (ref) => _FakeEmployeesController(
              ref,
              AiEmployeesState(
                employees: [
                  _employee(1, 'نورة', isDefault: true),
                  _employee(
                    2,
                    'سالم',
                    provider: 'openai',
                    model: 'gpt-4o-mini',
                  ),
                ],
                selectedId: 1,
              ),
            ),
          ),
        ],
        child: const MaterialApp(
          locale: Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: AiTestScreen(employeeId: 2),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'تشغيل الاختبار'));
    await tester.pumpAndSettle();

    expect(find.text('النموذج المستخدم: gpt-4o-mini'), findsNothing);
    expect(find.textContaining('gemini-2.5-flash'), findsNothing);
  });
}
