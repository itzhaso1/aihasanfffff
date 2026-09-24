import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';
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
  });

  final String label;
  final IconData icon;
  final Widget child;
  final double? minHeight;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return ComposeCard(
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
                color: HasimTokens.mintFill(context),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, size: 18, color: AppTheme.brand),
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
            if (trailing != null) ...[
              const SizedBox(width: 8),
              trailing!,
            ],
          ],
        ),
      ),
    );
  }
}

InputDecoration catalogInputDecoration(BuildContext context, {String? hint, String? errorText}) {
  return InputDecoration(
    hintText: hint,
    errorText: errorText,
    hintStyle: Theme.of(context).textTheme.bodyLarge?.copyWith(
          color: HasimTokens.mutedColor(context),
          fontWeight: FontWeight.w500,
        ),
    errorStyle: Theme.of(context).textTheme.bodySmall?.copyWith(
          color: const Color(0xFFC44536),
          fontWeight: FontWeight.w700,
        ),
    isDense: true,
    filled: true,
    fillColor: Colors.transparent,
    contentPadding: const EdgeInsets.symmetric(vertical: 8),
    border: InputBorder.none,
    enabledBorder: InputBorder.none,
    focusedBorder: InputBorder.none,
    errorBorder: InputBorder.none,
    focusedErrorBorder: InputBorder.none,
    disabledBorder: InputBorder.none,
  );
}

class CatalogSelectField<T> extends StatelessWidget {
  const CatalogSelectField({
    super.key,
    required this.label,
    required this.icon,
    required this.value,
    required this.items,
    required this.onChanged,
  });

  final String label;
  final IconData icon;
  final T value;
  final List<DropdownMenuItem<T>> items;
  final ValueChanged<T?> onChanged;

  @override
  Widget build(BuildContext context) {
    return CatalogFieldCard(
      label: label,
      icon: icon,
      child: DropdownButtonHideUnderline(
        child: DropdownButton<T>(
          value: value,
          isExpanded: true,
          isDense: true,
          icon: Icon(Icons.keyboard_arrow_down_rounded, color: HasimTokens.mutedColor(context)),
          borderRadius: BorderRadius.circular(HasimTokens.radiusCard),
          items: items,
          onChanged: onChanged,
          style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                fontWeight: FontWeight.w700,
                color: HasimTokens.inkColor(context),
                fontSize: 16,
              ),
        ),
      ),
    );
  }
}
