import 'package:flutter/material.dart';
import 'package:hasim_finance/core/layout/finance_layout.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';

class ContractFormBreadcrumb extends StatelessWidget {
  const ContractFormBreadcrumb({
    super.key,
    required this.segments,
  });

  final List<String> segments;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final p = context.hs;
    return Wrap(
      spacing: 6,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        for (var i = 0; i < segments.length; i++) ...[
          if (i > 0)
            Icon(Icons.chevron_left, size: 16, color: p.textFaint),
          Text(
            segments[i],
            style: theme.textTheme.bodySmall?.copyWith(
              color: i == segments.length - 1 ? p.text : p.textMuted,
              fontWeight: i == segments.length - 1 ? FontWeight.w700 : FontWeight.w500,
            ),
          ),
        ],
      ],
    );
  }
}

class ContractPageHero extends StatelessWidget {
  const ContractPageHero({
    super.key,
    required this.title,
    required this.subtitle,
    this.onBack,
  });

  final String title;
  final String subtitle;
  final VoidCallback? onBack;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final stacked = MediaQuery.sizeOf(context).width < 720;
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const ContractFormBreadcrumb(segments: ['الرئيسية', 'العقود']),
          const SizedBox(height: 10),
          stacked
              ? Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _TitleRow(title: title, subtitle: subtitle, theme: theme),
                    if (onBack != null) ...[
                      const SizedBox(height: 10),
                      Align(
                        alignment: AlignmentDirectional.centerStart,
                        child: OutlinedButton.icon(
                          onPressed: onBack,
                          icon: const Icon(Icons.arrow_back, size: 18),
                          label: const Text('رجوع'),
                        ),
                      ),
                    ],
                  ],
                )
              : Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(child: _TitleRow(title: title, subtitle: subtitle, theme: theme)),
                    if (onBack != null)
                      OutlinedButton.icon(
                        onPressed: onBack,
                        icon: const Icon(Icons.arrow_back, size: 18),
                        label: const Text('رجوع'),
                      ),
                  ],
                ),
        ],
      ),
    );
  }
}

class _TitleRow extends StatelessWidget {
  const _TitleRow({
    required this.title,
    required this.subtitle,
    required this.theme,
  });

  final String title;
  final String subtitle;
  final ThemeData theme;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const FinanceIconBadge(icon: Icons.description_outlined, tone: FinanceIconTone.teal, size: 44),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800, fontSize: 26),
              ),
              const SizedBox(height: 4),
              Text(subtitle, style: theme.textTheme.bodyMedium?.copyWith(color: context.hs.textMuted)),
            ],
          ),
        ),
      ],
    );
  }
}

class ContractFormActionBar extends StatelessWidget {
  const ContractFormActionBar({
    super.key,
    required this.onSave,
    required this.onSaveDraft,
    required this.onCancel,
    this.busy = false,
    this.canSave = true,
    this.primaryLabel = 'حفظ العقد',
    this.draftLabel = 'حفظ كمسودة',
  });

  final VoidCallback? onSave;
  final VoidCallback? onSaveDraft;
  final VoidCallback onCancel;
  final bool busy;
  final bool canSave;
  final String primaryLabel;
  final String draftLabel;

  @override
  Widget build(BuildContext context) {
    final stacked = MediaQuery.sizeOf(context).width < 640;
    final savePrimary = FilledButton.icon(
      onPressed: busy || !canSave ? null : onSave,
      icon: busy
          ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
          : const Icon(Icons.check_circle_outline, size: 18),
      label: Text(primaryLabel),
      style: FilledButton.styleFrom(
        backgroundColor: FinanceTokens.brand,
        foregroundColor: Colors.white,
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(FinanceTokens.radius)),
      ),
    );
    final saveDraft = OutlinedButton.icon(
      onPressed: busy || !canSave ? null : onSaveDraft,
      icon: const Icon(Icons.save_outlined, size: 18),
      label: Text(draftLabel),
      style: OutlinedButton.styleFrom(
        foregroundColor: FinanceTokens.brand,
        side: BorderSide(color: context.hs.border),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(FinanceTokens.radius)),
      ),
    );
    final cancel = TextButton(
      onPressed: busy ? null : onCancel,
      child: Text('إلغاء', style: TextStyle(color: context.hs.textMuted)),
    );

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: stacked
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                savePrimary,
                const SizedBox(height: 8),
                saveDraft,
                const SizedBox(height: 4),
                cancel,
              ],
            )
          : Wrap(
              spacing: 10,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [savePrimary, saveDraft, cancel],
            ),
    );
  }
}

class ContractFormSectionCard extends StatelessWidget {
  const ContractFormSectionCard({
    super.key,
    required this.title,
    required this.child,
    this.subtitle,
    this.icon = Icons.article_outlined,
  });

  final String title;
  final String? subtitle;
  final IconData icon;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final p = context.hs;
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: DecoratedBox(
        decoration: FinanceTokens.card(context),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(18, 16, 18, 18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  FinanceIconBadge(icon: icon, tone: FinanceIconTone.teal, size: 36),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(title, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
                        if (subtitle != null) ...[
                          const SizedBox(height: 2),
                          Text(subtitle!, style: theme.textTheme.bodySmall?.copyWith(color: p.textMuted)),
                        ],
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              child,
            ],
          ),
        ),
      ),
    );
  }
}

class ContractLabeledField extends StatelessWidget {
  const ContractLabeledField({
    super.key,
    required this.label,
    required this.child,
    this.required = false,
    this.errorText,
    this.helperText,
    this.fullWidth = false,
  });

  final String label;
  final Widget child;
  final bool required;
  final String? errorText;
  final String? helperText;
  final bool fullWidth;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final p = context.hs;
    final labelWidget = RichText(
      text: TextSpan(
        style: theme.textTheme.labelLarge?.copyWith(color: p.textMuted, fontWeight: FontWeight.w600, height: 1.3),
        children: [
          TextSpan(text: label),
          if (required)
            const TextSpan(text: ' *', style: TextStyle(color: FinanceTokens.danger, fontWeight: FontWeight.w700)),
        ],
      ),
    );

    return LayoutBuilder(
      builder: (context, constraints) {
        final field = Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            labelWidget,
            const SizedBox(height: FinanceFilterField.labelGap),
            child,
            if (helperText != null && helperText!.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text(helperText!, style: theme.textTheme.bodySmall?.copyWith(color: p.textFaint)),
            ],
            if (errorText != null && errorText!.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text(errorText!, style: theme.textTheme.bodySmall?.copyWith(color: p.danger)),
            ],
          ],
        );
        if (fullWidth) return field;
        return SizedBox(width: constraints.maxWidth, child: field);
      },
    );
  }
}

InputDecoration contractFieldDecoration({
  String? hintText,
  IconData? prefixIcon,
  Widget? suffixIcon,
}) {
  return InputDecoration(
    hintText: hintText,
    prefixIcon: prefixIcon == null ? null : Icon(prefixIcon, size: 18),
    suffixIcon: suffixIcon,
    isDense: true,
    floatingLabelBehavior: FloatingLabelBehavior.never,
    contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
    prefixIconConstraints: prefixIcon == null ? null : const BoxConstraints(minWidth: 40, minHeight: 40),
    suffixIconConstraints: suffixIcon == null ? null : const BoxConstraints(minWidth: 40, minHeight: 40),
  );
}

class ContractFormGrid extends StatelessWidget {
  const ContractFormGrid({
    super.key,
    required this.children,
    this.minWidth = 240,
  });

  final List<Widget> children;
  final double minWidth;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final cols = constraints.maxWidth >= minWidth * 3 + 24
            ? 3
            : constraints.maxWidth >= minWidth * 2 + 12
                ? 2
                : 1;
        return Wrap(
          spacing: 14,
          runSpacing: 14,
          children: [
            for (final child in children)
              SizedBox(
                width: cols == 1 ? constraints.maxWidth : (constraints.maxWidth - (14 * (cols - 1))) / cols,
                child: child,
              ),
          ],
        );
      },
    );
  }
}
