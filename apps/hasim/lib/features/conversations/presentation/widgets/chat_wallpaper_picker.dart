import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/conversations/data/chat_wallpaper_catalog.dart';
import 'package:hasim/features/conversations/providers/chat_wallpaper_controller.dart';
import 'package:hasim/features/conversations/providers/conversation_theme_controller.dart';

/// اختيار خلفية شاشة المحادثة.
class ChatWallpaperPicker extends ConsumerWidget {
  const ChatWallpaperPicker({super.key, this.compact = false, this.conversationId});

  final bool compact;
  final int? conversationId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final shared = ref.watch(chatWallpaperControllerProvider);
    final override = conversationId == null ? null : ref.watch(conversationThemeProvider(conversationId!)).wallpaperId;
    final selected = override ?? shared;
    const displayOrder = [
      'color_doodle',
      'pokemon',
      'mr_doodle',
      'geometric',
      'mario',
      'social_green',
      'neon_lines',
      'lifestyle',
      'dark_icons',
      'neon_mind',
      'light_doodle',
      'owl',
      'space_doodle',
      'stickers',
      'night_doodle',
      'memphis',
      'game_doodle',
    ];
    final options = [...ChatWallpaperCatalog.options]..sort((a, b) {
        final ai = displayOrder.indexOf(a.id);
        final bi = displayOrder.indexOf(b.id);
        return (ai < 0 ? 99 : ai).compareTo(bi < 0 ? 99 : bi);
      });

    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: options.length,
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: compact ? 3 : 5,
        mainAxisSpacing: 10,
        crossAxisSpacing: 10,
        childAspectRatio: 0.72,
      ),
      itemBuilder: (context, index) {
        final option = options[index];
        return ChatWallpaperPreviewCard(
          option: option,
          selected: option.id == selected,
          onTap: () {
            final id = conversationId;
            if (id == null) {
              ref.read(chatWallpaperControllerProvider.notifier).select(option.id);
            } else {
              ref.read(conversationThemeProvider(id).notifier).setWallpaper(option.id);
            }
          },
        );
      },
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
    const radius = 16.0;
    return Material(
      color: Colors.transparent,
      child: InkWell(
        key: Key('chat-theme-${option.id}'),
        onTap: onTap,
        borderRadius: BorderRadius.circular(radius),
        child: Ink(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(radius),
            border: Border.all(
              color: selected ? HasimTokens.chatGreen : const Color(0xFFE6EEEC),
              width: selected ? 2.4 : 1,
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
