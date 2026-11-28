import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_card.dart';
import 'package:hasim/core/widgets/hasim_settings_body.dart';
import 'package:hasim/features/conversations/data/chat_bubble_color_catalog.dart';
import 'package:hasim/features/conversations/data/chat_wallpaper_catalog.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_bubble_color_picker.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_wallpaper_picker.dart';
import 'package:hasim/features/conversations/providers/chat_bubble_color_controller.dart';
import 'package:hasim/features/conversations/providers/chat_wallpaper_controller.dart';

/// المظهر (فاتح/داكن) + معاينة حية + سمات خلفية المحادثة — في شاشة واحدة.
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
    final wallpaperId = ref.watch(chatWallpaperControllerProvider);
    final bubbleColorId = ref.watch(chatBubbleColorControllerProvider);

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
        HasimCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const HasimSettingsSectionHeader(
                icon: Icons.brightness_6_outlined,
                title: 'المظهر',
                subtitle: 'اختر السطوع المناسب أو اربطه بإعدادات الجهاز',
              ),
              const SizedBox(height: 14),
              _AppearanceModeBar(
                themeMode: themeMode,
                onChanged: onThemeChanged,
              ),
            ],
          ),
        ),
        const SizedBox(height: HasimTokens.spaceMd),
        _AppearanceLivePreviewCard(
          wallpaperId: wallpaperId,
          bubbleColorId: bubbleColorId,
        ),
        const SizedBox(height: HasimTokens.spaceMd),
        HasimCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const HasimSettingsSectionHeader(
                icon: Icons.wallpaper_outlined,
                title: 'السمات',
                subtitle: 'اختر صورة أو خلفية لشاشة المحادثة',
              ),
              const SizedBox(height: 14),
              ChatWallpaperPicker(compact: compactPreview),
            ],
          ),
        ),
        const SizedBox(height: HasimTokens.spaceMd),
        HasimCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const HasimSettingsSectionHeader(
                icon: Icons.color_lens_outlined,
                title: 'لون فقاعاتك',
                subtitle: 'لون رسائلك المرسلة في المحادثة',
              ),
              const SizedBox(height: 14),
              const ChatBubbleColorPicker(),
            ],
          ),
        ),
      ],
    );
  }
}

class _AppearanceLivePreviewCard extends StatelessWidget {
  const _AppearanceLivePreviewCard({
    required this.wallpaperId,
    required this.bubbleColorId,
  });

  final String wallpaperId;
  final String bubbleColorId;

  @override
  Widget build(BuildContext context) {
    final dark = HasimTokens.isDark(context);
    final bubbleColor =
        ChatBubbleColorCatalog.find(bubbleColorId)?.resolve(context) ??
        HasimTokens.outgoingBubble(context);
    final inboundBg = dark ? const Color(0xFF1F2C33) : Colors.white;
    final bubbleInk = dark ? Colors.white : HasimTokens.ink;

    return HasimCard(
      padding: EdgeInsets.zero,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Padding(
            padding: EdgeInsets.fromLTRB(16, 14, 16, 12),
            child: HasimSettingsSectionHeader(
              icon: Icons.visibility_outlined,
              title: 'معاينة المحادثة',
              subtitle: 'تحديث مباشر عند تغيير السمة أو لون الفقاعات',
            ),
          ),
          ClipRRect(
            borderRadius: const BorderRadius.vertical(
              bottom: Radius.circular(HasimTokens.radiusMd - 1),
            ),
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: HasimTokens.chatCanvas(context),
                image: ChatWallpaperCatalog.decorationImage(wallpaperId),
              ),
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: 14,
                  vertical: 14,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Align(
                      alignment: AlignmentDirectional.centerEnd,
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 12,
                          vertical: 8,
                        ),
                        decoration: BoxDecoration(
                          color: inboundBg,
                          borderRadius: BorderRadius.circular(14),
                          boxShadow: HasimTokens.softShadow(context),
                        ),
                        child: Text(
                          'مرحباً، أود الاستفسار عن تفاصيل الطلب',
                          style: Theme.of(context).textTheme.bodyMedium
                              ?.copyWith(color: bubbleInk),
                        ),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Align(
                      alignment: AlignmentDirectional.centerStart,
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 12,
                          vertical: 8,
                        ),
                        decoration: BoxDecoration(
                          color: bubbleColor,
                          borderRadius: BorderRadius.circular(14),
                          boxShadow: HasimTokens.softShadow(context),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Flexible(
                              child: Text(
                                'أهلاً بك! يسعدنا خدمتك الآن',
                                style: Theme.of(context).textTheme.bodyMedium
                                    ?.copyWith(color: bubbleInk),
                              ),
                            ),
                            const SizedBox(width: 6),
                            const Icon(
                              Icons.done_all_rounded,
                              size: 15,
                              color: HasimTokens.readReceipt,
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
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
    final color = selected
        ? HasimTokens.chatGreen
        : HasimTokens.mutedColor(context);
    return Expanded(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 3),
        child: Material(
          color: selected ? HasimTokens.mintFill(context) : Colors.transparent,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
            side: BorderSide(
              color: selected ? HasimTokens.chatGreen : Colors.transparent,
              width: 1.4,
            ),
          ),
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(14),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 10),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(
                    selected ? Icons.check_rounded : icon,
                    size: 16,
                    color: color,
                  ),
                  const SizedBox(width: 4),
                  Flexible(
                    child: Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: color,
                        fontWeight: FontWeight.w700,
                        fontSize: 13,
                      ),
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
