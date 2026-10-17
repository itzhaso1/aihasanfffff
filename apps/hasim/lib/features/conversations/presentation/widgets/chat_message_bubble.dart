import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/conversations/data/chat_bubble_color_catalog.dart';
import 'package:hasim/features/conversations/providers/chat_bubble_color_controller.dart';
import 'package:hasim/features/conversations/providers/conversation_theme_controller.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_attachment_content.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:intl/intl.dart' hide TextDirection;

class ChatMessageBubble extends ConsumerWidget {
  const ChatMessageBubble({
    super.key,
    required this.message,
    this.onRetry,
    this.onImageTap,
    this.onMediaUrlExpired,
    this.onOpenFailed,
    this.maxWidth,
  });

  final MessageModel message;
  final VoidCallback? onRetry;
  final void Function(String url)? onImageTap;
  final VoidCallback? onMediaUrlExpired;
  final VoidCallback? onOpenFailed;
  final double? maxWidth;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (message.isInternalNote) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 24),
        child: Center(
          child: DecoratedBox(
            key: const Key('chat-internal-note'),
            decoration: BoxDecoration(
              color: HasimTokens.cardBg(context),
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: HasimTokens.lineColor(context)),
            ),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    'ملاحظة داخلية',
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      color: HasimTokens.mutedColor(context),
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    message.content,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 12.5,
                      height: 1.35,
                      fontWeight: FontWeight.w600,
                      color: HasimTokens.inkColor(context),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    }

    final l10n = AppLocalizations.of(context);
    final sharedBubble = ref.watch(chatBubbleColorControllerProvider);
    final bubbleOverride = ref
        .watch(conversationThemeProvider(message.conversationId))
        .bubbleColorId;
    final bubbleColorId = bubbleOverride ?? sharedBubble;
    final mine = message.isOutbound;
    final compact = HasimLayout.isCompact(context);
    // Sent (outbound) on the physical left, received on the right — all breakpoints.
    final onPhysicalLeft = mine;
    // Alignment (not Directional) — physical left/right even when the app is RTL.
    final alignment = onPhysicalLeft
        ? Alignment.centerLeft
        : Alignment.centerRight;
    final bubbleMaxFraction = compact ? 0.78 : 0.72;
    final laneWidth = maxWidth != null
        ? maxWidth! / bubbleMaxFraction
        : MediaQuery.sizeOf(context).width;
    // Mobile: tighter lanes; desktop: scale gutter to chat pane width for balanced lanes.
    final innerGutter = compact ? 52.0 : (laneWidth * 0.18).clamp(72.0, 132.0);
    final outerGutter = compact ? 6.0 : 14.0;
    final verticalGap = compact ? 4.0 : 4.0;
    final bubblePadding = EdgeInsets.fromLTRB(
      onPhysicalLeft ? outerGutter : innerGutter,
      verticalGap,
      onPhysicalLeft ? innerGutter : outerGutter,
      verticalGap,
    );
    final failed = message.localFailed;
    final bg = failed
        ? Colors.red.shade50
        : (mine
              ? ChatBubbleColorCatalog.outgoingBubble(context, bubbleColorId)
              : HasimTokens.cardBg(context));
    final fg = HasimTokens.inkColor(context);
    final meta = HasimTokens.mutedColor(context);
    final showCaption = message.content.isNotEmpty && message.content != '📎';

    return SizedBox(
      width: double.infinity,
      child: Align(
        alignment: alignment,
        child: Padding(
          padding: bubblePadding,
          child: InkWell(
            onTap: onRetry,
            borderRadius: BorderRadius.circular(18),
            child: ConstrainedBox(
              constraints: BoxConstraints(
                maxWidth:
                    maxWidth ??
                    MediaQuery.sizeOf(context).width * bubbleMaxFraction,
              ),
              child: DecoratedBox(
                decoration: BoxDecoration(
                  color: bg,
                  borderRadius: BorderRadius.only(
                    topLeft: const Radius.circular(12),
                    topRight: const Radius.circular(12),
                    bottomLeft: Radius.circular(onPhysicalLeft ? 4 : 12),
                    bottomRight: Radius.circular(onPhysicalLeft ? 12 : 4),
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.04),
                      blurRadius: 1,
                      offset: const Offset(0, 1),
                    ),
                  ],
                ),
                child: Padding(
                  padding: EdgeInsets.fromLTRB(
                    compact ? 8 : 10,
                    compact ? 5 : 6,
                    compact ? 8 : 10,
                    compact ? 4 : 5,
                  ),
                  child: Directionality(
                    textDirection: TextDirection.rtl,
                    child: _BubbleBody(
                      message: message,
                      mine: mine,
                      failed: failed,
                      showCaption: showCaption,
                      fg: fg,
                      meta: meta,
                      l10n: l10n,
                      onImageTap: onImageTap,
                      onMediaUrlExpired: onMediaUrlExpired,
                      onOpenFailed: onOpenFailed,
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _BubbleBody extends StatelessWidget {
  const _BubbleBody({
    required this.message,
    required this.mine,
    required this.failed,
    required this.showCaption,
    required this.fg,
    required this.meta,
    required this.l10n,
    this.onImageTap,
    this.onMediaUrlExpired,
    this.onOpenFailed,
  });

  final MessageModel message;
  final bool mine;
  final bool failed;
  final bool showCaption;
  final Color fg;
  final Color meta;
  final AppLocalizations l10n;
  final void Function(String url)? onImageTap;
  final VoidCallback? onMediaUrlExpired;
  final VoidCallback? onOpenFailed;

  static const _textStyle = TextStyle(
    height: 1.25,
    fontSize: 14.5,
    fontWeight: FontWeight.w500,
  );

  Widget _metaRow() {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          message.createdAt == null
              ? ''
              : DateFormat('HH:mm').format(message.createdAt!.toLocal()),
          style: TextStyle(
            color: meta,
            fontSize: 11,
            height: 1.1,
            fontWeight: FontWeight.w500,
          ),
        ),
        if (mine && !failed && !message.localPending) ...[
          const SizedBox(width: 3),
          const Icon(
            Icons.done_all_rounded,
            size: 14,
            color: HasimTokens.readReceipt,
          ),
        ],
        if (message.localPending &&
            (message.localUploading ||
                message.localAttachmentPath != null ||
                message.attachments.isNotEmpty)) ...[
          const SizedBox(width: 4),
          SizedBox(
            width: 11,
            height: 11,
            child: CircularProgressIndicator(strokeWidth: 1.5, color: meta),
          ),
        ],
        if (failed) ...[
          const SizedBox(width: 4),
          Icon(Icons.refresh, size: 13, color: Colors.red.shade400),
          const SizedBox(width: 3),
          Text(
            l10n.chatAttachmentRetry,
            style: TextStyle(
              fontSize: 10,
              height: 1.1,
              color: Colors.red.shade400,
            ),
          ),
        ],
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final pendingUpload =
        message.localPending &&
        message.attachments.isEmpty &&
        message.localAttachmentPath != null;
    final hasAttachments = message.attachments.isNotEmpty;
    final textOnly = showCaption && !hasAttachments && !pendingUpload;

    if (textOnly) {
      return Wrap(
        spacing: 6,
        runSpacing: 2,
        crossAxisAlignment: WrapCrossAlignment.end,
        alignment: WrapAlignment.end,
        children: [
          Text(message.content, style: _textStyle.copyWith(color: fg)),
          _metaRow(),
        ],
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (showCaption)
          Text(message.content, style: _textStyle.copyWith(color: fg)),
        for (final a in message.attachments)
          Padding(
            padding: EdgeInsets.only(top: showCaption ? 6 : 0),
            child: ChatAttachmentContent(
              attachment: a,
              onImageTap: onImageTap,
              onMediaUrlExpired: onMediaUrlExpired,
              onOpenFailed: onOpenFailed,
            ),
          ),
        if (message.localAttachmentPath != null && message.attachments.isEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: Image.file(
                File(message.localAttachmentPath!),
                height: 160,
                width: 220,
                fit: BoxFit.cover,
                errorBuilder: (_, _, _) => const SizedBox.shrink(),
              ),
            ),
          ),
        if (pendingUpload)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              message.localUploading
                  ? l10n.chatAttachmentUploading
                  : l10n.chatAttachmentSending,
              style: TextStyle(
                color: meta,
                fontSize: 12,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        Padding(
          padding: EdgeInsets.only(
            top: (showCaption || hasAttachments || pendingUpload) ? 2 : 0,
          ),
          child: _metaRow(),
        ),
      ],
    );
  }
}

class ChatDateChip extends StatelessWidget {
  const ChatDateChip({super.key, required this.date});

  final DateTime date;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 12),
      child: Center(
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: HasimTokens.cardBg(context),
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: HasimTokens.lineColor(context)),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
            child: Text(
              _label(date),
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: HasimTokens.mutedColor(context),
              ),
            ),
          ),
        ),
      ),
    );
  }

  static String _label(DateTime date) {
    final local = date.toLocal();
    final now = DateTime.now();
    final day = DateTime(local.year, local.month, local.day);
    final today = DateTime(now.year, now.month, now.day);
    const months = [
      'يناير',
      'فبراير',
      'مارس',
      'أبريل',
      'مايو',
      'يونيو',
      'يوليو',
      'أغسطس',
      'سبتمبر',
      'أكتوبر',
      'نوفمبر',
      'ديسمبر',
    ];
    final formatted = '${local.day} ${months[local.month - 1]} ${local.year}';
    if (day == today) {
      return 'اليوم · $formatted';
    }
    if (day == today.subtract(const Duration(days: 1))) {
      return 'أمس · $formatted';
    }
    return formatted;
  }
}
