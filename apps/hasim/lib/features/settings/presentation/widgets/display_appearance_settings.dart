import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_bubble_color_picker.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_wallpaper_picker.dart';
/// المظهر (فاتح/داكن) + سمات خلفية المحادثة — في شاشة واحدة.
class DisplayAppearanceSettings extends ConsumerWidget {
  const DisplayAppearanceSettings({
    super.key,
    required this.themeMode,
    required this.onThemeChanged,
    this.compactPreview = false,
    this.pageTitle,
  });

  final ThemeMode themeMode;
  final ValueChanged<ThemeMode> onThemeChanged;
  final bool compactPreview;
  final String? pageTitle;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final muted = HasimTokens.mutedColor(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (pageTitle != null) ...[
          Text(
            pageTitle!,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: HasimTokens.inkColor(context),
                ),
          ),
          const SizedBox(height: 16),
        ],
        Text(
          'المظهر',
          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w700,
                color: HasimTokens.inkColor(context),
              ),
        ),
        const SizedBox(height: 10),
        _AppearanceModeBar(themeMode: themeMode, onChanged: onThemeChanged),
        const SizedBox(height: 24),
        Text(
          'السمات',
          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w700,
                color: HasimTokens.inkColor(context),
              ),
        ),
        const SizedBox(height: 6),
        Text(
          'اختر صورة أو خلفية لشاشة المحادثة',
          style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: muted),
        ),
        const SizedBox(height: 16),
        ChatWallpaperPicker(compact: compactPreview),
        const SizedBox(height: 24),
        Text(
          'لون فقاعاتك',
          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w700,
                color: HasimTokens.inkColor(context),
              ),
        ),
        const SizedBox(height: 6),
        Text(
          'لون رسائلك المرسلة في المحادثة',
          style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: muted),
        ),
        const SizedBox(height: 12),
        const ChatBubbleColorPicker(),
      ],
    );
  }
}

class _AppearanceModeBar extends StatelessWidget {
  const _AppearanceModeBar({required this.themeMode, required this.onChanged});

  final ThemeMode themeMode;
  final ValueChanged<ThemeMode> onChanged;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(6),
        child: Row(
          textDirection: TextDirection.ltr,
          children: [
            _ModePill(
              label: 'داكن',
              icon: Icons.dark_mode_outlined,
              selected: themeMode == ThemeMode.dark,
              onTap: () => onChanged(ThemeMode.dark),
            ),
            _ModePill(
              label: 'فاتح',
              icon: Icons.light_mode_outlined,
              selected: themeMode == ThemeMode.light,
              onTap: () => onChanged(ThemeMode.light),
            ),
            _ModePill(
              label: 'حسب النظام',
              icon: Icons.settings_outlined,
              selected: themeMode == ThemeMode.system,
              onTap: () => onChanged(ThemeMode.system),
            ),
          ],
        ),
      ),
    );
  }
}

class _ModePill extends StatelessWidget {
  const _ModePill({
    required this.label,
    required this.icon,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final IconData icon;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final color = selected ? HasimTokens.chatGreen : HasimTokens.mutedColor(context);
    return Expanded(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 3),
        child: Material(
          color: selected ? HasimTokens.mintFill(context) : Colors.transparent,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
            side: BorderSide(color: selected ? HasimTokens.chatGreen : Colors.transparent, width: 1.4),
          ),
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(14),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 10),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(selected ? Icons.check_rounded : icon, size: 16, color: color),
                  const SizedBox(width: 4),
                  Flexible(
                    child: Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 13),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
