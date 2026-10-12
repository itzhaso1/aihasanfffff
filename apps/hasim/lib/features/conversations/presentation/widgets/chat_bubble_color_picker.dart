import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/conversations/data/chat_bubble_color_catalog.dart';
import 'package:hasim/features/conversations/providers/chat_bubble_color_controller.dart';
import 'package:hasim/features/conversations/providers/conversation_theme_controller.dart';

/// اختيار لون فقاعات الرسائل المرسلة.
class ChatBubbleColorPicker extends ConsumerWidget {
  const ChatBubbleColorPicker({super.key, this.conversationId});

  final int? conversationId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final shared = ref.watch(chatBubbleColorControllerProvider);
    final override = conversationId == null ? null : ref.watch(conversationThemeProvider(conversationId!)).bubbleColorId;
    final selected = override ?? shared;
    final ink = HasimTokens.inkColor(context);

    return Wrap(
      spacing: 10,
      runSpacing: 10,
      children: [
        for (final option in ChatBubbleColorCatalog.options)
          _BubbleColorChip(
            key: Key('chat-bubble-color-${option.id}'),
            option: option,
            selected: option.id == selected,
            onTap: () {
              final id = conversationId;
              if (id == null) {
                ref.read(chatBubbleColorControllerProvider.notifier).select(option.id);
              } else {
                ref.read(conversationThemeProvider(id).notifier).setBubble(option.id);
              }
            },
            ink: ink,
          ),
      ],
    );
  }
}

class _BubbleColorChip extends StatelessWidget {
  const _BubbleColorChip({
    super.key,
    required this.option,
    required this.selected,
    required this.onTap,
    required this.ink,
  });

  final ChatBubbleColorOption option;
  final bool selected;
  final VoidCallback onTap;
  final Color ink;

  @override
  Widget build(BuildContext context) {
    final swatch = option.previewSwatch(context);

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(22),
        child: Ink(
          decoration: BoxDecoration(
            color: HasimTokens.cardBg(context),
            borderRadius: BorderRadius.circular(22),
            border: Border.all(
              color: selected ? HasimTokens.chatGreen : HasimTokens.lineColor(context),
              width: selected ? 1.6 : 1,
            ),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 16,
                  height: 16,
                  decoration: BoxDecoration(
                    color: swatch,
                    shape: BoxShape.circle,
                    border: Border.all(color: const Color(0xFFE6EEEC)),
                  ),
                ),
                const SizedBox(width: 8),
                Text(
                  option.label,
                  style: TextStyle(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                    color: ink,
                  ),
                ),
                if (selected) ...[
                  const SizedBox(width: 6),
                  const Icon(Icons.check_rounded, size: 16, color: HasimTokens.chatGreen),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
