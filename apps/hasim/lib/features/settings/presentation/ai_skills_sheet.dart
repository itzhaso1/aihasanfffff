import 'package:flutter/material.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/settings/data/ai_employee_model.dart';

Future<void> showAiSkillsSheet({
  required BuildContext context,
  required String employeeName,
  required List<String> selectedKeys,
  required Future<List<AiSkillDefinition>> Function() load,
  required Future<bool> Function(List<String> keys) save,
}) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    builder: (context) => _AiSkillsSheet(
      employeeName: employeeName,
      selectedKeys: selectedKeys,
      load: load,
      save: save,
    ),
  );
}

class _AiSkillsSheet extends StatefulWidget {
  const _AiSkillsSheet({
    required this.employeeName,
    required this.selectedKeys,
    required this.load,
    required this.save,
  });

  final String employeeName;
  final List<String> selectedKeys;
  final Future<List<AiSkillDefinition>> Function() load;
  final Future<bool> Function(List<String> keys) save;

  @override
  State<_AiSkillsSheet> createState() => _AiSkillsSheetState();
}

class _AiSkillsSheetState extends State<_AiSkillsSheet> {
  bool _loading = true;
  bool _saving = false;
  String? _error;
  List<AiSkillDefinition> _skills = const [];
  late Set<String> _selected;
  AiSkillDefinition? _detail;

  @override
  void initState() {
    super.initState();
    _selected = widget.selectedKeys.toSet();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final skills = await widget.load();
      if (!mounted) return;
      setState(() {
        _skills = skills;
        _loading = false;
      });
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error.userMessage;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = 'تعذر تحميل المهارات.';
        _loading = false;
      });
    }
  }

  Future<void> _save() async {
    if (_saving) return;
    setState(() => _saving = true);
    final ok = await widget.save(_selected.toList());
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    final height = MediaQuery.sizeOf(context).height * 0.7;
    return SafeArea(
      child: SizedBox(
        height: height,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.all(16),
              child: Text('مهارات ${widget.employeeName}'),
            ),
            Expanded(child: _body()),
            if (_detail == null)
              Padding(
                padding: const EdgeInsets.all(16),
                child: FilledButton(
                  key: const Key('ai-skills-save'),
                  onPressed: _saving || _loading || _error != null ? null : _save,
                  child: const Text('حفظ المهارات'),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _body() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator(key: Key('ai-skills-loading')));
    }
    if (_error != null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(_error!, key: const Key('ai-skills-error')),
            TextButton(onPressed: _load, child: const Text('إعادة المحاولة')),
          ],
        ),
      );
    }
    if (_detail != null) {
      final skill = _detail!;
      return ListView(
        key: const Key('ai-skill-detail'),
        padding: const EdgeInsets.all(16),
        children: [
          TextButton(
            onPressed: () => setState(() => _detail = null),
            child: const Text('المهارات'),
          ),
          Text(skill.name, style: Theme.of(context).textTheme.titleLarge),
          if (skill.category != null) Text(skill.category!),
          if (skill.description != null) Text(skill.description!),
        ],
      );
    }
    if (_skills.isEmpty) {
      return const Center(child: Text('لا توجد مهارات', key: Key('ai-skills-empty')));
    }
    return ListView(
      key: const Key('ai-skills-list'),
      children: [
        for (final skill in _skills)
          CheckboxListTile(
            key: Key('ai-skill-${skill.key}'),
            value: _selected.contains(skill.key),
            title: Text(skill.name),
            subtitle: skill.category == null ? null : Text(skill.category!),
            onChanged: _saving
                ? null
                : (value) {
                    setState(() {
                      if (value == true) {
                        _selected.add(skill.key);
                      } else {
                        _selected.remove(skill.key);
                      }
                    });
                  },
            secondary: IconButton(
              key: Key('ai-skill-open-${skill.key}'),
              onPressed: () => setState(() => _detail = skill),
              icon: const Icon(Icons.info_outline),
            ),
          ),
      ],
    );
  }
}
