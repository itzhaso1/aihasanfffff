import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_panel_width.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_selection.dart';

/// مقبض سحب بين قائمة المحادثات ومساحة الدردشة — سطح المكتب فقط.
class ConversationPanelResizeHandle extends ConsumerStatefulWidget {
  const ConversationPanelResizeHandle({
    super.key,
    this.navRailWidth = HasimLayout.navRailWidth,
  });

  final double navRailWidth;

  @override
  ConsumerState<ConversationPanelResizeHandle> createState() =>
      _ConversationPanelResizeHandleState();
}

class _ConversationPanelResizeHandleState
    extends ConsumerState<ConversationPanelResizeHandle> {
  bool _dragging = false;
  bool _hover = false;

  @override
  Widget build(BuildContext context) {
    final accent = HasimTokens.accent.withValues(
      alpha: _dragging || _hover ? 0.85 : 0.35,
    );

    return MouseRegion(
      cursor: SystemMouseCursors.resizeColumn,
      onEnter: (_) => setState(() => _hover = true),
      onExit: (_) => setState(() => _hover = false),
      child: GestureDetector(
        key: const Key('conversation-panel-resize-handle'),
        behavior: HitTestBehavior.translucent,
        onHorizontalDragStart: (_) => setState(() => _dragging = true),
        onHorizontalDragEnd: (_) => setState(() => _dragging = false),
        onHorizontalDragCancel: () => setState(() => _dragging = false),
        onHorizontalDragUpdate: (details) {
          final drawerOpen = ref.read(desktopCustomerDrawerOpenProvider);
          ref
              .read(desktopConversationPanelWidthProvider.notifier)
              .adjustByDrag(
                context,
                details.delta.dx,
                customerDrawerOpen: drawerOpen,
                navRailWidth: widget.navRailWidth,
              );
        },
        onDoubleTap: () {
          ref
              .read(desktopConversationPanelWidthProvider.notifier)
              .resetToSuggested(context);
        },
        child: SizedBox(
          width: HasimLayout.conversationPanelResizeHandleWidth,
          child: ColoredBox(
            color: _dragging
                ? HasimTokens.mintChip.withValues(alpha: 0.5)
                : Colors.transparent,
            child: Center(
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 120),
                width: _dragging || _hover ? 3 : 1,
                height: double.infinity,
                margin: const EdgeInsets.symmetric(vertical: 8),
                decoration: BoxDecoration(
                  color: _dragging || _hover ? accent : Colors.transparent,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
