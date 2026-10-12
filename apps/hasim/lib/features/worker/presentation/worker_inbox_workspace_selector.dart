import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/worker/presentation/worker_chat_screen.dart';
import 'package:hasim/features/worker/providers/worker_inbox_workspace_controller.dart';

/// Company selector bound to the worker Inbox state (sidebar and phone Settings).
class WorkerInboxWorkspacePicker extends ConsumerWidget {
  const WorkerInboxWorkspacePicker({
    super.key,
    required this.newMessagesCount,
    this.iconOnly = false,
  });

  final int newMessagesCount;
  final bool iconOnly;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final workspaceState = ref.watch(workerInboxWorkspaceControllerProvider);
    final controller = ref.read(
      workerInboxWorkspaceControllerProvider.notifier,
    );
    return WorkerInboxWorkspaceSelector(
      workspaces: workspaceState.workspaces,
      loading: workspaceState.loading,
      changing: workspaceState.changing,
      error: workspaceState.error,
      selectedWorkspaceId: ref.watch(activeWorkspaceIdProvider),
      newMessagesCount: newMessagesCount,
      iconOnly: iconOnly,
      onSelected: controller.select,
      onRetry: () => controller.load(force: true),
    );
  }
}

class WorkerInboxWorkspaceSelector extends StatelessWidget {
  const WorkerInboxWorkspaceSelector({
    super.key,
    required this.workspaces,
    required this.loading,
    required this.changing,
    required this.error,
    required this.selectedWorkspaceId,
    required this.newMessagesCount,
    required this.onSelected,
    required this.onRetry,
    this.iconOnly = false,
  });

  final List<WorkerInboxWorkspace> workspaces;
  final bool loading;
  final bool changing;
  final String? error;
  final int? selectedWorkspaceId;
  final int newMessagesCount;
  final Future<void> Function(int workspaceId) onSelected;
  final Future<void> Function() onRetry;
  final bool iconOnly;

  @override
  Widget build(BuildContext context) {
    final selectedId =
        workspaces.any((workspace) => workspace.id == selectedWorkspaceId)
        ? selectedWorkspaceId
        : null;
    final unreadLabel = newMessagesCount == 0
        ? 'لا توجد محادثات جديدة'
        : '$newMessagesCount محادثات جديدة';

    if (iconOnly) {
      return _IconOnlyWorkspaceSelector(
        workspaces: workspaces,
        loading: loading,
        changing: changing,
        error: error,
        selectedWorkspaceId: selectedId,
        newMessagesCount: newMessagesCount,
        onSelected: onSelected,
        onRetry: onRetry,
      );
    }

    return Material(
      key: const Key('worker-inbox-workspace-selector'),
      color: HasimTokens.cardBg(context),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                DecoratedBox(
                  decoration: BoxDecoration(
                    color: HasimTokens.primary.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Padding(
                    padding: EdgeInsets.all(6),
                    child: Icon(
                      Icons.business_outlined,
                      color: HasimTokens.primary,
                      size: 18,
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: workspaces.isEmpty
                      ? Text(
                          loading
                              ? 'جارٍ تحميل الشركات...'
                              : 'لا توجد شركة متاحة للمحادثات',
                          style: Theme.of(context).textTheme.titleSmall
                              ?.copyWith(
                                fontSize: 12,
                                fontWeight: FontWeight.w800,
                              ),
                        )
                      : Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Text(
                              'الشركة الحالية',
                              style: Theme.of(context).textTheme.labelSmall
                                  ?.copyWith(
                                    color: HasimTokens.mutedColor(context),
                                    fontSize: 10,
                                    fontWeight: FontWeight.w600,
                                    height: 1.1,
                                  ),
                            ),
                            DropdownButtonHideUnderline(
                              child: DropdownButton<int>(
                                key: const Key(
                                  'worker-inbox-workspace-dropdown',
                                ),
                                value: selectedId,
                                hint: const Text('اختر الشركة'),
                                isExpanded: true,
                                isDense: true,
                                borderRadius: BorderRadius.circular(12),
                                icon: const Icon(
                                  Icons.expand_more_rounded,
                                  size: 18,
                                ),
                                style: Theme.of(context).textTheme.titleSmall
                                    ?.copyWith(
                                      fontSize: 12,
                                      fontWeight: FontWeight.w700,
                                    ),
                                items: [
                                  for (final workspace in workspaces)
                                    DropdownMenuItem<int>(
                                      value: workspace.id,
                                      child: Text(
                                        workspace.name,
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                        textAlign: TextAlign.right,
                                      ),
                                    ),
                                ],
                                onChanged: changing
                                    ? null
                                    : (value) {
                                        if (value != null) {
                                          unawaited(onSelected(value));
                                        }
                                      },
                              ),
                            ),
                          ],
                        ),
                ),
                if (loading || changing)
                  const Padding(
                    padding: EdgeInsetsDirectional.only(start: 8),
                    child: SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 2),
            Text(
              unreadLabel,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: HasimTokens.mutedColor(context),
                fontSize: 10,
                fontWeight: FontWeight.w600,
                height: 1.1,
              ),
            ),
            if (error != null) ...[
              const SizedBox(height: 2),
              Text(
                error!,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: HasimTokens.danger,
                  fontSize: 10,
                  fontWeight: FontWeight.w600,
                ),
              ),
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: TextButton(
                  key: const Key('worker-inbox-workspace-retry'),
                  onPressed: loading ? null : onRetry,
                  style: TextButton.styleFrom(
                    minimumSize: Size.zero,
                    padding: EdgeInsets.zero,
                    tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    visualDensity: VisualDensity.compact,
                  ),
                  child: const Text('إعادة المحاولة'),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _IconOnlyWorkspaceSelector extends StatelessWidget {
  const _IconOnlyWorkspaceSelector({
    required this.workspaces,
    required this.loading,
    required this.changing,
    required this.error,
    required this.selectedWorkspaceId,
    required this.newMessagesCount,
    required this.onSelected,
    required this.onRetry,
  });

  final List<WorkerInboxWorkspace> workspaces;
  final bool loading;
  final bool changing;
  final String? error;
  final int? selectedWorkspaceId;
  final int newMessagesCount;
  final Future<void> Function(int workspaceId) onSelected;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    final selectedWorkspace = workspaces
        .where((workspace) => workspace.id == selectedWorkspaceId)
        .firstOrNull;
    final tooltip = selectedWorkspace == null
        ? 'اختيار الشركة'
        : 'الشركة الحالية: ${selectedWorkspace.name}';
    final enabled =
        !loading && !changing && (workspaces.isNotEmpty || error != null);

    return SizedBox(
      key: const Key('worker-inbox-workspace-selector'),
      width: 60,
      height: 56,
      child: MenuAnchor(
        key: const Key('worker-inbox-workspace-dropdown'),
        alignmentOffset: const Offset(0, 8),
        style: MenuStyle(
          minimumSize: const WidgetStatePropertyAll(Size(220, 0)),
          maximumSize: const WidgetStatePropertyAll(Size(260, double.infinity)),
          padding: const WidgetStatePropertyAll(
            EdgeInsets.symmetric(vertical: 8, horizontal: 6),
          ),
          backgroundColor: WidgetStatePropertyAll(HasimTokens.cardBg(context)),
          elevation: const WidgetStatePropertyAll(12),
          shadowColor: const WidgetStatePropertyAll(Color(0x330F2A24)),
          surfaceTintColor: const WidgetStatePropertyAll(Colors.transparent),
          shape: WidgetStatePropertyAll(
            RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(22),
              side: BorderSide(color: HasimTokens.lineColor(context)),
            ),
          ),
        ),
        menuChildren: [
          for (final workspace in workspaces)
            _WorkspaceMenuItem(
              name: workspace.name,
              selected: workspace.id == selectedWorkspaceId,
              onPressed: () => unawaited(onSelected(workspace.id)),
            ),
          if (error != null)
            MenuItemButton(
              key: const Key('worker-inbox-workspace-retry'),
              onPressed: loading ? null : () => unawaited(onRetry()),
              child: const SizedBox(
                width: double.infinity,
                child: Text('إعادة المحاولة'),
              ),
            ),
        ],
        builder: (context, controller, child) {
          return Tooltip(
            message: tooltip,
            child: IconButton(
              onPressed: enabled
                  ? () {
                      if (controller.isOpen) {
                        controller.close();
                      } else {
                        controller.open();
                      }
                    }
                  : null,
              padding: EdgeInsets.zero,
              constraints: const BoxConstraints.tightFor(width: 36, height: 36),
              splashRadius: 20,
              icon: loading || changing
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Badge(
                      isLabelVisible: newMessagesCount > 0,
                      label: Text('$newMessagesCount'),
                      child: const Icon(
                        Icons.business_outlined,
                        color: HasimTokens.primary,
                        size: 22,
                      ),
                    ),
            ),
          );
        },
      ),
    );
  }
}

class _WorkspaceMenuItem extends StatelessWidget {
  const _WorkspaceMenuItem({
    required this.name,
    required this.selected,
    required this.onPressed,
  });

  final String name;
  final bool selected;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return MenuItemButton(
      onPressed: onPressed,
      style: ButtonStyle(
        minimumSize: const WidgetStatePropertyAll(Size(double.infinity, 44)),
        padding: const WidgetStatePropertyAll(
          EdgeInsets.symmetric(horizontal: 8),
        ),
        shape: const WidgetStatePropertyAll(
          RoundedRectangleBorder(
            borderRadius: BorderRadius.all(Radius.circular(14)),
          ),
        ),
        backgroundColor: WidgetStateProperty.resolveWith((states) {
          if (selected) {
            return HasimTokens.primary.withValues(alpha: 0.12);
          }
          if (states.contains(WidgetState.hovered)) {
            return HasimTokens.primary.withValues(alpha: 0.08);
          }
          return Colors.transparent;
        }),
        elevation: WidgetStateProperty.resolveWith((states) {
          return states.contains(WidgetState.hovered) ? 2 : 0;
        }),
        shadowColor: const WidgetStatePropertyAll(Color(0x260F2A24)),
      ),
      leadingIcon: Icon(
        selected ? Icons.check_circle_rounded : Icons.business_outlined,
        size: 18,
        color: selected ? HasimTokens.primary : HasimTokens.mutedColor(context),
      ),
      child: Text(name, maxLines: 1, overflow: TextOverflow.ellipsis),
    );
  }
}
