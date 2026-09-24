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
  });

  final TextEditingController controller;
  final String label;
  final IconData? icon;
  final TextDirection? textDirection;
  final TextAlign? textAlign;
  final bool obscureText;
  final VoidCallback? onToggleObscure;

  @override
  Widget build(BuildContext context) {
    final muted = HasimTokens.mutedColor(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          label,
          style: Theme.of(context).textTheme.labelLarge?.copyWith(
                fontWeight: FontWeight.w600,
                color: HasimTokens.inkColor(context),
              ),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: controller,
          obscureText: obscureText,
          textDirection: textDirection,
          textAlign: textAlign ?? TextAlign.start,
          decoration: InputDecoration(
            prefixIcon: icon != null ? Icon(icon, size: 20, color: muted) : null,
            suffixIcon: onToggleObscure != null
                ? IconButton(
                    onPressed: onToggleObscure,
                    icon: Icon(
                      obscureText ? Icons.visibility_off_outlined : Icons.visibility_outlined,
                      size: 20,
                      color: muted,
                    ),
                  )
                : null,
            hintText: label,
            filled: true,
            fillColor: HasimTokens.pageBg(context),
            contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(12),
              borderSide: BorderSide(color: HasimTokens.lineColor(context)),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(12),
              borderSide: const BorderSide(color: HasimTokens.primary, width: 1.4),
            ),
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
          ),
        ),
      ],
    );
  }
}
