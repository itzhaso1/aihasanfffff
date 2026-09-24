import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/settings/data/agent_persona_model.dart';
import 'package:hasim/features/settings/data/ai_setting_model.dart';
import 'package:hasim/features/settings/providers/ai_settings_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';

class AiSettingsScreen extends ConsumerStatefulWidget {
  const AiSettingsScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  ConsumerState<AiSettingsScreen> createState() => _AiSettingsScreenState();
}

class _AiSettingsScreenState extends ConsumerState<AiSettingsScreen>
    with SingleTickerProviderStateMixin {
  AiSettingModel? _draft;
  late final TabController _tabs;
  late final TextEditingController _name;
  late final TextEditingController _instructions;
  late final TextEditingController _tone;
  late final TextEditingController _identityName;
  late final TextEditingController _identityRole;
  late final TextEditingController _rules;

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: 2, vsync: this);
    _name = TextEditingController();
    _instructions = TextEditingController();
    _tone = TextEditingController();
    _identityName = TextEditingController();
    _identityRole = TextEditingController();
    _rules = TextEditingController();
  }

  @override
  void dispose() {
    _tabs.dispose();
    _name.dispose();
    _instructions.dispose();
    _tone.dispose();
    _identityName.dispose();
    _identityRole.dispose();
    _rules.dispose();
    super.dispose();
  }

  void _applyModel(AiSettingModel? model) {
    _draft = model;
    _name.text = model?.name ?? '';
    _instructions.text = model?.instructions ?? '';
    _tone.text = model?.tone ?? '';
    _identityName.text = model?.agentPersona.identityName ?? '';
    _identityRole.text = model?.agentPersona.identityRole ?? '';
    _rules.text = (model?.agentPersona.behavioralRules ?? const []).join('\n');
  }

  Future<void> _save() async {
    final base = _draft;
    if (base == null) return;
    final persona = base.agentPersona.copyWith(
      identityName: _identityName.text.trim(),
      identityRole: _identityRole.text.trim(),
      behavioralRules: _rules.text
          .split('\n')
          .map((e) => e.trim())
          .where((e) => e.isNotEmpty)
          .toList(),
    );
    final draft = base.copyWith(
      name: _name.text.trim(),
      instructions: _instructions.text.trim(),
      tone: _tone.text.trim(),
      agentPersona: persona,
    );
    final ok = await ref.read(aiSettingsControllerProvider.notifier).save(draft);
    if (!mounted) return;
    if (ok) {
      setState(() => _applyModel(ref.read(aiSettingsControllerProvider).settings));
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(AppLocalizations.of(context).settingsSaved)),
      );
    } else {
      final err = ref.read(aiSettingsControllerProvider).error;
      if (err != null) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(err)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final state = ref.watch(aiSettingsControllerProvider);
    ref.listen(aiSettingsControllerProvider.select((s) => s.settings), (previous, next) {
      if (next?.id != _draft?.id || next == null) {
        setState(() => _applyModel(next));
      }
    });

    final tabs = [
      Tab(text: l10n.aiTabGeneral),
      Tab(text: l10n.aiTabPersona),
    ];

    final body = state.loading
          ? const Center(child: CircularProgressIndicator())
          : state.error != null && _draft == null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(state.error!),
                      TextButton(
                        onPressed: () => ref.read(aiSettingsControllerProvider.notifier).reload(),
                        child: Text(l10n.retry),
                      ),
                    ],
                  ),
                )
              : _draft == null
                  ? Center(child: Text(l10n.aiSettingsUnavailable))
                  : Column(
                      children: [
                        if (widget.embedded)
                          Material(
                            color: HasimTokens.cardBg(context),
                            child: TabBar(controller: _tabs, tabs: tabs),
                          ),
                        Expanded(
                          child: TabBarView(
                            controller: _tabs,
                            children: [
                              ListView(
                                padding: const EdgeInsets.all(16),
                                children: [
                                  TextField(
                                    controller: _name,
                                    decoration: InputDecoration(labelText: l10n.aiFieldAssistantName),
                                  ),
                                  const SizedBox(height: 12),
                                  DropdownButtonFormField<String>(
                                    value: (state.aiRoles.contains(_draft!.aiRole)
                                            ? _draft!.aiRole
                                            : (state.aiRoles.isNotEmpty ? state.aiRoles.first : _draft!.aiRole)) ??
                                        'general_business_assistant',
                                    decoration: InputDecoration(labelText: l10n.aiFieldRole),
                                    items: [
                                      for (final role in (state.aiRoles.isEmpty
                                          ? const ['general_business_assistant']
                                          : state.aiRoles))
                                        DropdownMenuItem(value: role, child: Text(role)),
                                    ],
                                    onChanged: (v) => setState(() => _draft = _draft!.copyWith(aiRole: v)),
                                  ),
                                  const SizedBox(height: 12),
                                  TextField(
                                    controller: _instructions,
                                    decoration: InputDecoration(labelText: l10n.aiFieldInstructions),
                                    maxLines: 4,
                                  ),
                                  const SizedBox(height: 12),
                                  TextField(
                                    controller: _tone,
                                    decoration: InputDecoration(labelText: l10n.aiFieldTone),
                                  ),
                                  SwitchListTile(
                                    title: Text(l10n.aiFieldActive),
                                    value: _draft!.isActive,
                                    onChanged: (v) => setState(() => _draft = _draft!.copyWith(isActive: v)),
                                  ),
                                  const SizedBox(height: 16),
                                  FilledButton(
                                    onPressed: state.saving ? null : _save,
                                    child: Text(state.saving ? l10n.loading : l10n.save),
                                  ),
                                ],
                              ),
                              _PersonaTab(
                                persona: _draft!.agentPersona,
                                catalog: state.catalog,
                                identityName: _identityName,
                                identityRole: _identityRole,
                                rules: _rules,
                                saving: state.saving,
                                onChanged: (p) => setState(() => _draft = _draft!.copyWith(agentPersona: p)),
                                onSave: _save,
                              ),
                            ],
                          ),
                        ),
                      ],
                    );

    if (!widget.embedded && HasimLayout.isCompact(context)) {
      return HasimMobileScaffold(
        title: l10n.aiSettingsTitle,
        appBarBottom: TabBar(controller: _tabs, tabs: tabs),
        body: body,
      );
    }

    return Scaffold(
      backgroundColor: widget.embedded ? Colors.transparent : HasimTokens.pageBg(context),
      appBar: widget.embedded
          ? null
          : AppBar(
              title: Text(l10n.aiSettingsTitle),
              bottom: TabBar(controller: _tabs, tabs: tabs),
            ),
      body: body,
    );
  }
}

class _PersonaTab extends StatelessWidget {
  const _PersonaTab({
    required this.persona,
    required this.catalog,
    required this.identityName,
    required this.identityRole,
    required this.rules,
    required this.saving,
    required this.onChanged,
    required this.onSave,
  });

  final AgentPersonaModel persona;
  final AgentPersonaCatalog catalog;
  final TextEditingController identityName;
  final TextEditingController identityRole;
  final TextEditingController rules;
  final bool saving;
  final ValueChanged<AgentPersonaModel> onChanged;
  final VoidCallback onSave;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    String pick(String current, List<String> allowed) =>
        allowed.contains(current) ? current : (allowed.isNotEmpty ? allowed.first : current);

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        TextField(
          controller: identityName,
          decoration: InputDecoration(labelText: l10n.aiPersonaName),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: identityRole,
          decoration: InputDecoration(labelText: l10n.aiPersonaRole),
        ),
        const SizedBox(height: 16),
        Text(l10n.aiPersonaTraits, style: Theme.of(context).textTheme.titleMedium),
        Wrap(
          spacing: 8,
          children: [
            for (final trait in catalog.traits)
              FilterChip(
                label: Text(trait),
                selected: persona.traits.contains(trait),
                onSelected: (selected) {
                  final next = [...persona.traits];
                  if (selected) {
                    if (next.length >= catalog.maxTraits) return;
                    next.add(trait);
                  } else {
                    next.remove(trait);
                  }
                  onChanged(persona.copyWith(traits: next));
                },
              ),
          ],
        ),
        const SizedBox(height: 16),
        DropdownButtonFormField<String>(
          value: pick(persona.language, catalog.languages),
          decoration: InputDecoration(labelText: l10n.aiPersonaLanguage),
          items: [for (final v in catalog.languages) DropdownMenuItem(value: v, child: Text(v))],
          onChanged: (v) => onChanged(persona.copyWith(language: v)),
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          value: pick(persona.dialect, catalog.dialects),
          decoration: InputDecoration(labelText: l10n.aiPersonaDialect),
          items: [for (final v in catalog.dialects) DropdownMenuItem(value: v, child: Text(v))],
          onChanged: (v) => onChanged(persona.copyWith(dialect: v)),
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          value: pick(persona.formality, catalog.formality),
          decoration: InputDecoration(labelText: l10n.aiPersonaFormality),
          items: [for (final v in catalog.formality) DropdownMenuItem(value: v, child: Text(v))],
          onChanged: (v) => onChanged(persona.copyWith(formality: v)),
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          value: pick(persona.warmth, catalog.warmth),
          decoration: InputDecoration(labelText: l10n.aiPersonaWarmth),
          items: [for (final v in catalog.warmth) DropdownMenuItem(value: v, child: Text(v))],
          onChanged: (v) => onChanged(persona.copyWith(warmth: v)),
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          value: pick(persona.verbosity, catalog.verbosity),
          decoration: InputDecoration(labelText: l10n.aiPersonaVerbosity),
          items: [for (final v in catalog.verbosity) DropdownMenuItem(value: v, child: Text(v))],
          onChanged: (v) => onChanged(persona.copyWith(verbosity: v)),
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          value: pick(persona.emojiUsage, catalog.emojiUsage),
          decoration: InputDecoration(labelText: l10n.aiPersonaEmoji),
          items: [for (final v in catalog.emojiUsage) DropdownMenuItem(value: v, child: Text(v))],
          onChanged: (v) => onChanged(persona.copyWith(emojiUsage: v)),
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          value: pick(persona.greetingStyle, catalog.greetingStyles),
          decoration: InputDecoration(labelText: l10n.aiPersonaGreeting),
          items: [for (final v in catalog.greetingStyles) DropdownMenuItem(value: v, child: Text(v))],
          onChanged: (v) => onChanged(persona.copyWith(greetingStyle: v)),
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          value: pick(persona.salesStyle, catalog.salesStyles),
          decoration: InputDecoration(labelText: l10n.aiPersonaSales),
          items: [for (final v in catalog.salesStyles) DropdownMenuItem(value: v, child: Text(v))],
          onChanged: (v) => onChanged(persona.copyWith(salesStyle: v)),
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          value: pick(persona.supportStyle, catalog.supportStyles),
          decoration: InputDecoration(labelText: l10n.aiPersonaSupport),
          items: [for (final v in catalog.supportStyles) DropdownMenuItem(value: v, child: Text(v))],
          onChanged: (v) => onChanged(persona.copyWith(supportStyle: v)),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: rules,
          decoration: InputDecoration(labelText: l10n.aiPersonaRules, alignLabelWithHint: true),
          maxLines: 6,
        ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: saving ? null : onSave,
          child: Text(saving ? l10n.loading : l10n.save),
        ),
      ],
    );
  }
}
