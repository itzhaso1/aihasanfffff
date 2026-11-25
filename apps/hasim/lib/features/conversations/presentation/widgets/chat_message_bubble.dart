import 'dart:io';

import 'package:flutter/material.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/conversations/data/chat_bubble_color_catalog.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/utils/quoted_reply.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_attachment_content.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_image_grid.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_media_viewer_dialog.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:intl/intl.dart' hide TextDirection;

class ChatMessageBubble extends StatelessWidget {
  const ChatMessageBubble({
    super.key,
    required this.message,
    this.onRetry,
    this.onImageTap,
    this.onMediaUrlExpired,
    this.onOpenFailed,
    this.maxWidth,
    this.onContextMenu,
    this.pinned = false,
    this.starred = false,
    this.selected = false,
    this.selectionMode = false,
    this.onToggleSelected,
    this.bubbleColorId,
    this.groupedWithPrevious = false,
    this.groupedWithNext = false,
  });

  final MessageModel message;
  final VoidCallback? onRetry;
  final void Function(String url)? onImageTap;
  final VoidCallback? onMediaUrlExpired;
  final VoidCallback? onOpenFailed;
  final double? maxWidth;

  /// ضغط مطوّل (الجوال) أو زر أيمن (الكمبيوتر) — يمرَّر موضع المؤشر.
  final void Function(Offset globalPosition)? onContextMenu;
  final bool pinned;
  final bool starred;
  final bool selected;

  /// أثناء التحديد: النقر يبدّل تحديد الرسالة بدل فتح المرفقات.
  final bool selectionMode;
  final VoidCallback? onToggleSelected;
  final String? bubbleColorId;
  final bool groupedWithPrevious;
  final bool groupedWithNext;

  static const _bubbleShadowLight = [
    BoxShadow(color: Color(0x09000000), blurRadius: 3, offset: Offset(0, 1)),
  ];

  static const _bubbleShadowDark = [
    BoxShadow(color: Color(0x28000000), blurRadius: 3, offset: Offset(0, 1)),
  ];

  static BorderRadius _bubbleRadius({
    required bool onPhysicalLeft,
    required bool groupedPrev,
    required bool groupedNext,
  }) {
    const r = Radius.circular(16);
    const stacked = Radius.circular(6);
    const tail = Radius.circular(4);
    if (onPhysicalLeft) {
      return BorderRadius.only(
        topLeft: groupedPrev ? stacked : r,
        topRight: r,
        bottomLeft: groupedNext ? stacked : tail,
        bottomRight: r,
      );
    }
    return BorderRadius.only(
      topLeft: r,
      topRight: groupedPrev ? stacked : r,
      bottomLeft: r,
      bottomRight: groupedNext ? stacked : tail,
    );
  }

  static TextDirection? _detectBidiDirection(String text) {
    for (final rune in text.runes) {
      if ((rune >= 0x0600 && rune <= 0x06FF) ||
          (rune >= 0x0750 && rune <= 0x077F) ||
          (rune >= 0x08A0 && rune <= 0x08FF) ||
          (rune >= 0xFB50 && rune <= 0xFDFF) ||
          (rune >= 0xFE70 && rune <= 0xFEFF) ||
          (rune >= 0x0590 && rune <= 0x05FF)) {
        return TextDirection.rtl;
      }
      if ((rune >= 0x0041 && rune <= 0x005A) ||
          (rune >= 0x0061 && rune <= 0x007A) ||
          (rune >= 0x00C0 && rune <= 0x024F)) {
        return TextDirection.ltr;
      }
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final dark = HasimTokens.isDark(context);
    if (message.isInternalNote) {
      final noteBg = dark ? const Color(0xFF28200D) : const Color(0xFFFFFBEB);
      final noteBorder = dark
          ? const Color(0xFFF59E0B).withValues(alpha: 0.42)
          : const Color(0xFFFCD34D);
      final noteLabelColor =
          dark ? const Color(0xFFFBBF24) : const Color(0xFF92400E);
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 20),
        child: Center(
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: maxWidth ?? 360),
            child: DecoratedBox(
              key: const Key('chat-internal-note'),
              decoration: BoxDecoration(
                color: noteBg,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: noteBorder, width: 0.9),
              ),
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 7,
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                          Icons.lock_outline_rounded,
                          size: 12,
                          color: noteLabelColor,
                        ),
                        const SizedBox(width: 4),
                        Text(
                          'ملاحظة داخلية',
                          style: TextStyle(
                            fontSize: 10.5,
                            fontWeight: FontWeight.w800,
                            color: noteLabelColor,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 2),
                    Text(
                      message.content,
                      textAlign: TextAlign.center,
                      textDirection: _detectBidiDirection(message.content),
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
        ),
      );
    }

    final l10n = AppLocalizations.of(context);
    final activeBubbleColor = bubbleColorId ?? 'default';
    final mine = message.isOutbound;
    final compact = HasimLayout.isCompact(context);
    // Sent (outbound) on the physical left, received on the right — all breakpoints.
    final onPhysicalLeft = mine;
    // Alignment (not Directional) — physical left/right even when the app is RTL.
    final alignment = onPhysicalLeft
        ? Alignment.centerLeft
        : Alignment.centerRight;
    final bubbleMaxFraction = compact ? 0.80 : 0.68;
    final outerGutter = compact ? 8.0 : 14.0;
    final oppositeBreathing = compact ? 24.0 : 48.0;
    final topGap = groupedWithPrevious ? 1.5 : 3.5;
    final bottomGap = groupedWithNext ? 1.5 : 3.5;
    final bubblePadding = EdgeInsets.fromLTRB(
      onPhysicalLeft ? outerGutter : oppositeBreathing,
      topGap,
      onPhysicalLeft ? oppositeBreathing : outerGutter,
      bottomGap,
    );
    final failed = message.outboundPhase == OutboundPhase.failed;
    final isAiBubble = message.isFromAi;
    final isWorkerBubble = message.isFromWorker;

    final Color bg;
    final Border? bubbleBorder;
    if (failed) {
      bg = HasimTokens.dangerSoftColor(context);
      bubbleBorder = Border.all(
        color: HasimTokens.danger.withValues(alpha: 0.45),
        width: 0.9,
      );
    } else if (isAiBubble && activeBubbleColor == 'default') {
      bg = dark ? const Color(0xFF1A2236) : const Color(0xFFEEF4FF);
      bubbleBorder = Border.all(
        color: (dark ? const Color(0xFF818CF8) : const Color(0xFF6366F1))
            .withValues(alpha: dark ? 0.42 : 0.28),
        width: 0.9,
      );
    } else if (mine) {
      bg = ChatBubbleColorCatalog.outgoingBubble(context, activeBubbleColor);
      if (isWorkerBubble) {
        bubbleBorder = Border.all(
          color: const Color(0xFFD97706).withValues(alpha: dark ? 0.45 : 0.30),
          width: 0.8,
        );
      } else if (activeBubbleColor == 'default') {
        bubbleBorder = Border.all(
          color: dark
              ? Colors.white.withValues(alpha: 0.08)
              : const Color(0xFFC9F0E8),
          width: 0.7,
        );
      } else {
        bubbleBorder = null;
      }
    } else {
      bg = HasimTokens.cardBg(context);
      bubbleBorder = Border.all(
        color: HasimTokens.lineColor(context).withValues(alpha: dark ? 0.85 : 0.9),
        width: 0.7,
      );
    }

    final fg = HasimTokens.inkColor(context);
    final meta = HasimTokens.mutedColor(context);
    final showCaption = message.content.isNotEmpty && message.content != '📎';
    final radius = _bubbleRadius(
      onPhysicalLeft: onPhysicalLeft,
      groupedPrev: groupedWithPrevious,
      groupedNext: groupedWithNext,
    );

    final openMenu = onContextMenu;
    final bubbleTap = selectionMode ? onToggleSelected : onRetry;

    final Widget decoratedBubble = DecoratedBox(
      decoration: BoxDecoration(
        color: bg,
        borderRadius: radius,
        border: bubbleBorder,
        boxShadow: dark ? _bubbleShadowDark : _bubbleShadowLight,
      ),
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          compact ? 10 : 12,
          compact ? 5 : 6,
          compact ? 10 : 12,
          compact ? 4 : 5,
        ),
        child: AbsorbPointer(
          absorbing: selectionMode,
          child: _BubbleBody(
            message: message,
            mine: mine,
            failed: failed,
            showCaption: showCaption,
            showSenderHeader: !groupedWithPrevious,
            fg: fg,
            meta: meta,
            l10n: l10n,
            pinned: pinned,
            starred: starred,
            onImageTap: onImageTap,
            onMediaUrlExpired: onMediaUrlExpired,
            onOpenFailed: onOpenFailed,
          ),
        ),
      ),
    );

    final row = SizedBox(
      width: double.infinity,
      child: Align(
        alignment: alignment,
        child: Padding(
          padding: bubblePadding,
          child: GestureDetector(
            key: Key('chat-message-gesture-${message.id}'),
            onLongPressStart: openMenu == null
                ? null
                : (details) {
                    Feedback.forLongPress(context);
                    openMenu(details.globalPosition);
                  },
            onSecondaryTapUp: openMenu == null
                ? null
                : (details) => openMenu(details.globalPosition),
            child: InkWell(
              onTap: bubbleTap,
              borderRadius: radius,
              child: ConstrainedBox(
                constraints: BoxConstraints(
                  maxWidth:
                      maxWidth ??
                      MediaQuery.sizeOf(context).width * bubbleMaxFraction,
                ),
                child: message.attachments.isNotEmpty || message.localPending
                    ? RepaintBoundary(child: decoratedBubble)
                    : decoratedBubble,
              ),
            ),
          ),
        ),
      ),
    );

    if (!selectionMode && !selected) {
      return row;
    }
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: selectionMode ? onToggleSelected : null,
      child: ColoredBox(
        key: selected ? Key('chat-message-selected-${message.id}') : null,
        color: selected
            ? HasimTokens.accent.withValues(alpha: 0.16)
            : Colors.transparent,
        child: row,
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
    required this.showSenderHeader,
    required this.fg,
    required this.meta,
    required this.l10n,
    this.pinned = false,
    this.starred = false,
    this.onImageTap,
    this.onMediaUrlExpired,
    this.onOpenFailed,
  });

  final MessageModel message;
  final bool mine;
  final bool failed;
  final bool showCaption;
  final bool showSenderHeader;
  final Color fg;
  final Color meta;
  final AppLocalizations l10n;
  final bool pinned;
  final bool starred;
  final void Function(String url)? onImageTap;
  final VoidCallback? onMediaUrlExpired;
  final VoidCallback? onOpenFailed;

  static const _textStyle = TextStyle(
    height: 1.36,
    fontSize: 14,
    fontWeight: FontWeight.w500,
  );

  static final DateFormat _timeFormat = DateFormat('HH:mm');

  Widget _metaRow() {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (pinned) ...[
          Icon(
            Icons.push_pin,
            key: const Key('chat-message-pinned'),
            size: 11.5,
            color: meta,
          ),
          const SizedBox(width: 3),
        ],
        if (starred) ...[
          Icon(
            Icons.star_rounded,
            key: const Key('chat-message-starred'),
            size: 12.5,
            color: meta,
          ),
          const SizedBox(width: 3),
        ],
        Text(
          message.createdAt == null
              ? ''
              : _timeFormat.format(message.createdAt!.toLocal()),
          style: TextStyle(
            color: meta,
            fontSize: 10.5,
            height: 1.1,
            fontWeight: FontWeight.w500,
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
        if (mine) ...[
          const SizedBox(width: 3),
          switch (message.outboundPhase) {
            OutboundPhase.failed => Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(
                  Icons.error_outline_rounded,
                  size: 13,
                  color: HasimTokens.danger,
                ),
                const SizedBox(width: 2),
                const Icon(Icons.refresh, size: 12, color: HasimTokens.danger),
                const SizedBox(width: 2),
                Text(
                  l10n.chatAttachmentRetry,
                  style: const TextStyle(
                    fontSize: 10,
                    height: 1.1,
                    fontWeight: FontWeight.w700,
                    color: HasimTokens.danger,
                  ),
                ),
              ],
            ),
            OutboundPhase.sending => SizedBox(
              width: 11,
              height: 11,
              child: CircularProgressIndicator(strokeWidth: 1.5, color: meta),
            ),
            OutboundPhase.pending => Icon(
              Icons.access_time_rounded,
              size: 12,
              color: meta,
            ),
            OutboundPhase.accepted => Icon(
              Icons.schedule_send_outlined,
              size: 12.5,
              color: meta,
            ),
            OutboundPhase.sent => Icon(
              Icons.done_rounded,
              size: 14,
              color: meta,
            ),
            OutboundPhase.delivered => Icon(
              Icons.done_all_rounded,
              size: 14,
              color: meta,
            ),
            OutboundPhase.read => const Icon(
              Icons.done_all_rounded,
              size: 14,
              color: HasimTokens.readReceipt,
            ),
            OutboundPhase.unknown => Icon(
              Icons.help_outline_rounded,
              size: 12.5,
              color: meta,
            ),
          },
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

    final header = showSenderHeader
        ? _SenderHeader(message: message, mine: mine)
        : const SizedBox.shrink();

    if (textOnly) {
      final quoted = splitQuotedReply(message.content);
      final bodyDirection = ChatMessageBubble._detectBidiDirection(quoted.body);
      final textRow = Wrap(
        spacing: 6,
        runSpacing: 2,
        crossAxisAlignment: WrapCrossAlignment.end,
        alignment: WrapAlignment.end,
        children: [
          Text(
            quoted.body,
            textDirection: bodyDirection,
            style: _textStyle.copyWith(color: fg),
          ),
          _metaRow(),
        ],
      );
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          header,
          if (quoted.quote != null) ...[
            _QuotedReplyBlock(text: quoted.quote!, mine: mine),
            const SizedBox(height: 3),
          ],
          textRow,
          if (failed && (message.localError?.trim().isNotEmpty ?? false))
            Padding(
              padding: const EdgeInsets.only(top: 3),
              child: Text(
                message.localError!.trim(),
                style: const TextStyle(
                  fontSize: 11,
                  height: 1.2,
                  color: HasimTokens.danger,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
        ],
      );
    }

    final imageAttachments = message.attachments
        .where((a) => a.kind == 'image')
        .toList();
    final otherAttachments = message.attachments
        .where((a) => a.kind != 'image')
        .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        header,
        if (imageAttachments.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(bottom: 4),
            child: ChatImageGrid(
              attachments: imageAttachments,
              onImageTap: (idx) {
                final a = imageAttachments[idx];
                final url = a.displayUrl ?? '';
                if (url.isNotEmpty) {
                  onImageTap?.call(url);
                }
                ChatMediaViewerDialog.show(
                  context,
                  attachments: imageAttachments,
                  initialIndex: idx,
                  senderName: message.customerName ?? message.userName,
                  timestamp: message.createdAt,
                  caption: message.content,
                );
              },
              onMediaUrlExpired: onMediaUrlExpired,
            ),
          ),
        for (final a in otherAttachments)
          Padding(
            padding: const EdgeInsets.only(top: 4),
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
        if (showCaption)
          Padding(
            padding: EdgeInsets.only(
              top: (hasAttachments || pendingUpload) ? 5 : 0,
            ),
            child: Text(
              message.content,
              textDirection: ChatMessageBubble._detectBidiDirection(
                message.content,
              ),
              style: _textStyle.copyWith(color: fg),
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
            top: (showCaption || hasAttachments || pendingUpload) ? 4 : 0,
          ),
          child: _metaRow(),
        ),
      ],
    );
  }
}

/// شريط تعريفي واضح بأعلى كل رسالة يحدد بدقة من أرسلها (موظف، ذكاء اصطناعي، عميل، موظف مستقل)
class _SenderHeader extends StatelessWidget {
  const _SenderHeader({
    required this.message,
    required this.mine,
  });

  final MessageModel message;
  final bool mine;

  @override
  Widget build(BuildContext context) {
    if (message.isInternalNote) {
      return const SizedBox.shrink();
    }

    final dark = HasimTokens.isDark(context);
    final isEmployee = message.isFromEmployee;
    final isAi = message.isFromAi;
    final isWorker = message.isFromWorker;

    final IconData icon;
    final Color color;
    final Color badgeBg;
    final String roleLabel;
    final String name = message.senderDisplayName;

    if (isAi) {
      icon = Icons.smart_toy_outlined;
      color = dark ? const Color(0xFFA5B4FC) : const Color(0xFF4F46E5);
      badgeBg = color.withValues(alpha: dark ? 0.18 : 0.11);
      roleLabel = 'ذكاء اصطناعي';
    } else if (isWorker) {
      icon = Icons.work_outline;
      color = dark ? const Color(0xFFFBBF24) : const Color(0xFFB45309);
      badgeBg = color.withValues(alpha: dark ? 0.18 : 0.12);
      roleLabel = 'موظف مستقل';
    } else if (isEmployee) {
      icon = Icons.badge_outlined;
      color = dark ? HasimTokens.chatGreen : HasimTokens.accentDark;
      badgeBg = color.withValues(alpha: dark ? 0.18 : 0.11);
      roleLabel = 'موظف';
    } else {
      icon = Icons.person_outline;
      color = HasimTokens.mutedColor(context);
      badgeBg = HasimTokens.lineColor(context).withValues(alpha: dark ? 0.45 : 0.6);
      roleLabel = 'عميل';
    }

    return Padding(
      padding: const EdgeInsets.only(bottom: 2),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 11, color: color),
          const SizedBox(width: 3),
          Flexible(
            child: Text(
              name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 10.5,
                fontWeight: FontWeight.w700,
                color: color,
                height: 1.1,
              ),
            ),
          ),
          const SizedBox(width: 4),
          DecoratedBox(
            decoration: BoxDecoration(
              color: badgeBg,
              borderRadius: BorderRadius.circular(4),
              border: Border.all(
                color: color.withValues(alpha: 0.25),
                width: 0.5,
              ),
            ),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 0.5),
              child: Text(
                roleLabel,
                style: TextStyle(
                  fontSize: 8.5,
                  fontWeight: FontWeight.w800,
                  color: color,
                  height: 1.15,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// اقتباس الرد داخل الفقاعة — مثل واتساب: شريط جانبي ونص مختصر.
class _QuotedReplyBlock extends StatelessWidget {
  const _QuotedReplyBlock({required this.text, required this.mine});

  final String text;
  final bool mine;

  @override
  Widget build(BuildContext context) {
    final accent = HasimTokens.chatGreen;
    return DecoratedBox(
      key: const Key('chat-message-quote'),
      decoration: BoxDecoration(
        color: Colors.black.withValues(alpha: mine ? 0.06 : 0.04),
        borderRadius: BorderRadius.circular(6),
        border: BorderDirectional(start: BorderSide(color: accent, width: 3)),
      ),
      child: Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(8, 5, 8, 5),
        child: Text(
          text,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            fontSize: 12.5,
            height: 1.3,
            fontWeight: FontWeight.w500,
            color: HasimTokens.mutedColor(context),
          ),
        ),
      ),
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
