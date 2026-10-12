import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/email/presentation/widgets/compose_fields.dart';

class CatalogFieldCard extends StatelessWidget {
  const CatalogFieldCard({
    super.key,
    required this.label,
    required this.icon,
    required this.child,
    this.minHeight,
    this.trailing,
    this.dense = false,
  });

  static const double denseHeight = 48;

  final String label;
  final IconData icon;
  final Widget child;
  final double? minHeight;
  final Widget? trailing;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    if (!dense) {
      return ComposeCard(
        radius: 10,
        minHeight: minHeight ?? 72,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 10),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(
                  color: HasimTokens.mutedColor(
                    context,
                  ).withValues(alpha: 0.08),
                  shape: BoxShape.circle,
                ),
                child: Icon(
                  icon,
                  size: 18,
                  color: HasimTokens.mutedColor(context),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      label,
                      textAlign: TextAlign.start,
                      style: Theme.of(context).textTheme.labelLarge?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: HasimTokens.inkColor(context),
                      ),
                    ),
                    const SizedBox(height: 2),
                    child,
                  ],
                ),
              ),
              if (trailing != null) ...[const SizedBox(width: 8), trailing!],
            ],
          ),
        ),
      );
    }

    return ClipRRect(
      borderRadius: BorderRadius.circular(10),
      child: SizedBox(
        height: minHeight ?? denseHeight,
        width: double.infinity,
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: HasimTokens.cardBg(context),
            borderRadius: BorderRadius.circular(10),
            border: Border.all(
              color: HasimTokens.fieldBorder.withValues(
                alpha: HasimTokens.isDark(context) ? 0.35 : 1,
              ),
            ),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8),
            child: Row(
              children: [
                Container(
                  width: 22,
                  height: 22,
                  decoration: BoxDecoration(
                    color: HasimTokens.mutedColor(
                      context,
                    ).withValues(alpha: 0.08),
                    shape: BoxShape.circle,
                  ),
                  child: Icon(
                    icon,
                    size: 13,
                    color: HasimTokens.mutedColor(context),
                  ),
                ),
                const SizedBox(width: 8),
                ConstrainedBox(
                  constraints: const BoxConstraints(minWidth: 56, maxWidth: 86),
                  child: Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.labelLarge?.copyWith(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: HasimTokens.inkColor(context),
                    ),
                  ),
                ),
                SizedBox(width: dense ? 6 : 8),
                Expanded(
                  child: Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: child,
                  ),
                ),
                if (trailing != null) ...[const SizedBox(width: 6), trailing!],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

InputDecoration catalogInputDecoration(
  BuildContext context, {
  String? hint,
  String? errorText,
}) {
  return InputDecoration(
    hintText: hint,
    errorText: errorText,
    hintStyle: Theme.of(context).textTheme.bodySmall?.copyWith(
      color: HasimTokens.mutedColor(context),
      fontWeight: FontWeight.w500,
      fontSize: 12,
    ),
    errorStyle: const TextStyle(fontSize: 0, height: 0),
    isDense: true,
    isCollapsed: true,
    filled: true,
    fillColor: Colors.transparent,
    contentPadding: EdgeInsets.zero,
    border: InputBorder.none,
    enabledBorder: InputBorder.none,
    focusedBorder: InputBorder.none,
    errorBorder: InputBorder.none,
    focusedErrorBorder: InputBorder.none,
    disabledBorder: InputBorder.none,
  );
}

class CatalogFormSection extends StatelessWidget {
  const CatalogFormSection({
    super.key,
    required this.title,
    required this.icon,
    required this.child,
    this.trailing,
  });

  final String title;
  final IconData icon;
  final Widget child;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: HasimTokens.fieldBorder.withValues(
            alpha: HasimTokens.isDark(context) ? 0.35 : 1,
          ),
        ),
        boxShadow: const [
          BoxShadow(
            color: Color(0x05000000),
            blurRadius: 8,
            offset: Offset(0, 2),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: HasimTokens.inkColor(context),
                    ),
                  ),
                ),
                if (trailing != null) ...[trailing!, const SizedBox(width: 8)],
                Icon(icon, size: 18, color: HasimTokens.mutedColor(context)),
              ],
            ),
            const SizedBox(height: 10),
            child,
          ],
        ),
      ),
    );
  }
}

class CatalogSelectField<T> extends StatelessWidget {
  const CatalogSelectField({
    super.key,
    required this.label,
    required this.icon,
    required this.value,
    required this.items,
    required this.onChanged,
    this.dense = false,
  });

  final String label;
  final IconData icon;
  final T value;
  final List<DropdownMenuItem<T>> items;
  final ValueChanged<T?> onChanged;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    Widget selected = const SizedBox.shrink();
    for (final item in items) {
      if (item.value == value) {
        selected = item.child;
        break;
      }
    }
    return PopupMenuButton<T>(
      initialValue: value,
      padding: EdgeInsets.zero,
      tooltip: label,
      offset: const Offset(0, 8),
      position: PopupMenuPosition.under,
      useRootNavigator: true,
      color: HasimTokens.cardBg(context),
      elevation: 8,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: BorderSide(color: HasimTokens.lineColor(context)),
      ),
      constraints: BoxConstraints(
        minWidth: MediaQuery.sizeOf(context).width < 252
            ? MediaQuery.sizeOf(context).width - 32
            : 220,
        maxWidth: MediaQuery.sizeOf(context).width - 32,
      ),
      onSelected: onChanged,
      itemBuilder: (context) => [
        for (final item in items)
          PopupMenuItem<T>(
            value: item.value,
            height: 44,
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Align(
              alignment: AlignmentDirectional.centerEnd,
              child: DefaultTextStyle.merge(
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: HasimTokens.inkColor(context),
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                ),
                child: item.child,
              ),
            ),
          ),
      ],
      child: SizedBox(
        width: double.infinity,
        child: CatalogFieldCard(
          label: label,
          icon: icon,
          dense: dense,
          trailing: Icon(
            Icons.keyboard_arrow_down_rounded,
            size: 18,
            color: HasimTokens.mutedColor(context),
          ),
          child: DefaultTextStyle.merge(
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              fontWeight: FontWeight.w600,
              color: HasimTokens.inkColor(context),
              fontSize: 13,
              height: 1.1,
            ),
            child: selected,
          ),
        ),
      ),
    );
  }
}
