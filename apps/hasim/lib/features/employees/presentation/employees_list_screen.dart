import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/employees/data/employee_models.dart';
import 'package:hasim/features/employees/presentation/invite_employee_dialog.dart';
import 'package:hasim/features/employees/providers/employees_controller.dart';

class EmployeesListScreen extends ConsumerStatefulWidget {
  const EmployeesListScreen({super.key});

  @override
  ConsumerState<EmployeesListScreen> createState() => _EmployeesListScreenState();
}

class _EmployeesListScreenState extends ConsumerState<EmployeesListScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  void _openInviteDialog(List<String> roles) {
    showDialog<bool>(
      context: context,
      builder: (_) => InviteEmployeeDialog(assignableRoles: roles),
    );
  }

  void _showRoleSheet(EmployeeMemberModel employee, List<String> roles) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
                  child: Text(
                    'تعديل دور ${employee.name}',
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                  ),
                ),
                const Divider(),
                ...roles.map((role) {
                  final isCurrent = employee.membershipRole == role;
                  return ListTile(
                    leading: Icon(
                      isCurrent ? Icons.check_circle : Icons.radio_button_unchecked,
                      color: isCurrent ? HasimTokens.primary : Colors.grey,
                    ),
                    title: Text(employee.roleLabel),
                    onTap: () async {
                      Navigator.of(ctx).pop();
                      if (isCurrent) return;
                      final ok = await ref
                          .read(employeesControllerProvider.notifier)
                          .updateRole(employee.id, role);
                      if (mounted && ok) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(
                            content: Text('تم تغيير الدور إلى $role'),
                            backgroundColor: HasimTokens.chatGreen,
                          ),
                        );
                      }
                    },
                  );
                }),
              ],
            ),
          ),
        );
      },
    );
  }

  void _confirmDelete(EmployeeMemberModel employee) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('حذف الموظف'),
        content: Text('هل أنت متأكد من رغبتك في حذف ${employee.name} من مساحة العمل؟'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () async {
              Navigator.of(ctx).pop();
              final ok = await ref
                  .read(employeesControllerProvider.notifier)
                  .remove(employee.id);
              if (mounted && ok) {
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(
                    content: Text('تم حذف الموظف بنجاح.'),
                    backgroundColor: Colors.red,
                  ),
                );
              }
            },
            child: const Text('نعم، حذف'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(employeesControllerProvider);
    final auth = ref.watch(authControllerProvider);
    final isOwnerOrAdmin = auth.workspace?.isOwner == true ||
        auth.workspace?.role == 'owner' ||
        auth.workspace?.role == 'admin';

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: const Text('الموظفون وفريق العمل', style: TextStyle(fontWeight: FontWeight.bold)),
        backgroundColor: HasimTokens.cardBg(context),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        actions: [
          if (isOwnerOrAdmin)
            IconButton(
              icon: const Icon(Icons.person_add_alt_1_outlined),
              tooltip: 'دعوة موظف',
              onPressed: () => _openInviteDialog(state.assignableRoles),
            ),
        ],
        bottom: TabBar(
          controller: _tabController,
          labelColor: HasimTokens.primary,
          indicatorColor: HasimTokens.primary,
          tabs: [
            Tab(text: 'الفريق الحالي (${state.employees.length})'),
            Tab(text: 'الدعوات المعلقة (${state.invitations.length})'),
          ],
        ),
      ),
      body: state.loading && state.employees.isEmpty
          ? const SkeletonList()
          : state.error != null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(state.error!),
                      const SizedBox(height: 12),
                      FilledButton(
                        onPressed: () => ref.read(employeesControllerProvider.notifier).load(),
                        child: const Text('إعادة المحاولة'),
                      ),
                    ],
                  ),
                )
              : TabBarView(
                  controller: _tabController,
                  children: [
                    // Tab 1: Current Employees
                    RefreshIndicator(
                      onRefresh: () => ref.read(employeesControllerProvider.notifier).load(),
                      child: state.employees.isEmpty
                          ? const EmptyState(
                              title: 'لا يوجد موظفون مضافون بعد',
                              subtitle: 'قم بدعوة أعضاء فريقك لإدارة المحادثات والمبيعات معاً.',
                            )
                          : ListView.separated(
                              padding: const EdgeInsets.all(16),
                              itemCount: state.employees.length,
                              separatorBuilder: (_, _) => const SizedBox(height: 10),
                              itemBuilder: (context, index) {
                                final emp = state.employees[index];
                                return Card(
                                  elevation: 0,
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(14),
                                    side: BorderSide(color: HasimTokens.lineColor(context)),
                                  ),
                                  color: HasimTokens.cardBg(context),
                                  child: ListTile(
                                    contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                                    leading: CircleAvatar(
                                      backgroundColor: emp.isOwner
                                          ? HasimTokens.primary.withValues(alpha: 0.15)
                                          : Colors.grey.shade200,
                                      child: Icon(
                                        emp.isOwner ? Icons.workspace_premium : Icons.person,
                                        color: emp.isOwner ? HasimTokens.primary : Colors.grey.shade700,
                                      ),
                                    ),
                                    title: Row(
                                      children: [
                                        Text(
                                          emp.name,
                                          style: const TextStyle(fontWeight: FontWeight.bold),
                                        ),
                                        if (emp.isOwner) ...[
                                          const SizedBox(width: 6),
                                          Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                            decoration: BoxDecoration(
                                              color: Colors.amber.shade100,
                                              borderRadius: BorderRadius.circular(6),
                                            ),
                                            child: const Text(
                                              'مالك',
                                              style: TextStyle(
                                                fontSize: 11,
                                                fontWeight: FontWeight.bold,
                                                color: Colors.brown,
                                              ),
                                            ),
                                          ),
                                        ],
                                      ],
                                    ),
                                    subtitle: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        const SizedBox(height: 4),
                                        Text(emp.email ?? emp.phone ?? ''),
                                        const SizedBox(height: 4),
                                        Row(
                                          children: [
                                            Container(
                                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                                              decoration: BoxDecoration(
                                                color: HasimTokens.mintSoft,
                                                borderRadius: BorderRadius.circular(8),
                                              ),
                                              child: Text(
                                                emp.roleLabel,
                                                style: const TextStyle(
                                                  fontSize: 12,
                                                  fontWeight: FontWeight.w600,
                                                  color: HasimTokens.primary,
                                                ),
                                              ),
                                            ),
                                          ],
                                        ),
                                      ],
                                    ),
                                    trailing: isOwnerOrAdmin && !emp.isOwner
                                        ? PopupMenuButton<String>(
                                            onSelected: (val) {
                                              if (val == 'role') {
                                                _showRoleSheet(emp, state.assignableRoles);
                                              } else if (val == 'delete') {
                                                _confirmDelete(emp);
                                              }
                                            },
                                            itemBuilder: (ctx) => [
                                              const PopupMenuItem(
                                                value: 'role',
                                                child: Row(
                                                  children: [
                                                    Icon(Icons.edit_outlined, size: 18),
                                                    SizedBox(width: 8),
                                                    Text('تعديل الدور'),
                                                  ],
                                                ),
                                              ),
                                              const PopupMenuItem(
                                                value: 'delete',
                                                child: Row(
                                                  children: [
                                                    Icon(Icons.delete_outline, size: 18, color: Colors.red),
                                                    SizedBox(width: 8),
                                                    Text('حذف', style: TextStyle(color: Colors.red)),
                                                  ],
                                                ),
                                              ),
                                            ],
                                          )
                                        : null,
                                  ),
                                );
                              },
                            ),
                    ),

                    // Tab 2: Pending Invitations
                    RefreshIndicator(
                      onRefresh: () => ref.read(employeesControllerProvider.notifier).load(),
                      child: state.invitations.isEmpty
                          ? const EmptyState(
                              title: 'لا توجد دعوات معلقة',
                              subtitle: 'يمكنك إرسال دعوات جديدة لفريقك من زر الإضافة أعلاه.',
                            )
                          : ListView.separated(
                              padding: const EdgeInsets.all(16),
                              itemCount: state.invitations.length,
                              separatorBuilder: (_, _) => const SizedBox(height: 10),
                              itemBuilder: (context, index) {
                                final inv = state.invitations[index];
                                return Card(
                                  elevation: 0,
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(14),
                                    side: BorderSide(color: HasimTokens.lineColor(context)),
                                  ),
                                  color: HasimTokens.cardBg(context),
                                  child: ListTile(
                                    leading: const CircleAvatar(
                                      backgroundColor: Colors.orangeAccent,
                                      child: Icon(Icons.mail_outline, color: Colors.white),
                                    ),
                                    title: Text(inv.email, style: const TextStyle(fontWeight: FontWeight.bold)),
                                    subtitle: Text('الدور: ${inv.roleLabel} • بانتظار القبول'),
                                  ),
                                );
                              },
                            ),
                    ),
                  ],
                ),
      floatingActionButton: isOwnerOrAdmin
          ? FloatingActionButton.extended(
              backgroundColor: HasimTokens.chatGreen,
              onPressed: () => _openInviteDialog(state.assignableRoles),
              icon: const Icon(Icons.person_add_alt_1),
              label: const Text('دعوة موظف'),
            )
          : null,
    );
  }
}
