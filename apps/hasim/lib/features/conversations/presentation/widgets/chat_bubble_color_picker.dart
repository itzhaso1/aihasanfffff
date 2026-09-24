import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/conversations/data/chat_bubble_color_catalog.dart';
import 'package:hasim/features/conversations/providers/chat_bubble_color_controller.dart';

/// اختيار لون فقاعات الرسائل المرسلة.
class ChatBubbleColorPicker extends ConsumerWidget {
  const ChatBubbleColorPicker({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final selected = ref.watch(chatBubbleColorControllerProvider);
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
            onTap: () => ref.read(chatBubbleColorControllerProvider.notifier).select(option.id),
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
    final isDefault = option.id == ChatBubbleColorCatalog.defaultId;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
        child: Ink(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
            border: Border.all(
              color: selected ? HasimTokens.chatGreen : HasimTokens.lineColor(context),
              width: selected ? 2 : 1,
            ),
            color: HasimTokens.cardBg(context),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                DecoratedBox(
                  decoration: BoxDecoration(
                    color: swatch,
                    borderRadius: const BorderRadius.only(
                      topLeft: Radius.circular(10),
                      topRight: Radius.circular(10),
                      bottomLeft: Radius.circular(3),
                      bottomRight: Radius.circular(10),
                    ),
                    border: isDefault
                        ? Border.all(color: HasimTokens.lineColor(context))
                        : null,
                  ),
                  child: const SizedBox(width: 28, height: 22),
                ),
                const SizedBox(width: 8),
                Text(
                  option.label,
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: ink,
                  ),
                ),
                if (selected) ...[
                  const SizedBox(width: 4),
                  const Icon(Icons.check_rounded, size: 18, color: HasimTokens.chatGreen),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
