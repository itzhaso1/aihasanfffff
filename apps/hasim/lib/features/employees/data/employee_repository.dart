import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/features/employees/data/employee_models.dart';

final employeeRepositoryProvider = Provider<EmployeeRepository>((ref) {
  return EmployeeRepository(ref.watch(apiClientProvider));
});

class EmployeeRepository {
  const EmployeeRepository(this._api);

  final ApiClient _api;

  Future<({List<EmployeeMemberModel> employees, List<EmployeeInvitationModel> invitations, List<String> assignableRoles})> list() async {
    final res = await _api.get('employees');
    final data = res.data is Map ? res.data as Map : <String, dynamic>{};

    final rawEmployees = data['employees'] as List? ?? [];
    final employees = rawEmployees
        .whereType<Map<String, dynamic>>()
        .map(EmployeeMemberModel.fromJson)
        .toList();

    final rawInvitations = data['invitations'] as List? ?? [];
    final invitations = rawInvitations
        .whereType<Map<String, dynamic>>()
        .map(EmployeeInvitationModel.fromJson)
        .toList();

    final rawRoles = data['assignable_roles'] as List? ?? [];
    final roles = rawRoles.map((r) => r.toString()).toList();

    return (
      employees: employees,
      invitations: invitations,
      assignableRoles: roles,
    );
  }

  Future<EmployeeInvitationModel> invite({
    required String email,
    required String role,
  }) async {
    final res = await _api.post(
      'employees/invite',
      body: {
        'email': email,
        'role': role,
      },
    );
    final data = res.data is Map<String, dynamic> ? res.data as Map<String, dynamic> : <String, dynamic>{};
    return EmployeeInvitationModel.fromJson(data);
  }

  Future<void> updateRole({
    required int membershipId,
    required String role,
  }) async {
    await _api.patch(
      'employees/$membershipId',
      body: {'membership_role': role},
    );
  }

  Future<void> updateStatus({
    required int membershipId,
    required String status,
  }) async {
    await _api.patch(
      'employees/$membershipId',
      body: {'status': status},
    );
  }

  Future<void> remove({required int membershipId}) async {
    await _api.delete('employees/$membershipId');
  }
}
