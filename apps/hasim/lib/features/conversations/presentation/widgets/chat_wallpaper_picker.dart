import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/conversations/data/chat_wallpaper_catalog.dart';
import 'package:hasim/features/conversations/providers/chat_wallpaper_controller.dart';

/// اختيار خلفية شاشة المحادثة.
class ChatWallpaperPicker extends ConsumerWidget {
  const ChatWallpaperPicker({super.key, this.compact = false});

  final bool compact;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final selected = ref.watch(chatWallpaperControllerProvider);
    final screenW = MediaQuery.sizeOf(context).width;
    final cardWidth = compact
        ? (screenW - 32 - 12) / 2
        : (screenW * 0.28).clamp(140.0, 200.0);

    return Wrap(
      spacing: 12,
      runSpacing: 12,
      alignment: WrapAlignment.center,
      children: [
        for (final option in ChatWallpaperCatalog.options)
          SizedBox(
            width: cardWidth,
            child: ChatWallpaperPreviewCard(
              option: option,
              selected: option.id == selected,
              onTap: () => ref.read(chatWallpaperControllerProvider.notifier).select(option.id),
            ),
          ),
      ],
    );
  }
}

class ChatWallpaperPreviewCard extends StatelessWidget {
  const ChatWallpaperPreviewCard({
    super.key,
    required this.option,
    required this.selected,
    required this.onTap,
  });

  final ChatWallpaperOption option;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    const radius = 18.0;
    return Material(
      color: Colors.transparent,
      elevation: selected ? 4 : 1,
      shadowColor: Colors.black.withValues(alpha: 0.12),
      borderRadius: BorderRadius.circular(radius),
      child: InkWell(
        key: Key('chat-theme-${option.id}'),
        onTap: onTap,
        borderRadius: BorderRadius.circular(radius),
        child: Ink(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(radius),
            border: Border.all(
              color: selected ? HasimTokens.chatGreen : HasimTokens.lineColor(context),
              width: selected ? 2.5 : 1,
            ),
          ),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(radius - 1),
            child: AspectRatio(
              aspectRatio: 9 / 16,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  DecoratedBox(
                    decoration: BoxDecoration(
                      color: HasimTokens.chatCanvasLight,
                      image: ChatWallpaperCatalog.decorationImage(option.id),
                    ),
                  ),
                  Align(
                    alignment: Alignment.bottomCenter,
                    child: Container(
                      width: double.infinity,
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.bottomCenter,
                          end: Alignment.topCenter,
                          colors: [
                            Colors.black.withValues(alpha: 0.5),
                            Colors.black.withValues(alpha: 0.05),
                          ],
                        ),
                      ),
                      padding: const EdgeInsets.fromLTRB(8, 16, 8, 10),
                      child: Text(
                        option.label,
                        textAlign: TextAlign.center,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w700,
                          fontSize: 13,
                        ),
                      ),
                    ),
                  ),
                  if (selected)
                    const Align(
                      alignment: Alignment.topRight,
                      child: Padding(
                        padding: EdgeInsets.all(8),
                        child: DecoratedBox(
                          decoration: BoxDecoration(color: HasimTokens.chatGreen, shape: BoxShape.circle),
                          child: Padding(
                            padding: EdgeInsets.all(4),
                            child: Icon(Icons.check_rounded, color: Colors.white, size: 16),
                          ),
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
