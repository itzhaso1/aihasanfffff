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
        DecoratedBox(
          decoration: BoxDecoration(
            color: HasimTokens.cardBg(context),
            borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
            border: Border.all(color: HasimTokens.lineColor(context)),
          ),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: SegmentedButton<ThemeMode>(
              segments: const [
                ButtonSegment(
                  value: ThemeMode.system,
                  icon: Icon(Icons.brightness_auto, size: 18),
                  label: Text('حسب النظام'),
                ),
                ButtonSegment(
                  value: ThemeMode.light,
                  icon: Icon(Icons.light_mode, size: 18),
                  label: Text('فاتح'),
                ),
                ButtonSegment(
                  value: ThemeMode.dark,
                  icon: Icon(Icons.dark_mode, size: 18),
                  label: Text('داكن'),
                ),
              ],
              selected: {themeMode},
              onSelectionChanged: (s) => onThemeChanged(s.first),
            ),
          ),
        ),
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
