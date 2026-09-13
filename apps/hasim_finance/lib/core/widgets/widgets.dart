import 'package:flutter/material.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.title,
    this.subtitle,
    this.icon = Icons.inbox_outlined,
    this.actionLabel,
    this.onAction,
  });

  final String title;
  final String? subtitle;
  final IconData icon;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final muted = Theme.of(context).colorScheme.onSurface.withValues(alpha: 0.55);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 52, color: muted),
            const SizedBox(height: 14),
            Text(title, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
            if (subtitle != null) ...[
              const SizedBox(height: 6),
              Text(subtitle!, textAlign: TextAlign.center, style: TextStyle(color: muted, height: 1.4)),
            ],
            if (actionLabel != null && onAction != null) ...[
              const SizedBox(height: 16),
              FilledButton(onPressed: onAction, child: Text(actionLabel!)),
            ],
          ],
        ),
      ),
    );
  }
}

class AsyncBody extends StatelessWidget {
  const AsyncBody({
    super.key,
    required this.loading,
    required this.onRetry,
    required this.child,
    this.error,
    this.isEmpty = false,
    this.emptyTitle,
    this.emptySubtitle,
    this.emptyIcon = Icons.inbox_outlined,
    this.emptyActionLabel,
    this.onEmptyAction,
  });

  final bool loading;
  final String? error;
  final bool isEmpty;
  final VoidCallback onRetry;
  final Widget child;
  final String? emptyTitle;
  final String? emptySubtitle;
  final IconData emptyIcon;
  final String? emptyActionLabel;
  final VoidCallback? onEmptyAction;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
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
              Icon(Icons.error_outline_rounded, size: 44, color: Theme.of(context).colorScheme.error),
              const SizedBox(height: 12),
              Text(error!, textAlign: TextAlign.center),
              const SizedBox(height: 12),
              FilledButton.icon(
                onPressed: onRetry,
                icon: const Icon(Icons.refresh_rounded, size: 18),
                label: Text(l.retry),
              ),
            ],
          ),
        ),
      );
    }
    if (isEmpty) {
      return EmptyState(
        title: emptyTitle ?? l.empty,
        subtitle: emptySubtitle,
        icon: emptyIcon,
        actionLabel: emptyActionLabel,
        onAction: onEmptyAction,
      );
    }
    return child;
  }
}

class StatusChip extends StatelessWidget {
  const StatusChip({super.key, required this.label, this.tone = StatusTone.neutral});

  final String label;
  final StatusTone tone;

  @override
  Widget build(BuildContext context) {
    final p = Theme.of(context).extension<FinancePalette>() ??
        (Theme.of(context).brightness == Brightness.dark ? FinancePalette.dark : FinancePalette.light);
    final color = switch (tone) {
      StatusTone.success => p.success,
      StatusTone.warning => p.warning,
      StatusTone.danger => p.danger,
      StatusTone.info => p.info,
      StatusTone.neutral => p.textMuted,
    };
    final bg = switch (tone) {
      StatusTone.success => p.successSoft,
      StatusTone.warning => p.warningSoft,
      StatusTone.danger => p.dangerSoft,
      StatusTone.info => p.infoSoft,
      StatusTone.neutral => p.canvasAlt,
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
      child: Text(label, style: TextStyle(color: color, fontWeight: FontWeight.w800, fontSize: 11)),
    );
  }
}

enum StatusTone { success, warning, danger, info, neutral }

StatusTone toneFor(String? value) {
  switch (value) {
    case 'issued':
    case 'paid':
    case 'posted':
    case 'accepted':
    case 'sent':
    case 'open':
    case 'active':
      return StatusTone.success;
    case 'draft':
    case 'pending':
    case 'partial':
    case 'ready':
      return StatusTone.info;
    case 'overdue':
    case 'failed':
    case 'rejected':
    case 'voided':
    case 'reversed':
    case 'cancelled':
      return StatusTone.danger;
    case 'expired':
    case 'unpaid':
      return StatusTone.warning;
    default:
      return StatusTone.neutral;
  }
}

class MoneyText extends StatelessWidget {
  const MoneyText(this.value, {super.key, this.currency = 'ر.س', this.style});

  final String value;
  final String currency;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    return Text(
      '$value $currency',
      style: style ?? Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800, letterSpacing: -0.2),
    );
  }
}

class PermissionGate extends StatelessWidget {
  const PermissionGate({
    super.key,
    required this.allowed,
    required this.child,
    this.message,
  });

  final bool allowed;
  final Widget child;
  final String? message;

  @override
  Widget build(BuildContext context) {
    if (allowed) return child;
    return EmptyState(title: message ?? AppLocalizations.of(context).noPermissionScreen, icon: Icons.lock_outline);
  }
}
