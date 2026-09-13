import 'package:flutter/material.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';

class FinanceScaffold extends StatelessWidget {
  const FinanceScaffold({
    super.key,
    required this.title,
    required this.body,
    this.subtitle,
    this.actions = const [],
    this.floatingActionButton,
    this.primaryAction,
    this.showBack = false,
  });

  final String title;
  final String? subtitle;
  final List<Widget> actions;
  final Widget body;
  final Widget? floatingActionButton;
  final Widget? primaryAction;
  final bool showBack;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: floatingActionButton,
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          FinancePageHeader(
            title: title,
            subtitle: subtitle,
            actions: [
              ...actions,
              ?primaryAction,
            ],
            showBack: showBack,
          ),
          Expanded(child: body),
        ],
      ),
    );
  }
}

class FinancePageHeader extends StatelessWidget {
  const FinancePageHeader({
    super.key,
    required this.title,
    this.subtitle,
    this.actions = const [],
    this.showBack = false,
  });

  final String title;
  final String? subtitle;
  final List<Widget> actions;
  final bool showBack;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final canPop = showBack && Navigator.of(context).canPop();
    final stacked = MediaQuery.sizeOf(context).width < 720;
    final titleBlock = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800, fontSize: stacked ? 20 : 24),
        ),
        if (subtitle != null && subtitle!.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 2),
            child: Text(subtitle!, maxLines: 2, overflow: TextOverflow.ellipsis, style: theme.textTheme.bodySmall),
          ),
      ],
    );
    final actionRow = Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: actions);
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 4),
      child: stacked
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    if (canPop) ...[
                      IconButton(
                        tooltip: MaterialLocalizations.of(context).backButtonTooltip,
                        onPressed: () => Navigator.of(context).maybePop(),
                        icon: const Icon(Icons.arrow_back, size: 20),
                      ),
                      const SizedBox(width: 4),
                    ],
                    Expanded(child: titleBlock),
                  ],
                ),
                if (actions.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  actionRow,
                ],
              ],
            )
          : Row(
              children: [
                if (canPop) ...[
                  IconButton(
                    tooltip: MaterialLocalizations.of(context).backButtonTooltip,
                    onPressed: () => Navigator.of(context).maybePop(),
                    icon: const Icon(Icons.arrow_back, size: 20),
                  ),
                  const SizedBox(width: 4),
                ],
                Expanded(child: titleBlock),
                actionRow,
              ],
            ),
    );
  }
}

/// Visible label stacked above a compact outline field (not a floating label).
class FinanceFilterField extends StatelessWidget {
  const FinanceFilterField({
    super.key,
    required this.label,
    required this.child,
    this.width = 180,
  });

  final String label;
  final Widget child;
  final double width;

  static const double labelGap = 6;
  static const double labelSlotHeight = 24;
  static const double stackHeight = labelSlotHeight + labelGap + FinanceTokens.controlHeight;

  static InputDecoration decoration({
    String? hintText,
    Widget? prefixIcon,
    Widget? suffixIcon,
  }) {
    return InputDecoration(
      hintText: hintText,
      prefixIcon: prefixIcon,
      suffixIcon: suffixIcon,
      isDense: true,
      floatingLabelBehavior: FloatingLabelBehavior.never,
      alignLabelWithHint: true,
      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      prefixIconConstraints: prefixIcon == null ? null : const BoxConstraints(minWidth: 36, minHeight: 36),
      suffixIconConstraints: suffixIcon == null ? null : const BoxConstraints(minWidth: 36, minHeight: 36),
    );
  }

  /// Below this width fields stretch to the full row (single-column form).
  static const double stackBreakpoint = 600;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final fill = MediaQuery.sizeOf(context).width < stackBreakpoint;
    return SizedBox(
      width: fill ? double.infinity : width,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          SizedBox(
            height: labelSlotHeight,
            child: Align(
              alignment: AlignmentDirectional.centerStart,
              child: Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.labelLarge?.copyWith(
                  color: context.hs.textMuted,
                  fontWeight: FontWeight.w600,
                  height: 1.3,
                ),
              ),
            ),
          ),
          const SizedBox(height: labelGap),
          SizedBox(
            height: FinanceTokens.controlHeight,
            child: child,
          ),
        ],
      ),
    );
  }
}

/// Compact dropdown styled for [FinanceFilterField] slots.
class FinanceFilterDropdown<T> extends StatelessWidget {
  const FinanceFilterDropdown({
    super.key,
    required this.value,
    required this.items,
    required this.onChanged,
  });

  final T? value;
  final List<DropdownMenuItem<T?>> items;
  final ValueChanged<T?> onChanged;

  @override
  Widget build(BuildContext context) {
    return DropdownButtonFormField<T?>(
      // ignore: deprecated_member_use
      value: value,
      isExpanded: true,
      isDense: true,
      decoration: FinanceFilterField.decoration(),
      items: items,
      onChanged: onChanged,
    );
  }
}

/// Card that hosts filter controls.
///
/// Fields flow in a [Wrap] so they never overlap [trailing] actions or spill
/// outside the card (the previous horizontal scroller was unclipped and drew
/// over the action buttons in RTL). [below] renders an optional second block
/// (chips, advanced filters, summaries) inside the same card.
class FinanceFilterBar extends StatelessWidget {
  const FinanceFilterBar({
    super.key,
    required this.children,
    this.trailing,
    this.below,
    this.margin = const EdgeInsets.fromLTRB(20, 8, 20, 12),
  });

  final List<Widget> children;
  final Widget? trailing;
  final Widget? below;
  final EdgeInsets margin;

  static const double gap = 12;

  @override
  Widget build(BuildContext context) {
    final stacked = MediaQuery.sizeOf(context).width < 720;
    // On wide screens the actions flow inline after the last field (bottom
    // aligned with the controls) and wrap to a new run when space runs out;
    // on phones they get their own row.
    final inlineTrailing = trailing != null && !stacked;
    final fields = Wrap(
      spacing: gap,
      runSpacing: gap,
      crossAxisAlignment: WrapCrossAlignment.end,
      children: [
        ...children,
        if (inlineTrailing) trailing!,
      ],
    );
    final Widget top;
    if (trailing == null || inlineTrailing) {
      top = fields;
    } else {
      top = Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (children.isNotEmpty) ...[fields, const SizedBox(height: gap)],
          Align(alignment: AlignmentDirectional.centerEnd, child: trailing),
        ],
      );
    }
    return Container(
      margin: margin,
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      decoration: FinanceTokens.card(context, radius: FinanceTokens.radiusLg),
      // Ink-based children (ListTile, chips) need a Material above them that
      // sits inside the decorated box, otherwise their ink is painted below it.
      child: Material(
        type: MaterialType.transparency,
        child: below == null
            ? top
            : Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (children.isNotEmpty || trailing != null) ...[top, const SizedBox(height: gap)],
                  below!,
                ],
              ),
      ),
    );
  }
}

class FinanceSurface extends StatelessWidget {
  const FinanceSurface({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
    this.margin,
    this.title,
    this.trailing,
  });

  final Widget child;
  final EdgeInsets padding;
  final EdgeInsets? margin;
  final String? title;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final p = context.hs;
    return Material(
      color: p.card,
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(FinanceTokens.radiusLg),
        side: BorderSide(color: p.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (title != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 0),
              child: Row(
                children: [
                  Expanded(
                    child: Text(title!, style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
                  ),
                  ?trailing,
                ],
              ),
            ),
          Padding(padding: padding, child: child),
        ],
      ),
    );
  }
}

class FinanceIconBadge extends StatelessWidget {
  const FinanceIconBadge({super.key, required this.icon, required this.tone, this.size = 40});

  final IconData icon;
  final FinanceIconTone tone;
  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: tone.themed(context).background, borderRadius: BorderRadius.circular(12)),
      child: Icon(icon, color: tone.themed(context).foreground, size: size * 0.48),
    );
  }
}
