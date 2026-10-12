class _EmployeesTab extends ConsumerStatefulWidget {
  const _EmployeesTab({required this.roles, required this.onSelectEmployee});

  final List<String> roles;
  final ValueChanged<int> onSelectEmployee;

  @override
  ConsumerState<_EmployeesTab> createState() => _EmployeesTabState();
}
