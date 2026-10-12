import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

class WorkspacePickerScreen extends ConsumerStatefulWidget {
  const WorkspacePickerScreen({super.key});

  @override
  ConsumerState<WorkspacePickerScreen> createState() => _WorkspacePickerScreenState();
}

class _WorkspacePickerScreenState extends ConsumerState<WorkspacePickerScreen> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final items = auth.workspaces.where((w) {
      if (_query.trim().isEmpty) return true;
      final q = _query.trim().toLowerCase();
      return w.name.toLowerCase().contains(q) || (w.type ?? '').toLowerCase().contains(q);
    }).toList();

    return Scaffold(
      appBar: AppBar(title: const Text('اختر مساحة العمل')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: TextField(
              decoration: const InputDecoration(
                hintText: 'بحث...',
                prefixIcon: Icon(Icons.search),
              ),
              onChanged: (v) => setState(() => _query = v),
            ),
          ),
          Expanded(
            child: ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: items.length,
              separatorBuilder: (_, _) => const SizedBox(height: 8),
              itemBuilder: (context, index) {
                final ws = items[index];
                final selected = auth.workspace?.id == ws.id;
                final initial = ws.name.isNotEmpty ? ws.name[0] : '?';
                return ListTile(
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14),
                    side: BorderSide(
                      color: selected ? Theme.of(context).colorScheme.primary : Colors.grey.shade300,
                    ),
                  ),
                  leading: CircleAvatar(
                    backgroundColor: Theme.of(context).colorScheme.primary.withValues(alpha: 0.15),
                    child: Text(
                      initial,
                      style: TextStyle(
                        color: Theme.of(context).colorScheme.primary,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                  title: Text(ws.name),
                  subtitle: Text(ws.type ?? 'مساحة عمل'),
                  trailing: selected
                      ? Icon(Icons.check_circle, color: Theme.of(context).colorScheme.primary)
                      : null,
                  onTap: () async {
                    final ok = await ref.read(authControllerProvider.notifier).switchWorkspace(ws);
                    if (!context.mounted) return;
                    if (ok) {
                      context.go('/conversations');
                      return;
                    }
                    final error = ref.read(authControllerProvider).error;
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(error ?? 'تعذر تبديل مساحة العمل.')),
                    );
                  },
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
