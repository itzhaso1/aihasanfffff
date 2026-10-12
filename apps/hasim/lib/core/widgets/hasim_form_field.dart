import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class HasimFormField extends StatelessWidget {
  const HasimFormField({
    super.key,
    required this.controller,
    required this.label,
    this.icon,
    this.textDirection,
    this.textAlign,
    this.obscureText = false,
    this.onToggleObscure,
    this.maxLines = 1,
    this.onChanged,
    this.errorText,
  });

  final TextEditingController controller;
  final String label;
  final IconData? icon;
  final TextDirection? textDirection;
  final TextAlign? textAlign;
  final bool obscureText;
  final VoidCallback? onToggleObscure;
  final int maxLines;
  final ValueChanged<String>? onChanged;
  final String? errorText;

  @override
  Widget build(BuildContext context) {
    final muted = HasimTokens.mutedColor(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          label,
          style: Theme.of(context).textTheme.titleSmall?.copyWith(
            color: HasimTokens.inkColor(context),
          ),
        ),
        const SizedBox(height: HasimTokens.spaceSm),
        TextField(
          controller: controller,
          obscureText: obscureText,
          onChanged: onChanged,
          maxLines: obscureText ? 1 : maxLines,
          textDirection: textDirection,
          textAlign: textAlign ?? TextAlign.start,
          style: Theme.of(context).textTheme.bodyLarge?.copyWith(
            color: HasimTokens.inkColor(context),
            fontWeight: FontWeight.w600,
          ),
          decoration: InputDecoration(
            prefixIcon: icon != null
                ? Icon(icon, size: 20, color: muted)
                : null,
            suffixIcon: onToggleObscure != null
                ? IconButton(
                    onPressed: onToggleObscure,
                    icon: Icon(
                      obscureText
                          ? Icons.visibility_off_outlined
                          : Icons.visibility_outlined,
                      size: 20,
                      color: muted,
                    ),
                  )
                : null,
            hintText: label,
            errorText: errorText,
            filled: true,
            fillColor: HasimTokens.pageBg(context),
            contentPadding: const EdgeInsets.symmetric(
              horizontal: HasimTokens.spaceMd,
              vertical: 14,
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
              borderSide: BorderSide(color: HasimTokens.lineColor(context)),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
              borderSide: const BorderSide(
                color: HasimTokens.primary,
                width: 1.4,
              ),
            ),
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
            ),
          ),
        ),
      ],
    );
  }
}
