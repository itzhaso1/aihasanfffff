import 'package:flutter/material.dart';
import 'package:hasim/core/widgets/empty_state.dart';

class AsyncBody extends StatelessWidget {
  const AsyncBody({
    super.key,
    required this.loading,
    required this.error,
    required this.isEmpty,
    required this.onRetry,
    required this.child,
    this.emptyTitle = 'لا توجد بيانات',
    this.emptySubtitle,
    this.emptyIllustration,
    this.emptyActionLabel,
    this.onEmptyAction,
    this.emptyActionIcon,
    this.emptyPillAction = false,
  });

  final bool loading;
  final String? error;
  final bool isEmpty;
  final VoidCallback onRetry;
  final Widget child;
  final String emptyTitle;
  final String? emptySubtitle;
  final Widget? emptyIllustration;
  final String? emptyActionLabel;
  final VoidCallback? onEmptyAction;
  final IconData? emptyActionIcon;
  final bool emptyPillAction;

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(error!, textAlign: TextAlign.center),
              const SizedBox(height: 12),
              FilledButton(onPressed: onRetry, child: const Text('إعادة المحاولة')),
            ],
          ),
        ),
      );
    }
    if (isEmpty) {
      return LayoutBuilder(
        builder: (context, constraints) {
          return ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            children: [
              ConstrainedBox(
                constraints: BoxConstraints(minHeight: constraints.maxHeight),
                child: EmptyState(
                  title: emptyTitle,
                  subtitle: emptySubtitle,
                  illustration: emptyIllustration,
                  actionLabel: emptyActionLabel,
                  onAction: onEmptyAction,
                  actionIcon: emptyActionIcon,
                  pillAction: emptyPillAction,
                ),
              ),
            ],
          );
        },
      );
    }
    return child;
  }
}
