import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/features/employees/data/employee_models.dart';
import 'package:hasim/features/employees/data/employee_repository.dart';
import 'package:hasim/features/employees/providers/employees_controller.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _FakeEmployeeRepository extends EmployeeRepository {
  _FakeEmployeeRepository(super.api);

  final List<EmployeeMemberModel> mockEmployees = [
    const EmployeeMemberModel(
      id: 1,
      userId: 10,
      name: 'مالك مساحة العمل',
      email: 'owner@example.com',
      membershipRole: 'owner',
      status: 'active',
      isOwner: true,
      createdAt: '2026-01-01',
    ),
    const EmployeeMemberModel(
      id: 2,
      userId: 11,
      name: 'أحمد مشرف',
      email: 'ahmed@example.com',
      membershipRole: 'admin',
      status: 'active',
      isOwner: false,
      createdAt: '2026-02-01',
    ),
  ];

  final List<EmployeeInvitationModel> mockInvitations = [
    const EmployeeInvitationModel(
      id: 10,
      email: 'invitee@example.com',
      role: 'agent',
      status: 'pending',
      createdAt: '2026-03-01',
    ),
  ];

  @override
  Future<({List<EmployeeMemberModel> employees, List<EmployeeInvitationModel> invitations, List<String> assignableRoles})> list() async {
    return (
      employees: List.of(mockEmployees),
      invitations: List.of(mockInvitations),
      assignableRoles: ['admin', 'manager', 'agent', 'accountant'],
    );
  }

  @override
  Future<EmployeeInvitationModel> invite({required String email, required String role}) async {
    final inv = EmployeeInvitationModel(
      id: 99,
      email: email,
      role: role,
      status: 'pending',
      createdAt: '2026-03-15',
    );
    mockInvitations.add(inv);
    return inv;
  }

  @override
  Future<void> updateRole({required int membershipId, required String role}) async {
    final idx = mockEmployees.indexWhere((e) => e.id == membershipId);
    if (idx != -1) {
      final e = mockEmployees[idx];
      mockEmployees[idx] = EmployeeMemberModel(
        id: e.id,
        userId: e.userId,
        name: e.name,
        email: e.email,
        phone: e.phone,
        avatarUrl: e.avatarUrl,
        membershipRole: role,
        status: e.status,
        isOwner: e.isOwner,
        createdAt: e.createdAt,
      );
    }
  }

  @override
  Future<void> remove({required int membershipId}) async {
    mockEmployees.removeWhere((e) => e.id == membershipId);
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('Employee Models & Serialization', () {
    test('EmployeeMemberModel parsing matches backend contract', () {
      final json = {
        'id': 5,
        'user_id': 20,
        'name': 'سارة مديرة',
        'email': 'sara@example.com',
        'membership_role': 'manager',
        'status': 'active',
        'is_owner': false,
        'created_at': '2026-01-10T12:00:00Z',
      };

      final emp = EmployeeMemberModel.fromJson(json);
      expect(emp.id, 5);
      expect(emp.name, 'سارة مديرة');
      expect(emp.email, 'sara@example.com');
      expect(emp.membershipRole, 'manager');
      expect(emp.roleLabel, 'مدير العمليات');
      expect(emp.isOwner, isFalse);
      expect(emp.statusLabel, 'نشط');
    });

    test('EmployeeInvitationModel parsing matches backend contract', () {
      final json = {
        'id': 12,
        'email': 'new_agent@example.com',
        'role': 'agent',
        'status': 'pending',
        'created_at': '2026-02-15T08:30:00Z',
      };

      final inv = EmployeeInvitationModel.fromJson(json);
      expect(inv.id, 12);
      expect(inv.email, 'new_agent@example.com');
      expect(inv.role, 'agent');
      expect(inv.roleLabel, 'وكيل خدمة العملاء');
      expect(inv.status, 'pending');
    });
  });

  group('EmployeesController State & Operations', () {
    late _FakeEmployeeRepository repo;
    late EmployeesController controller;

    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      final prefs = PrefsStore(await SharedPreferences.getInstance());
      final api = isolatedTestApiClient(
        secureStore: SecureStore(),
        prefsStore: prefs,
      );
      repo = _FakeEmployeeRepository(api);
      controller = EmployeesController(repo);
    });

    test('load() populates employees and invitations list', () async {
      await controller.load();
      expect(controller.state.loading, isFalse);
      expect(controller.state.employees.length, 2);
      expect(controller.state.invitations.length, 1);
      expect(controller.state.assignableRoles.length, 4);
      expect(controller.state.employees.first.name, 'مالك مساحة العمل');
    });

    test('invite() adds invitation and updates state', () async {
      await controller.load();
      final success = await controller.invite(email: 'test@example.com', role: 'agent');
      expect(success, isTrue);
      expect(controller.state.invitations.length, 2);
      expect(controller.state.invitations.any((i) => i.email == 'test@example.com'), isTrue);
    });

    test('updateRole() updates employee role in-place', () async {
      await controller.load();
      final success = await controller.updateRole(2, 'manager');
      expect(success, isTrue);
      final updated = controller.state.employees.firstWhere((e) => e.id == 2);
      expect(updated.membershipRole, 'manager');
    });

    test('remove() removes member from state', () async {
      await controller.load();
      final success = await controller.remove(2);
      expect(success, isTrue);
      expect(controller.state.employees.any((e) => e.id == 2), isFalse);
      expect(controller.state.employees.length, 1);
    });
  });
}
