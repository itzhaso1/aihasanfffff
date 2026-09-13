import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class WorkspaceSelectScreen extends ConsumerWidget {
  const WorkspaceSelectScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    return Scaffold(
      appBar: AppBar(
        title: Text(l.switchWorkspace),
        actions: [
          TextButton(onPressed: () => ref.read(authControllerProvider.notifier).logout(), child: Text(l.logout)),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Text(l.selectWorkspaceHint, style: Theme.of(context).textTheme.bodyLarge),
          const SizedBox(height: 16),
          for (final workspace in auth.workspaces)
            Card(
              child: ListTile(
                title: Text(workspace.name),
                subtitle: Text(workspace.financeEnabled ? l.financeEnabled : l.financeDisabled),
                trailing: const Icon(Icons.arrow_forward_ios, size: 16),
                onTap: () async {
                  try {
                    await ref.read(authControllerProvider.notifier).switchWorkspace(workspace.id);
                  } on ApiException catch (e) {
                    if (context.mounted) {
                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
                    }
                  }
                },
              ),
            ),
        ],
      ),
    );
  }
}

class FinanceUnavailableScreen extends ConsumerWidget {
  const FinanceUnavailableScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    return Scaffold(
      appBar: AppBar(title: Text(l.appName)),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 480),
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.lock_outline, size: 48, color: Color(0xFF06C2A4)),
                const SizedBox(height: 16),
                Text(l.financeUnavailableTitle, style: Theme.of(context).textTheme.titleLarge, textAlign: TextAlign.center),
                const SizedBox(height: 8),
                Text(
                  auth.error ?? l.financeUnavailableBody,
                  textAlign: TextAlign.center,
                ),
                if (auth.workspace != null) ...[
                  const SizedBox(height: 8),
                  Text(auth.workspace!.name, style: Theme.of(context).textTheme.titleMedium),
                ],
                const SizedBox(height: 24),
                if (auth.workspaces.length > 1)
                  FilledButton(
                    onPressed: () => ref.read(authControllerProvider.notifier).requestWorkspaceSelection(),
                    child: Text(l.switchWorkspace),
                  ),
                const SizedBox(height: 8),
                OutlinedButton(
                  onPressed: () => ref.read(authControllerProvider.notifier).logout(),
                  child: Text(l.logout),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
