import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/settings/data/ai_employee_model.dart';

class AiEmployeesState {
  const AiEmployeesState({
    this.employees = const [],
    this.presets = const [],
    this.options = const AiEmployeeOptions(),
    this.selectedId,
    this.loading = false,
    this.saving = false,
    this.error,
  });

  final List<AiEmployeeModel> employees;
  final List<AiEmployeePresetModel> presets;
  final AiEmployeeOptions options;

  /// Selection is always by employee id, never by name.
  final int? selectedId;
  final bool loading;
  final bool saving;
  final String? error;

  AiEmployeeModel? get selected {
    for (final employee in employees) {
      if (employee.id == selectedId) return employee;
    }
    return null;
  }

  AiEmployeesState copyWith({
    List<AiEmployeeModel>? employees,
    List<AiEmployeePresetModel>? presets,
    AiEmployeeOptions? options,
    int? selectedId,
    bool? loading,
    bool? saving,
    String? error,
    bool clearError = false,
  }) {
    return AiEmployeesState(
      employees: employees ?? this.employees,
      presets: presets ?? this.presets,
      options: options ?? this.options,
      selectedId: selectedId ?? this.selectedId,
      loading: loading ?? this.loading,
      saving: saving ?? this.saving,
      error: clearError ? null : (error ?? this.error),
    );
  }
}

final aiEmployeesControllerProvider =
    StateNotifierProvider<AiEmployeesController, AiEmployeesState>((ref) {
  final controller = AiEmployeesController(ref);
  ref.listen<String>(
    authControllerProvider.select((s) => s.isolationKey),
    (previous, next) {
      if (previous != next) {
        controller.reload();
      }
    },
  );
  return controller;
});

class AiEmployeesController extends StateNotifier<AiEmployeesState> {
  AiEmployeesController(this._ref) : super(const AiEmployeesState()) {
    reload();
  }

  final Ref _ref;

  Future<void> reload() async {
    final workspaceId = _ref.read(authControllerProvider).workspace?.id;
    if (workspaceId == null) {
      state = const AiEmployeesState();
      return;
    }
    state = state.copyWith(loading: true, clearError: true);
    try {
      final repository = _ref.read(aiRepositoryProvider);
      final payload = await repository.fetchEmployees();
      final presets = await repository.fetchEmployeePresets();
      state = AiEmployeesState(
        employees: payload.employees,
        presets: presets,
        options: payload.options,
        selectedId: _keepSelection(payload.employees, state.selectedId),
      );
    } on ApiException catch (e) {
      state = AiEmployeesState(error: e.userMessage);
    } catch (_) {
      state = const AiEmployeesState(error: 'تعذر تحميل الموظفين.');
    }
  }

  void select(int id) {
    if (state.employees.any((employee) => employee.id == id)) {
      state = state.copyWith(selectedId: id);
    }
  }

  Future<bool> createFromPreset(String presetKey, {String? name}) =>
      _mutate(() => _ref.read(aiRepositoryProvider).createEmployee(
            presetKey: presetKey,
            fields: name == null || name.trim().isEmpty ? null : {'name': name.trim()},
          ));

  Future<bool> create(AiEmployeeDraft draft) =>
      _mutate(() => _ref.read(aiRepositoryProvider).createEmployee(fields: draft.toJson()));

  Future<bool> update(int id, AiEmployeeDraft draft) =>
      _mutate(() => _ref.read(aiRepositoryProvider).updateEmployee(id, draft.toJson()));

  Future<bool> rename(int id, String name) =>
      _mutate(() => _ref.read(aiRepositoryProvider).updateEmployee(id, {'name': name.trim()}));

  Future<bool> setDefault(int id) => _mutate(() => _ref.read(aiRepositoryProvider).setDefaultEmployee(id));

  Future<bool> setActive(int id, {required bool active}) =>
      _mutate(() => _ref.read(aiRepositoryProvider).setEmployeeActive(id, active: active));

  Future<bool> setSkills(int id, List<String> keys) =>
      _mutate(() => _ref.read(aiRepositoryProvider).setEmployeeSkills(id, keys));

  Future<bool> delete(int id) async {
    state = state.copyWith(saving: true, clearError: true);
    try {
      await _ref.read(aiRepositoryProvider).deleteEmployee(id);
      final remaining = state.employees.where((employee) => employee.id != id).toList();
      state = AiEmployeesState(
        employees: remaining,
        presets: state.presets,
        options: state.options,
        selectedId: _keepSelection(remaining, state.selectedId == id ? null : state.selectedId),
      );
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(saving: false, error: e.userMessage);
      return false;
    } catch (_) {
      state = state.copyWith(saving: false, error: 'تعذر حذف الموظف.');
      return false;
    }
  }

  Future<AiEmployeeTestResult?> test(int id, String message) async {
    try {
      return await _ref.read(aiRepositoryProvider).testEmployee(id, message);
    } on ApiException catch (e) {
      state = state.copyWith(error: e.userMessage);
      return null;
    }
  }

  /// Applies one saved employee and refreshes the list so the single default stays consistent.
  Future<bool> _mutate(Future<AiEmployeeModel> Function() operation) async {
    state = state.copyWith(saving: true, clearError: true);
    try {
      final saved = await operation();
      List<AiEmployeeModel> employees = state.employees;
      try {
        employees = (await _ref.read(aiRepositoryProvider).fetchEmployees()).employees;
      } catch (_) {}
      state = AiEmployeesState(
        employees: _withSaved(employees, saved),
        presets: state.presets,
        options: state.options,
        selectedId: saved.id,
      );
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(saving: false, error: e.userMessage);
      return false;
    } catch (_) {
      state = state.copyWith(saving: false, error: 'تعذر حفظ الموظف.');
      return false;
    }
  }

  static List<AiEmployeeModel> _withSaved(List<AiEmployeeModel> employees, AiEmployeeModel saved) {
    var replaced = false;
    final next = <AiEmployeeModel>[];
    for (final employee in employees) {
      if (employee.id == saved.id) {
        next.add(saved);
        replaced = true;
      } else {
        next.add(employee);
      }
    }
    if (!replaced) next.add(saved);
    return next;
  }

  static int? _keepSelection(List<AiEmployeeModel> employees, int? current) {
    if (current != null && employees.any((employee) => employee.id == current)) return current;
    for (final employee in employees) {
      if (employee.isDefault) return employee.id;
    }
    return employees.isEmpty ? null : employees.first.id;
  }
}
