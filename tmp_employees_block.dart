class _EmployeesTab extends ConsumerStatefulWidget {
  const _EmployeesTab({required this.roles, required this.onSelectEmployee});

  final List<String> roles;
  final ValueChanged<int> onSelectEmployee;

  @override
  ConsumerState<_EmployeesTab> createState() => _EmployeesTabState();
}

class _EmployeesTabState extends ConsumerState<_EmployeesTab> {
  @override
  Widget build(BuildContext context) {
    final aiState = ref.watch(aiEmployeesControllerProvider);
    final aiController = ref.read(aiEmployeesControllerProvider.notifier);
    final discoveryState = ref.watch(companyWorkerDiscoveryControllerProvider);
    final discoveryController = ref.read(companyWorkerDiscoveryControllerProvider.notifier);

    if (aiState.loading && aiState.employees.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }
    if (aiState.error != null && aiState.employees.isEmpty && aiState.presets.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(aiState.error!, style: _aiTitle(context)),
            TextButton(onPressed: aiController.reload, child: Text(AppLocalizations.of(context).retry)),
          ],
        ),
      );
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
      children: [
        Text(
          'كل موظف له اسمه وأسلوبه ومهاراته. الموظف الافتراضي هو من سيرد في المحادثات عند تفعيل نظام الموظفين في صندوق الوارد.',
          softWrap: true,
          style: _aiMuted(context).copyWith(fontSize: 13, height: 1.6),
        ),
        if (aiState.error != null) ...[
          const SizedBox(height: 8),
          Text(aiState.error!, style: _aiMuted(context).copyWith(color: HasimTokens.danger)),
        ],
        const SizedBox(height: 12),
        if (aiState.employees.isEmpty)
          _SectionCard(child: Text('لا يوجد موظفون بعد. أضف موظفًا من قالب أو أنشئ موظفًا جديدًا.', style: _aiTitle(context))),
        for (final employee in aiState.employees) ...[
          _EmployeeCard(
            key: ValueKey('ai-employee-${employee.id}'),
            employee: employee,
            selected: employee.id == aiState.selectedId,
            busy: aiState.saving,
            onSelect: () => widget.onSelectEmployee(employee.id),
            onSetDefault: () => aiController.setDefault(employee.id),
            onToggleActive: () => aiController.setActive(employee.id, active: !employee.isActive),
            onEdit: () => _openEditor(context, ref, employee: employee),
            onRename: () => _rename(context, ref, employee),
            onTest: () => _openTest(context, employee),
            onDelete: () => _confirmDelete(context, ref, employee),
          ),
          const SizedBox(height: 12),
        ],
        OutlinedButton.icon(
          onPressed: aiState.saving || aiState.presets.isEmpty ? null : () => _openPresets(context, ref, aiState.presets),
          icon: const Icon(Icons.auto_awesome_outlined),
          label: const Text('إضافة موظف من قالب'),
        ),
        const SizedBox(height: 8),
        OutlinedButton.icon(
          onPressed: aiState.saving ? null : () => _openEditor(context, ref),
          icon: const Icon(Icons.add),
          label: const Text('إنشاء موظف جديد'),
        ),
        const SizedBox(height: 24),
        _RealWorkersSection(),
      ],
    );
  }

  void _openPresets(BuildContext context, WidgetRef ref, List<AiEmployeePresetModel> presets) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: HasimTokens.pageBg(context),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (sheetContext) => SafeArea(
        child: ListView(
          shrinkWrap: true,
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
          children: [
            Text('اختر قالبًا', style: _aiTitle(sheetContext)),
            const SizedBox(height: 4),
            Text('القالب نقطة بداية فقط، ويمكنك تعديل الموظف بعد إنشائه.', style: _aiMuted(sheetContext)),
            const SizedBox(height: 12),
            for (final preset in presets) ...[
              _SectionCard(
                child: InkWell(
                  key: ValueKey('ai-preset-${preset.key}'),
                  onTap: () async {
                    Navigator.pop(sheetContext);
                    final ok = await ref.read(aiEmployeesControllerProvider.notifier).createFromPreset(preset.key);
                    if (ok && context.mounted) {
                      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تمت إضافة الموظف')));
                    }
                  },
                  child: Row(
                    children: [
                      _EmployeeAvatar(avatarKey: preset.avatarKey),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(preset.displayName, style: _aiTitle(sheetContext)),
                            if (preset.summary != null) Text(preset.summary!, style: _aiMuted(sheetContext)),
                          ],
                        ),
                      ),
                      const Icon(Icons.add_circle_outline),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 8),
            ],
          ],
        ),
      ),
    );
  }

  void _openEditor(BuildContext context, WidgetRef ref, {AiEmployeeModel? employee}) {
    final state = ref.read(aiEmployeesControllerProvider);
    final availableRoles = widget.roles.isEmpty ? const ['general_business_assistant'] : widget.roles;
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: HasimTokens.pageBg(context),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (sheetContext) => _EmployeeEditorSheet(
        title: employee == null ? 'موظف جديد' : 'تعديل ${employee.name}',
        initial: employee == null
            ? AiEmployeeDraft(name: '', role: availableRoles.first)
            : AiEmployeeDraft.fromEmployee(employee),
        roles: availableRoles,
        options: state.options,
        onSubmit: (draft) async {
          Navigator.pop(sheetContext);
          final aiController = ref.read(aiEmployeesControllerProvider.notifier);
    final discoveryState = ref.watch(companyWorkerDiscoveryControllerProvider);
    final discoveryController = ref.read(companyWorkerDiscoveryControllerProvider.notifier);
          final ok = employee == null ? await controller.create(draft) : await controller.update(employee.id, draft);
          if (ok && context.mounted) {
            ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم حفظ الموظف')));
          }
        },
      ),
    );
  }

  Future<void> _rename(BuildContext context, WidgetRef ref, AiEmployeeModel employee) async {
    final name = TextEditingController(text: employee.name);
    final next = await showDialog<String>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text('إعادة التسمية', style: _aiTitle(dialogContext)),
        content: TextField(
          controller: name,
          autofocus: true,
          maxLength: AiEmployeeModel.maxNameLength,
          decoration: const InputDecoration(labelText: 'اسم الموظف'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialogContext), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(dialogContext, name.text.trim()), child: const Text('حفظ')),
        ],
      ),
    );
    name.dispose();
    if (next == null || next.isEmpty || next == employee.name) return;
    await ref.read(aiEmployeesControllerProvider.notifier).rename(employee.id, next);
  }

  Future<void> _confirmDelete(BuildContext context, WidgetRef ref, AiEmployeeModel employee) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text('حذف ${employee.name}', style: _aiTitle(dialogContext)),
        content: Text('سيُحذف الموظف من القائمة. لا يمكن حذف الموظف الافتراضي.', style: _aiTitle(dialogContext)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialogContext, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(dialogContext, true), child: const Text('حذف')),
        ],
      ),
    );
    if (confirmed == true) {
      await ref.read(aiEmployeesControllerProvider.notifier).delete(employee.id);
    }
  }

  void _openTest(BuildContext context, AiEmployeeModel employee) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: HasimTokens.cardBg(context),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (_) => _EmployeeTestSheet(employee: employee),
    );
  }
}

