import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/features/employees/data/employee_models.dart';
import 'package:hasim/features/employees/data/employee_repository.dart';

class EmployeesState {
  const EmployeesState({
    this.employees = const [],
    this.invitations = const [],
    this.assignableRoles = const [],
    this.loading = false,
    this.error,
  });

  final List<EmployeeMemberModel> employees;
  final List<EmployeeInvitationModel> invitations;
  final List<String> assignableRoles;
  final bool loading;
  final String? error;

  EmployeesState copyWith({
    List<EmployeeMemberModel>? employees,
    List<EmployeeInvitationModel>? invitations,
    List<String>? assignableRoles,
    bool? loading,
    String? error,
  }) {
    return EmployeesState(
      employees: employees ?? this.employees,
      invitations: invitations ?? this.invitations,
      assignableRoles: assignableRoles ?? this.assignableRoles,
      loading: loading ?? this.loading,
      error: error,
    );
  }
}

final employeesControllerProvider =
    StateNotifierProvider.autoDispose<EmployeesController, EmployeesState>((
      ref,
    ) {
      return EmployeesController(ref.watch(employeeRepositoryProvider));
    });

class EmployeesController extends StateNotifier<EmployeesState> {
  EmployeesController(this._repo) : super(const EmployeesState()) {
    load();
  }

  final EmployeeRepository _repo;

  Future<void> load() async {
    state = state.copyWith(loading: true, error: null);
    try {
      final res = await _repo.list();
      state = state.copyWith(
        employees: res.employees,
        invitations: res.invitations,
        assignableRoles: res.assignableRoles,
        loading: false,
      );
    } catch (e) {
      state = state.copyWith(
        loading: false,
        error: 'تعذر تحميل قائمة الموظفين. يرجى المحاولة لاحقاً.',
      );
    }
  }

  Future<bool> invite({required String email, required String role}) async {
    try {
      final invitation = await _repo.invite(email: email, role: role);
      state = state.copyWith(
        invitations: [invitation, ...state.invitations],
      );
      return true;
    } catch (_) {
      return false;
    }
  }

  Future<bool> updateRole(int membershipId, String newRole) async {
    try {
      await _repo.updateRole(membershipId: membershipId, role: newRole);
      final updated = state.employees.map((e) {
        if (e.id == membershipId) {
          return EmployeeMemberModel(
            id: e.id,
            userId: e.userId,
            name: e.name,
            email: e.email,
            phone: e.phone,
            avatarUrl: e.avatarUrl,
            membershipRole: newRole,
            status: e.status,
            isOwner: e.isOwner,
            createdAt: e.createdAt,
          );
        }
        return e;
      }).toList();
      state = state.copyWith(employees: updated);
      return true;
    } catch (_) {
      return false;
    }
  }

  Future<bool> updateStatus(int membershipId, String newStatus) async {
    try {
      await _repo.updateStatus(membershipId: membershipId, status: newStatus);
      final updated = state.employees.map((e) {
        if (e.id == membershipId) {
          return EmployeeMemberModel(
            id: e.id,
            userId: e.userId,
            name: e.name,
            email: e.email,
            phone: e.phone,
            avatarUrl: e.avatarUrl,
            membershipRole: e.membershipRole,
            status: newStatus,
            isOwner: e.isOwner,
            createdAt: e.createdAt,
          );
        }
        return e;
      }).toList();
      state = state.copyWith(employees: updated);
      return true;
    } catch (_) {
      return false;
    }
  }

  Future<bool> remove(int membershipId) async {
    try {
      await _repo.remove(membershipId: membershipId);
      final updated = state.employees.where((e) => e.id != membershipId).toList();
      state = state.copyWith(employees: updated);
      return true;
    } catch (_) {
      return false;
    }
  }
}
