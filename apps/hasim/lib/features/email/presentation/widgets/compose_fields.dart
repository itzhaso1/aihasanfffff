import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class ComposeSectionLabel extends StatelessWidget {
  const ComposeSectionLabel(this.text, {super.key});
  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsetsDirectional.only(start: 4, bottom: 8),
      child: Text(
        text,
        textAlign: TextAlign.start,
        style: Theme.of(context).textTheme.titleSmall?.copyWith(
              fontWeight: FontWeight.w800,
              color: HasimTokens.inkColor(context),
            ),
      ),
    );
  }
}

class ComposeCard extends StatelessWidget {
  const ComposeCard({
    super.key,
    required this.child,
    this.onTap,
    this.minHeight,
  });

  final Widget child;
  final VoidCallback? onTap;
  final double? minHeight;

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(HasimTokens.radiusCard);
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context),
        borderRadius: radius,
        border: Border.all(color: HasimTokens.fieldBorder.withValues(alpha: HasimTokens.isDark(context) ? 0.35 : 1)),
        boxShadow: HasimTokens.softShadow(context),
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: radius,
          child: ConstrainedBox(
            constraints: BoxConstraints(minHeight: minHeight ?? 56),
            child: child,
          ),
        ),
      ),
    );
  }
}

class ComposeAccountCard extends StatelessWidget {
  const ComposeAccountCard({
    super.key,
    required this.loading,
    required this.accountsEmpty,
    required this.email,
    required this.onTap,
  });

  final bool loading;
  final bool accountsEmpty;
  final String? email;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final muted = HasimTokens.mutedColor(context);
    final Widget trailing;
    if (loading) {
      trailing = const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2));
    } else if (accountsEmpty) {
      trailing = Icon(Icons.info_outline, color: muted);
    } else {
      trailing = Icon(Icons.keyboard_arrow_down_rounded, color: muted);
    }

    return ComposeCard(
      onTap: accountsEmpty || loading ? null : onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        child: Row(
          children: [
            Container(
              width: 42,
              height: 42,
              decoration: BoxDecoration(
                color: HasimTokens.mintFill(context),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.mail_outline_rounded, color: AppTheme.brand, size: 20),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                accountsEmpty
                    ? 'لا توجد حسابات بريد مرتبطة. أضف حساباً من لوحة الإدارة.'
                    : (email ?? 'اختر حساب الإرسال'),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.start,
                textDirection: email == null || accountsEmpty ? TextDirection.rtl : TextDirection.ltr,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: HasimTokens.inkColor(context),
                      height: 1.35,
                    ),
              ),
            ),
            const SizedBox(width: 8),
            trailing,
          ],
        ),
      ),
    );
  }
}

class ComposeRecipientCard extends StatelessWidget {
  const ComposeRecipientCard({
    super.key,
    required this.chips,
    required this.onTap,
  });

  final List<Widget> chips;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ComposeCard(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 42,
              height: 42,
              decoration: BoxDecoration(
                color: HasimTokens.mintFill(context),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.groups_outlined, color: AppTheme.brand, size: 20),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: chips.isEmpty
                  ? Padding(
                      padding: const EdgeInsets.symmetric(vertical: 10),
                      child: Text(
                        'اضغط لاختيار مستلمين',
                        textAlign: TextAlign.start,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: HasimTokens.mutedColor(context),
                              fontWeight: FontWeight.w600,
                            ),
                      ),
                    )
                  : Wrap(spacing: 6, runSpacing: 6, children: chips),
            ),
          ],
        ),
      ),
    );
  }
}

class ComposeTextField extends StatelessWidget {
  const ComposeTextField({
    super.key,
    required this.controller,
    required this.hintText,
    this.fieldKey,
    this.minLines = 1,
    this.maxLines = 1,
    this.textDirection,
    this.textAlign,
    this.onChanged,
  });

  final TextEditingController controller;
  final String hintText;
  final Key? fieldKey;
  final int minLines;
  final int maxLines;
  final TextDirection? textDirection;
  final TextAlign? textAlign;
  final ValueChanged<String>? onChanged;

  @override
  Widget build(BuildContext context) {
    final isMultiline = maxLines > 1;
    return ComposeCard(
      minHeight: isMultiline ? 180 : 56,
      child: TextField(
        key: fieldKey,
        controller: controller,
        minLines: minLines,
        maxLines: maxLines,
        textDirection: textDirection,
        textAlign: textAlign ?? TextAlign.start,
        onChanged: onChanged,
        style: Theme.of(context).textTheme.bodyLarge?.copyWith(
              color: HasimTokens.inkColor(context),
              height: 1.5,
            ),
        decoration: InputDecoration(
          hintText: hintText,
          hintStyle: TextStyle(color: HasimTokens.mutedColor(context), fontWeight: FontWeight.w500),
          filled: true,
          fillColor: Colors.transparent,
          border: InputBorder.none,
          enabledBorder: InputBorder.none,
          focusedBorder: InputBorder.none,
          contentPadding: EdgeInsets.symmetric(
            horizontal: 16,
            vertical: isMultiline ? 16 : 14,
          ),
        ),
      ),
    );
  }
}

class ComposeToolbar extends StatelessWidget {
  const ComposeToolbar({
    super.key,
    required this.onAttachment,
    required this.onImage,
    required this.onLink,
    required this.onEmoji,
  });

  final VoidCallback onAttachment;
  final VoidCallback onImage;
  final VoidCallback onLink;
  final VoidCallback onEmoji;

  @override
  Widget build(BuildContext context) {
    final muted = HasimTokens.mutedColor(context);
    return Padding(
      padding: const EdgeInsetsDirectional.only(top: 8, start: 4),
      child: Row(
        children: [
          _ToolIcon(tooltip: 'مرفق', icon: Icons.attach_file_rounded, color: muted, onTap: onAttachment),
          _ToolIcon(tooltip: 'صورة', icon: Icons.image_outlined, color: muted, onTap: onImage),
          _ToolIcon(tooltip: 'رابط', icon: Icons.link_rounded, color: muted, onTap: onLink),
          _ToolIcon(tooltip: 'رمز تعبيري', icon: Icons.emoji_emotions_outlined, color: muted, onTap: onEmoji),
        ],
      ),
    );
  }
}

class _ToolIcon extends StatelessWidget {
  const _ToolIcon({
    required this.tooltip,
    required this.icon,
    required this.color,
    required this.onTap,
  });

  final String tooltip;
  final IconData icon;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: tooltip,
      visualDensity: VisualDensity.compact,
      onPressed: onTap,
      icon: Icon(icon, color: color, size: 22),
    );
  }
}
