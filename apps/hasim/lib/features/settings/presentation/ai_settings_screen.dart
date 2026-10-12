import 'dart:async';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/settings/data/ai_choice_labels.dart';
import 'package:hasim/features/settings/data/ai_employee_model.dart';
import 'package:hasim/features/settings/data/company_worker_discovery_repository.dart';
import 'package:hasim/features/settings/data/ai_setting_model.dart';
import 'package:hasim/features/settings/data/worker_offer_duration.dart';
import 'package:hasim/features/settings/providers/ai_employees_controller.dart';
import 'package:hasim/features/settings/presentation/ai_skills_sheet.dart';
import 'package:hasim/features/settings/presentation/company_worker_engagement_capabilities_screen.dart';
import 'package:hasim/features/settings/providers/ai_settings_controller.dart';
import 'package:hasim/features/settings/providers/company_worker_discovery_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';

part 'ai_employees_tab.dart';
part 'company_workers_section.dart';

TextStyle _aiTitle(BuildContext context) => GoogleFonts.cairo(
  fontSize: 14,
  fontWeight: FontWeight.w600,
  height: 1.2,
  color: HasimTokens.inkColor(context),
);

TextStyle _aiMuted(BuildContext context) => GoogleFonts.cairo(
  fontSize: 11,
  fontWeight: FontWeight.w500,
  height: 1.2,
  color: HasimTokens.mutedColor(context),
);

TabBar _aiTabs(
  BuildContext context,
  TabController controller,
  List<Widget> tabs,
) {
  final label = GoogleFonts.cairo(
    fontSize: 13,
    fontWeight: FontWeight.w600,
    height: 1.35,
  );
  return TabBar(
    controller: controller,
    tabs: [
      for (final tab in tabs)
        Tab(
          child: FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              tab is Tab ? (tab.text ?? '') : '',
              maxLines: 1,
              style: label,
            ),
          ),
        ),
    ],
    labelPadding: const EdgeInsets.symmetric(horizontal: 6),
    labelColor: HasimTokens.inkColor(context),
    unselectedLabelColor: HasimTokens.mutedColor(context),
    indicatorColor: HasimTokens.primary,
    indicatorSize: TabBarIndicatorSize.tab,
    dividerColor: HasimTokens.lineColor(context),
  );
}

class AiSettingsScreen extends ConsumerStatefulWidget {
  const AiSettingsScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  ConsumerState<AiSettingsScreen> createState() => _AiSettingsScreenState();
}

class _AiSettingsScreenState extends ConsumerState<AiSettingsScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs;
  late final TextEditingController _name;
  late final TextEditingController _instructions;
  late final TextEditingController _experience;
  int? _boundEmployeeId;
  AiEmployeeDraft? _employeeDraft;
  String? _nameError;

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: 3, vsync: this);
    _name = TextEditingController();
    _instructions = TextEditingController();
    _experience = TextEditingController();
    _tabs.addListener(_dismissOverlays);
  }

  void _dismissOverlays() {
    FocusManager.instance.primaryFocus?.unfocus();
  }

  @override
  void dispose() {
    _tabs.removeListener(_dismissOverlays);
    _tabs.dispose();
    _name.dispose();
    _instructions.dispose();
    _experience.dispose();
    super.dispose();
  }

  void _bindEmployee(AiEmployeeModel? employee) {
    if (employee == null) {
      _boundEmployeeId = null;
      _employeeDraft = null;
      _name.text = '';
      _instructions.text = '';
      _experience.text = '';
      _nameError = null;
      return;
    }
    if (_boundEmployeeId == employee.id && _employeeDraft != null) {
      return;
    }
    _boundEmployeeId = employee.id;
    _employeeDraft = AiEmployeeDraft.fromEmployee(employee);
    _name.text = employee.name;
    _instructions.text = employee.instructions ?? '';
    _experience.text = employee.experienceNotes ?? '';
    _nameError = null;
  }

  void _selectEmployee(int id) {
    ref.read(aiEmployeesControllerProvider.notifier).select(id);
    _tabs.animateTo(0);
  }

  AiEmployeeDraft? _composedEmployeeDraft() {
    final base = _employeeDraft;
    if (base == null) return null;
    return AiEmployeeDraft(
      name: _name.text.trim(),
      role: base.role,
      jobTitle: base.jobTitle,
      instructions: _instructions.text,
      experienceNotes: _experience.text,
      language: base.language,
      dialect: base.dialect,
      formality: base.formality,
      tone: base.tone,
      emojiPolicy: base.emojiPolicy,
      traits: base.traits,
      responseLength: base.responseLength,
      temperature: base.temperature,
    );
  }

  Future<void> _save() async {
    final employee = ref.read(aiEmployeesControllerProvider).selected;
    final draft = _composedEmployeeDraft();
    if (employee == null || draft == null) return;
    final l10n = AppLocalizations.of(context);
    final nameError = AiSettingModel.assistantNameError(draft.name);
    if (nameError != null) {
      setState(() => _nameError = l10n.aiFieldAssistantNameInvalid);
      return;
    }
    setState(() => _nameError = null);
    final ok = await ref
        .read(aiEmployeesControllerProvider.notifier)
        .update(employee.id, draft);
    if (!mounted) return;
    if (ok) {
      _boundEmployeeId = null;
      _bindEmployee(ref.read(aiEmployeesControllerProvider).selected);
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('تم حفظ الموظف بنجاح')));
    } else {
      final err = ref.read(aiEmployeesControllerProvider).error;
      if (err != null) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(err)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final settings = ref.watch(aiSettingsControllerProvider);
    final employees = ref.watch(aiEmployeesControllerProvider);
    ref.listen(aiEmployeesControllerProvider.select((s) => s.selectedId), (
      previous,
      next,
    ) {
      setState(() {
        _boundEmployeeId = null;
        _bindEmployee(ref.read(aiEmployeesControllerProvider).selected);
      });
    });
    if (_boundEmployeeId != employees.selectedId) {
      _bindEmployee(employees.selected);
    }

    final tabs = [
      Tab(text: l10n.aiTabGeneral),
      const Tab(text: 'موظفو الذكاء الاصطناعي'),
      const Tab(text: 'التعاقد مع موظفين حقيقيين'),
    ];

    final loading =
        (settings.loading && settings.settings == null) &&
        (employees.loading && employees.employees.isEmpty);
    final body = loading
        ? const Center(child: CircularProgressIndicator())
        : Column(
            children: [
              if (widget.embedded)
                Material(
                  color: HasimTokens.cardBg(context),
                  child: _aiTabs(context, _tabs, tabs),
                ),
              Expanded(
                child: TabBarView(
                  controller: _tabs,
                  children: [
                    _GeneralTab(
                      employee: employees.selected,
                      draft: _composedEmployeeDraft(),
                      aiRoles: settings.aiRoles,
                      name: _name,
                      instructions: _instructions,
                      experience: _experience,
                      nameError: _nameError,
                      saving: employees.saving,
                      onChanged: (next) =>
                          setState(() => _employeeDraft = next),
                      onSave: _save,
                      onOpenTest: () {
                        final id = employees.selectedId;
                        if (id == null) return;
                        context.push('/ai-test', extra: id);
                      },
                    ),
                    _EmployeesTab(
                      roles: settings.aiRoles,
                      onSelectEmployee: _selectEmployee,
                    ),
                    const _RealWorkersSection(),
                  ],
                ),
              ),
            ],
          );

    if (!widget.embedded && HasimLayout.isCompact(context)) {
      return HasimMobileScaffold(
        title: l10n.aiSettingsTitle,
        appBarBottom: _aiTabs(context, _tabs, tabs),
        body: body,
      );
    }

    return Scaffold(
      backgroundColor: widget.embedded
          ? Colors.transparent
          : HasimTokens.pageBg(context),
      appBar: widget.embedded
          ? null
          : AppBar(
              title: Text(l10n.aiSettingsTitle, style: _aiTitle(context)),
              bottom: _aiTabs(context, _tabs, tabs),
            ),
      body: body,
    );
  }
}

class _GeneralTab extends StatelessWidget {
  const _GeneralTab({
    required this.employee,
    required this.draft,
    required this.aiRoles,
    required this.name,
    required this.instructions,
    required this.experience,
    required this.nameError,
    required this.saving,
    required this.onChanged,
    required this.onSave,
    required this.onOpenTest,
  });

  final AiEmployeeModel? employee;
  final AiEmployeeDraft? draft;
  final List<String> aiRoles;
  final TextEditingController name;
  final TextEditingController instructions;
  final TextEditingController experience;
  final String? nameError;
  final bool saving;
  final ValueChanged<AiEmployeeDraft> onChanged;
  final VoidCallback onSave;
  final VoidCallback onOpenTest;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final selected = employee;
    final current = draft;
    if (selected == null || current == null) {
      return ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
        children: [
          _SectionCard(
            title: 'الموظف',
            child: Text(
              'اختر موظفاً من تبويب الموظفين ليظهر هنا.',
              key: const ValueKey('ai-general-empty'),
              style: _aiMuted(context),
            ),
          ),
        ],
      );
    }

    final roles = aiRoles.isEmpty
        ? const ['general_business_assistant']
        : aiRoles;
    final role = roles.contains(current.role) ? current.role : roles.first;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
      children: [
        _SectionCard(
          title: 'الموظف',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                selected.name,
                key: const ValueKey('ai-general-employee-heading'),
                style: _aiTitle(context),
              ),
              const SizedBox(height: 12),
              TextField(
                key: const ValueKey('ai-general-employee-name'),
                controller: name,
                maxLength: AiEmployeeModel.maxNameLength,
                style: _aiTitle(context),
                decoration: InputDecoration(
                  labelText: 'اسم الموظف',
                  hintText: 'محمد',
                  errorText: nameError,
                ),
              ),
              const SizedBox(height: 12),
              _AiSelectField(
                label: l10n.aiFieldRole,
                value: role,
                items: roles,
                onChanged: (v) {
                  if (v == null) return;
                  onChanged(current.copyWith(role: v));
                },
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        _SectionCard(
          title: 'التعليمات',
          child: Column(
            children: [
              TextField(
                key: const ValueKey('ai-general-employee-instructions'),
                controller: instructions,
                style: _aiTitle(context),
                decoration: const InputDecoration(
                  labelText: 'تعليمات الموظف',
                  alignLabelWithHint: true,
                  hintText:
                      'اكتب أسلوب الرد والحدود التي يجب أن يلتزم بها الموظف.',
                ),
                maxLines: 5,
              ),
              const SizedBox(height: 12),
              TextField(
                key: const ValueKey('ai-general-employee-experience'),
                controller: experience,
                style: _aiTitle(context),
                decoration: const InputDecoration(
                  labelText: 'الخبرة',
                  hintText: 'خبرة 3 سنوات في المبيعات وخدمة العملاء',
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        _SectionCard(
          title: 'أسلوب الرد',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Text('درجة الإبداع', style: _aiTitle(context)),
                  const Spacer(),
                  Text(
                    current.temperature.toStringAsFixed(2),
                    style: _aiMuted(context),
                  ),
                ],
              ),
              Slider(
                value: current.temperature.clamp(0, 1).toDouble(),
                min: 0,
                max: 1,
                divisions: 20,
                label: current.temperature.toStringAsFixed(2),
                onChanged: (v) => onChanged(current.copyWith(temperature: v)),
              ),
              const SizedBox(height: 8),
              Text('طول الرد', style: _aiTitle(context)),
              const SizedBox(height: 8),
              LayoutBuilder(
                builder: (context, constraints) {
                  if (constraints.maxWidth < 360) {
                    return Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        for (final option in const [
                          ('short', 'قصير'),
                          ('medium', 'متوسط'),
                          ('long', 'طويل'),
                        ])
                          ChoiceChip(
                            label: Text(option.$2),
                            selected: current.responseLength == option.$1,
                            onSelected: (_) => onChanged(
                              current.copyWith(responseLength: option.$1),
                            ),
                          ),
                      ],
                    );
                  }
                  return SegmentedButton<String>(
                    showSelectedIcon: constraints.maxWidth >= 420,
                    segments: const [
                      ButtonSegment(value: 'short', label: Text('قصير')),
                      ButtonSegment(value: 'medium', label: Text('متوسط')),
                      ButtonSegment(value: 'long', label: Text('طويل')),
                    ],
                    selected: {current.responseLength},
                    onSelectionChanged: (value) => onChanged(
                      current.copyWith(responseLength: value.first),
                    ),
                  );
                },
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: onOpenTest,
          icon: const Icon(Icons.science_outlined),
          label: const Text('اختبار الذكاء الاصطناعي'),
        ),
        const SizedBox(height: 12),
        SizedBox(
          height: 48,
          child: FilledButton(
            onPressed: saving ? null : onSave,
            child: Text(saving ? l10n.loading : 'حفظ التغييرات'),
          ),
        ),
      ],
    );
  }
}

class _SectionCard extends StatelessWidget {
  const _SectionCard({this.title, required this.child});

  final String? title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: HasimTokens.cardBg(context),
      elevation: 0,
      shadowColor: Colors.transparent,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: BorderSide(color: HasimTokens.lineColor(context)),
      ),
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(20),
          boxShadow: HasimTokens.softShadow(context),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (title != null) ...[
              Text(title!, style: _aiTitle(context)),
              const SizedBox(height: 10),
            ],
            child,
          ],
        ),
      ),
    );
  }
}

class _TraitRow extends StatelessWidget {
  const _TraitRow({
    required this.traits,
    required this.selected,
    required this.maxTraits,
    required this.onChanged,
  });

  final List<String> traits;
  final List<String> selected;
  final int maxTraits;
  final ValueChanged<List<String>> onChanged;

  void _toggle(String trait) {
    final next = [...selected];
    if (next.contains(trait)) {
      next.remove(trait);
    } else {
      if (next.length >= maxTraits) return;
      next.add(trait);
    }
    onChanged(next);
  }

  @override
  Widget build(BuildContext context) {
    final visible = traits.take(3).toList();
    final hidden = traits.skip(3).toList();

    return Row(
      children: [
        for (var i = 0; i < visible.length; i++) ...[
          if (i > 0) const SizedBox(width: 6),
          Expanded(
            child: _EqualTraitChip(
              label: aiChoiceLabel(context, visible[i]),
              selected: selected.contains(visible[i]),
              onTap: () => _toggle(visible[i]),
            ),
          ),
        ],
        if (hidden.isNotEmpty) ...[
          const SizedBox(width: 6),
          PopupMenuButton<String>(
            tooltip: 'المزيد',
            onSelected: _toggle,
            itemBuilder: (context) => [
              for (final trait in hidden)
                PopupMenuItem<String>(
                  value: trait,
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          aiChoiceLabel(context, trait),
                          style: _aiTitle(context),
                        ),
                      ),
                      if (selected.contains(trait))
                        Icon(
                          Icons.check_rounded,
                          size: 18,
                          color: HasimTokens.accentDark,
                        ),
                    ],
                  ),
                ),
            ],
            child: const _EqualTraitChip(
              label: '',
              selected: false,
              arrow: true,
              width: 40,
            ),
          ),
        ],
      ],
    );
  }
}

class _EqualTraitChip extends StatelessWidget {
  const _EqualTraitChip({
    required this.label,
    required this.selected,
    this.onTap,
    this.arrow = false,
    this.width,
  });

  final String label;
  final bool selected;
  final VoidCallback? onTap;
  final bool arrow;
  final double? width;

  @override
  Widget build(BuildContext context) {
    final bg = selected
        ? HasimTokens.mintFill(context)
        : HasimTokens.cardBg(context);
    final fg = selected
        ? (HasimTokens.isDark(context) ? Colors.white : HasimTokens.accentDark)
        : HasimTokens.inkColor(context);
    return Material(
      color: bg,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: BorderSide(
          color: selected
              ? HasimTokens.mintChip
              : HasimTokens.lineColor(context),
        ),
      ),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: SizedBox(
          width: width,
          height: 36,
          child: Center(
            child: arrow
                ? Icon(
                    Icons.keyboard_arrow_down_rounded,
                    size: 20,
                    color: HasimTokens.mutedColor(context),
                  )
                : Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 6),
                    child: Text(
                      selected ? '$label ✓' : label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: _aiTitle(
                        context,
                      ).copyWith(fontSize: 12, color: fg),
                    ),
                  ),
          ),
        ),
      ),
    );
  }
}

class _AiSelectField extends StatelessWidget {
  const _AiSelectField({
    required this.value,
    required this.label,
    required this.items,
    required this.onChanged,
  });

  final String value;
  final String label;
  final List<String> items;
  final ValueChanged<String?>? onChanged;

  @override
  Widget build(BuildContext context) {
    final unique = <String>[];
    for (final item in items) {
      if (!unique.contains(item)) unique.add(item);
    }
    if (value.isNotEmpty && !unique.contains(value)) {
      unique.insert(0, value);
    }
    if (unique.isEmpty) {
      return InputDecorator(
        decoration: InputDecoration(labelText: label),
        child: const SizedBox(height: 24),
      );
    }
    final selected = unique.contains(value) ? value : unique.first;
    return InputDecorator(
      decoration: InputDecoration(
        labelText: label,
        contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      ),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<String>(
          value: selected,
          isExpanded: true,
          isDense: true,
          borderRadius: BorderRadius.circular(12),
          items: [
            for (final item in unique)
              DropdownMenuItem<String>(
                value: item,
                child: Text(
                  aiChoiceLabel(context, item),
                  overflow: TextOverflow.ellipsis,
                  style: _aiTitle(context),
                ),
              ),
          ],
          onChanged: onChanged,
        ),
      ),
    );
  }
}
