part of 'ai_settings_screen.dart';

class _EmployeesTab extends ConsumerWidget {
  const _EmployeesTab({required this.roles, required this.onSelectEmployee});

  final List<String> roles;
  final ValueChanged<int> onSelectEmployee;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(aiEmployeesControllerProvider);
    final controller = ref.read(aiEmployeesControllerProvider.notifier);

    if (state.loading && state.employees.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }
    if (state.error != null &&
        state.employees.isEmpty &&
        state.presets.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(state.error!, style: _aiTitle(context)),
            TextButton(
              onPressed: controller.reload,
              child: Text(AppLocalizations.of(context).retry),
            ),
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
        if (state.error != null) ...[
          const SizedBox(height: 8),
          Text(
            state.error!,
            style: _aiMuted(context).copyWith(color: HasimTokens.danger),
          ),
        ],
        const SizedBox(height: 12),
        if (state.employees.isEmpty)
          _SectionCard(
            child: Text(
              'لا يوجد موظفون بعد. أضف موظفًا من قالب أو أنشئ موظفًا جديدًا.',
              style: _aiTitle(context),
            ),
          ),
        for (final employee in state.employees) ...[
          _EmployeeCard(
            key: ValueKey('ai-employee-${employee.id}'),
            employee: employee,
            selected: employee.id == state.selectedId,
            busy: state.saving,
            onSelect: () => onSelectEmployee(employee.id),
            onSetDefault: () => controller.setDefault(employee.id),
            onToggleActive: () =>
                controller.setActive(employee.id, active: !employee.isActive),
            onEdit: () => _openEditor(context, ref, employee: employee),
            onRename: () => _rename(context, ref, employee),
            onTest: () => _openTest(context, employee),
            onSkills: () => _openSkills(context, ref, employee),
            onDelete: () => _confirmDelete(context, ref, employee),
          ),
          const SizedBox(height: 12),
        ],
        OutlinedButton.icon(
          onPressed: state.saving || state.presets.isEmpty
              ? null
              : () => _openPresets(context, ref, state.presets),
          icon: const Icon(Icons.auto_awesome_outlined),
          label: const Text('إضافة موظف من قالب'),
        ),
        const SizedBox(height: 8),
        OutlinedButton.icon(
          onPressed: state.saving ? null : () => _openEditor(context, ref),
          icon: const Icon(Icons.add),
          label: const Text('إنشاء موظف جديد'),
        ),
        const SizedBox(height: 24),
      ],
    );
  }

  void _openPresets(
    BuildContext context,
    WidgetRef ref,
    List<AiEmployeePresetModel> presets,
  ) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: HasimTokens.pageBg(context),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (sheetContext) => SafeArea(
        child: ListView(
          shrinkWrap: true,
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
          children: [
            Text('اختر قالبًا', style: _aiTitle(sheetContext)),
            const SizedBox(height: 4),
            Text(
              'القالب نقطة بداية فقط، ويمكنك تعديل الموظف بعد إنشائه.',
              style: _aiMuted(sheetContext),
            ),
            const SizedBox(height: 12),
            for (final preset in presets) ...[
              _SectionCard(
                child: InkWell(
                  key: ValueKey('ai-preset-${preset.key}'),
                  onTap: () async {
                    Navigator.pop(sheetContext);
                    final ok = await ref
                        .read(aiEmployeesControllerProvider.notifier)
                        .createFromPreset(preset.key);
                    if (ok && context.mounted) {
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('تمت إضافة الموظف')),
                      );
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
                            Text(
                              preset.displayName,
                              style: _aiTitle(sheetContext),
                            ),
                            if (preset.summary != null)
                              Text(
                                preset.summary!,
                                style: _aiMuted(sheetContext),
                              ),
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

  void _openEditor(
    BuildContext context,
    WidgetRef ref, {
    AiEmployeeModel? employee,
  }) {
    final state = ref.read(aiEmployeesControllerProvider);
    final availableRoles = roles.isEmpty
        ? const ['general_business_assistant']
        : roles;
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: HasimTokens.pageBg(context),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (sheetContext) => _EmployeeEditorSheet(
        title: employee == null ? 'موظف جديد' : 'تعديل ${employee.name}',
        initial: employee == null
            ? AiEmployeeDraft(name: '', role: availableRoles.first)
            : AiEmployeeDraft.fromEmployee(employee),
        roles: availableRoles,
        options: state.options,
        onSubmit: (draft) async {
          Navigator.pop(sheetContext);
          final controller = ref.read(aiEmployeesControllerProvider.notifier);
          final ok = employee == null
              ? await controller.create(draft)
              : await controller.update(employee.id, draft);
          if (ok && context.mounted) {
            ScaffoldMessenger.of(
              context,
            ).showSnackBar(const SnackBar(content: Text('تم حفظ الموظف')));
          }
        },
      ),
    );
  }

  Future<void> _rename(
    BuildContext context,
    WidgetRef ref,
    AiEmployeeModel employee,
  ) async {
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
          TextButton(
            onPressed: () => Navigator.pop(dialogContext),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, name.text.trim()),
            child: const Text('حفظ'),
          ),
        ],
      ),
    );
    name.dispose();
    if (next == null || next.isEmpty || next == employee.name) return;
    await ref
        .read(aiEmployeesControllerProvider.notifier)
        .rename(employee.id, next);
  }

  Future<void> _confirmDelete(
    BuildContext context,
    WidgetRef ref,
    AiEmployeeModel employee,
  ) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text('حذف ${employee.name}', style: _aiTitle(dialogContext)),
        content: Text(
          'سيُحذف الموظف من القائمة. لا يمكن حذف الموظف الافتراضي.',
          style: _aiTitle(dialogContext),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('حذف'),
          ),
        ],
      ),
    );
    if (confirmed == true) {
      await ref
          .read(aiEmployeesControllerProvider.notifier)
          .delete(employee.id);
    }
  }

  void _openTest(BuildContext context, AiEmployeeModel employee) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: HasimTokens.cardBg(context),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (_) => _EmployeeTestSheet(employee: employee),
    );
  }

  Future<void> _openSkills(
    BuildContext context,
    WidgetRef ref,
    AiEmployeeModel employee,
  ) {
    return showAiSkillsSheet(
      context: context,
      employeeName: employee.name,
      selectedKeys: employee.skills,
      load: () => ref.read(aiRepositoryProvider).fetchSkills(),
      save: (keys) => ref.read(aiEmployeesControllerProvider.notifier).setSkills(employee.id, keys),
    );
  }
}

class _EmployeeCard extends StatelessWidget {
  const _EmployeeCard({
    super.key,
    required this.employee,
    required this.selected,
    required this.busy,
    required this.onSelect,
    required this.onSetDefault,
    required this.onToggleActive,
    required this.onEdit,
    required this.onRename,
    required this.onTest,
    required this.onSkills,
    required this.onDelete,
  });

  final AiEmployeeModel employee;
  final bool selected;
  final bool busy;
  final VoidCallback onSelect;
  final VoidCallback onSetDefault;
  final VoidCallback onToggleActive;
  final VoidCallback onEdit;
  final VoidCallback onRename;
  final VoidCallback onTest;
  final VoidCallback onSkills;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    final dialect = employee.persona.dialect;
    return DecoratedBox(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        border: Border.all(
          color: selected ? HasimTokens.primary : Colors.transparent,
          width: 1.5,
        ),
      ),
      child: _SectionCard(
        child: InkWell(
          onTap: onSelect,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  _EmployeeAvatar(avatarKey: employee.avatarKey),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(employee.name, style: _aiTitle(context)),
                        Text(
                          [
                            employee.jobTitle ??
                                aiChoiceLabel(context, employee.role),
                            if (dialect != null && dialect != 'auto')
                              aiChoiceLabel(context, dialect),
                          ].join(' · '),
                          style: _aiMuted(context),
                        ),
                      ],
                    ),
                  ),
                  if (employee.isDefault) ...[
                    const _Pill(label: 'افتراضي', highlighted: true),
                    const SizedBox(width: 6),
                  ],
                  _Pill(
                    label: employee.isActive ? 'مفعّل' : 'موقوف',
                    highlighted: employee.isActive,
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  if (!employee.isDefault && employee.isActive)
                    FilledButton(
                      onPressed: busy ? null : onSetDefault,
                      child: const Text('تعيين كافتراضي'),
                    ),
                  OutlinedButton(
                    onPressed: busy ? null : onEdit,
                    child: const Text('تعديل'),
                  ),
                  OutlinedButton(
                    onPressed: onTest,
                    child: const Text('اختبار'),
                  ),
                  OutlinedButton(
                    key: Key('ai-employee-skills-${employee.id}'),
                    onPressed: busy ? null : onSkills,
                    child: const Text('المهارات'),
                  ),
                  PopupMenuButton<String>(
                    tooltip: 'المزيد',
                    enabled: !busy,
                    onSelected: (value) => switch (value) {
                      'rename' => onRename(),
                      'toggle' => onToggleActive(),
                      'delete' => onDelete(),
                      _ => null,
                    },
                    itemBuilder: (context) => [
                      const PopupMenuItem(
                        value: 'rename',
                        child: Text('إعادة التسمية'),
                      ),
                      PopupMenuItem(
                        value: 'toggle',
                        child: Text(employee.isActive ? 'إيقاف' : 'تفعيل'),
                      ),
                      PopupMenuItem(
                        value: 'delete',
                        enabled: !employee.isDefault,
                        child: const Text('حذف'),
                      ),
                    ],
                    child: const Padding(
                      padding: EdgeInsets.all(8),
                      child: Icon(Icons.more_horiz),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _EmployeeAvatar extends StatelessWidget {
  const _EmployeeAvatar({this.avatarKey});

  final String? avatarKey;

  @override
  Widget build(BuildContext context) {
    final fallback = Container(
      width: 56,
      height: 56,
      color: HasimTokens.mintChip,
      child: Icon(Icons.support_agent_outlined, color: HasimTokens.mutedColor(context)),
    );
    final key = avatarKey;
    return ClipRRect(
      borderRadius: BorderRadius.circular(16),
      child: key == null
          ? fallback
          : Image.asset(
              'assets/personas/$key.png',
              width: 56,
              height: 56,
              fit: BoxFit.cover,
              errorBuilder: (_, _, _) => fallback,
            ),
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill({required this.label, required this.highlighted});

  final String label;
  final bool highlighted;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: highlighted
            ? HasimTokens.mintChip
            : HasimTokens.lineColor(context),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        label,
        style: _aiMuted(context).copyWith(
          color: highlighted
              ? HasimTokens.accentDark
              : HasimTokens.mutedColor(context),
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}

class _EmployeeEditorSheet extends StatefulWidget {
  const _EmployeeEditorSheet({
    required this.title,
    required this.initial,
    required this.roles,
    required this.options,
    required this.onSubmit,
  });

  final String title;
  final AiEmployeeDraft initial;
  final List<String> roles;
  final AiEmployeeOptions options;
  final ValueChanged<AiEmployeeDraft> onSubmit;

  @override
  State<_EmployeeEditorSheet> createState() => _EmployeeEditorSheetState();
}

class _EmployeeEditorSheetState extends State<_EmployeeEditorSheet> {
  late final TextEditingController _name;
  late final TextEditingController _jobTitle;
  late final TextEditingController _instructions;
  late AiEmployeeDraft _draft;
  String? _nameError;

  @override
  void initState() {
    super.initState();
    _draft = widget.initial;
    _name = TextEditingController(text: _draft.name);
    _jobTitle = TextEditingController(text: _draft.jobTitle);
    _instructions = TextEditingController(text: _draft.instructions);
  }

  @override
  void dispose() {
    _name.dispose();
    _jobTitle.dispose();
    _instructions.dispose();
    super.dispose();
  }

  AiEmployeeDraft _with({
    String? role,
    String? language,
    String? dialect,
    String? formality,
    String? tone,
    String? emojiPolicy,
    List<String>? traits,
    String? responseLength,
    double? temperature,
  }) {
    return AiEmployeeDraft(
      name: _name.text,
      role: role ?? _draft.role,
      jobTitle: _jobTitle.text,
      instructions: _instructions.text,
      experienceNotes: _draft.experienceNotes,
      language: language ?? _draft.language,
      dialect: dialect ?? _draft.dialect,
      formality: formality ?? _draft.formality,
      tone: tone ?? _draft.tone,
      emojiPolicy: emojiPolicy ?? _draft.emojiPolicy,
      traits: traits ?? _draft.traits,
      responseLength: responseLength ?? _draft.responseLength,
      temperature: temperature ?? _draft.temperature,
    );
  }

  void _submit() {
    final draft = _with();
    if (AiSettingModel.assistantNameError(draft.name) != null) {
      setState(
        () => _nameError = AppLocalizations.of(
          context,
        ).aiFieldAssistantNameInvalid,
      );
      return;
    }
    widget.onSubmit(draft);
  }

  @override
  Widget build(BuildContext context) {
    final options = widget.options;
    final bottom = MediaQuery.viewInsetsOf(context).bottom;
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 12, 16, 16 + bottom),
      child: ListView(
        shrinkWrap: true,
        children: [
          Text(widget.title, style: _aiTitle(context)),
          const SizedBox(height: 12),
          TextField(
            key: const ValueKey('ai-employee-name'),
            controller: _name,
            maxLength: AiEmployeeModel.maxNameLength,
            decoration: InputDecoration(
              labelText: 'اسم الموظف',
              errorText: _nameError,
            ),
          ),
          TextField(
            controller: _jobTitle,
            decoration: const InputDecoration(labelText: 'المسمى الوظيفي'),
          ),
          const SizedBox(height: 8),
          _AiSelectField(
            label: AppLocalizations.of(context).aiFieldRole,
            value: _draft.role,
            items: widget.roles,
            onChanged: (v) => setState(() => _draft = _with(role: v)),
          ),
          const SizedBox(height: 8),
          _AiSelectField(
            label: AppLocalizations.of(context).aiPersonaLanguage,
            value: _draft.language,
            items: options.languages,
            onChanged: (v) => setState(() => _draft = _with(language: v)),
          ),
          const SizedBox(height: 8),
          _AiSelectField(
            label: AppLocalizations.of(context).aiPersonaDialect,
            value: _draft.dialect,
            items: options.dialects,
            onChanged: (v) => setState(() => _draft = _with(dialect: v)),
          ),
          const SizedBox(height: 8),
          _AiSelectField(
            label: AppLocalizations.of(context).aiPersonaFormality,
            value: _draft.formality,
            items: options.formality,
            onChanged: (v) => setState(() => _draft = _with(formality: v)),
          ),
          const SizedBox(height: 8),
          _AiSelectField(
            label: 'النبرة',
            value: _draft.tone,
            items: options.tones,
            onChanged: (v) => setState(() => _draft = _with(tone: v)),
          ),
          const SizedBox(height: 8),
          _AiSelectField(
            label: 'الإيموجي',
            value: _draft.emojiPolicy,
            items: options.emojiPolicies,
            onChanged: (v) => setState(() => _draft = _with(emojiPolicy: v)),
          ),
          const SizedBox(height: 12),
          Text(
            AppLocalizations.of(context).aiPersonaTraits,
            style: _aiTitle(context),
          ),
          const SizedBox(height: 8),
          _TraitRow(
            traits: options.traits,
            selected: _draft.traits,
            maxTraits: options.maxTraits,
            onChanged: (next) => setState(() => _draft = _with(traits: next)),
          ),
          const SizedBox(height: 12),
          Text('طول الرد', style: _aiTitle(context)),
          const SizedBox(height: 8),
          SegmentedButton<String>(
            segments: [
              for (final length in options.responseLengths)
                ButtonSegment(
                  value: length,
                  label: Text(aiChoiceLabel(context, length)),
                ),
            ],
            selected: {_draft.responseLength},
            onSelectionChanged: (value) =>
                setState(() => _draft = _with(responseLength: value.first)),
          ),
          const SizedBox(height: 8),
          Text(
            'درجة الإبداع ${_draft.temperature.toStringAsFixed(2)}',
            style: _aiTitle(context),
          ),
          Slider(
            value: _draft.temperature.clamp(0, 1).toDouble(),
            min: 0,
            max: 1,
            divisions: 20,
            onChanged: (v) => setState(() => _draft = _with(temperature: v)),
          ),
          TextField(
            controller: _instructions,
            maxLines: 5,
            maxLength: 4000,
            decoration: const InputDecoration(
              labelText: 'تعليمات الموظف',
              alignLabelWithHint: true,
            ),
          ),
          const SizedBox(height: 8),
          FilledButton(onPressed: _submit, child: const Text('حفظ الموظف')),
        ],
      ),
    );
  }
}

class _EmployeeTestSheet extends ConsumerStatefulWidget {
  const _EmployeeTestSheet({required this.employee});

  final AiEmployeeModel employee;

  @override
  ConsumerState<_EmployeeTestSheet> createState() => _EmployeeTestSheetState();
}

class _EmployeeTestSheetState extends ConsumerState<_EmployeeTestSheet> {
  final _message = TextEditingController(text: 'مرحبا، عرفني بنفسك');
  AiEmployeeTestResult? _result;
  bool _running = false;

  @override
  void dispose() {
    _message.dispose();
    super.dispose();
  }

  Future<void> _run() async {
    final message = _message.text.trim();
    if (message.isEmpty) return;
    setState(() => _running = true);
    final result = await ref
        .read(aiEmployeesControllerProvider.notifier)
        .test(widget.employee.id, message);
    if (!mounted) return;
    setState(() {
      _running = false;
      _result = result;
    });
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.viewInsetsOf(context).bottom;
    final result = _result;
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 12, 16, 16 + bottom),
      child: ListView(
        shrinkWrap: true,
        children: [
          Text('اختبار ${widget.employee.name}', style: _aiTitle(context)),
          const SizedBox(height: 6),
          Text(
            'وضع المعاينة: لا تُرسل أي رسالة لعميل ولا يُحفظ شيء.',
            style: _aiMuted(context),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _message,
            maxLength: 500,
            decoration: const InputDecoration(labelText: 'اكتب رسالة للاختبار'),
          ),
          const SizedBox(height: 8),
          FilledButton(
            onPressed: _running ? null : _run,
            child: Text(_running ? 'جارٍ الاختبار' : 'تشغيل الاختبار'),
          ),
          if (result != null) ...[
            const SizedBox(height: 12),
            for (final check in result.checks)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(check.label, style: _aiTitle(context)),
                    ),
                    if (check.elapsedMs > 0)
                      Text('${check.elapsedMs} ms', style: _aiMuted(context)),
                    const SizedBox(width: 8),
                    Text(
                      _previewCheckLabel(check),
                      style: _aiMuted(
                        context,
                      ).copyWith(color: _previewCheckColor(context, check)),
                    ),
                  ],
                ),
              ),
            const SizedBox(height: 12),
            Text(
              result.replied
                  ? result.text!
                  : (result.error ?? 'لا يتوفر رد في وضع المعاينة حاليًا.'),
              style: _aiTitle(context).copyWith(height: 1.5),
            ),
            if (result.tokens != null)
              Text('Tokens: ${result.tokens}', style: _aiMuted(context)),
          ],
        ],
      ),
    );
  }
}

String _previewCheckLabel(AiProbeCheck check) {
  if (check.warning && check.ok) return 'تحذير';
  return check.ok ? 'ناجح' : 'فشل';
}

Color _previewCheckColor(BuildContext context, AiProbeCheck check) {
  if (check.warning && check.ok) return const Color(0xFFB45309);
  return check.ok ? const Color(0xFF067E6B) : HasimTokens.danger;
}
