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

  static const _outboundRadius = BorderRadius.only(
    topLeft: Radius.circular(12),
    topRight: Radius.circular(12),
    bottomLeft: Radius.circular(4),
    bottomRight: Radius.circular(12),
  );

  static const _inboundRadius = BorderRadius.only(
    topLeft: Radius.circular(12),
    topRight: Radius.circular(12),
    bottomLeft: Radius.circular(12),
    bottomRight: Radius.circular(4),
  );

  static const _bubbleShadow = [
    BoxShadow(color: Color(0x0A000000), blurRadius: 1, offset: Offset(0, 1)),
  ];

  @override
  Widget build(BuildContext context) {
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
    final activeBubbleColor = bubbleColorId ?? 'default';
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
              ? ChatBubbleColorCatalog.outgoingBubble(
                  context,
                  activeBubbleColor,
                )
              : HasimTokens.cardBg(context));
    final fg = HasimTokens.inkColor(context);
    final meta = HasimTokens.mutedColor(context);
    final showCaption = message.content.isNotEmpty && message.content != '📎';

    final openMenu = onContextMenu;
    final bubbleTap = selectionMode ? onToggleSelected : onRetry;

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
                    borderRadius: onPhysicalLeft
                        ? _outboundRadius
                        : _inboundRadius,
                    boxShadow: _bubbleShadow,
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
                      child: AbsorbPointer(
                        absorbing: selectionMode,
                        child: _BubbleBody(
                          message: message,
                          mine: mine,
                          failed: failed,
                          showCaption: showCaption,
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
                  ),
                ),
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
  final Color fg;
  final Color meta;
  final AppLocalizations l10n;
  final bool pinned;
  final bool starred;
  final void Function(String url)? onImageTap;
  final VoidCallback? onMediaUrlExpired;
  final VoidCallback? onOpenFailed;

  static const _textStyle = TextStyle(
    height: 1.4,
    fontSize: 14.5,
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
            size: 12,
            color: meta,
          ),
          const SizedBox(width: 3),
        ],
        if (starred) ...[
          Icon(
            Icons.star_rounded,
            key: const Key('chat-message-starred'),
            size: 13,
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
            fontSize: 11,
            height: 1.1,
            fontWeight: FontWeight.w500,
          ),
        ),
        if (mine) ...[
          const SizedBox(width: 3),
          if (failed) ...[
            Icon(Icons.error_outline_rounded, size: 13, color: Colors.red.shade400),
            const SizedBox(width: 2),
            Icon(Icons.refresh, size: 12, color: Colors.red.shade400),
            const SizedBox(width: 2),
            Text(
              l10n.chatAttachmentRetry,
              style: TextStyle(
                fontSize: 10,
                height: 1.1,
                color: Colors.red.shade400,
              ),
            ),
          ] else if (message.localPending) ...[
            if (message.localUploading ||
                message.localAttachmentPath != null ||
                message.attachments.isNotEmpty)
              SizedBox(
                width: 11,
                height: 11,
                child: CircularProgressIndicator(strokeWidth: 1.5, color: meta),
              )
            else
              Icon(
                Icons.access_time_rounded,
                size: 12,
                color: meta,
              ),
          ] else if (message.id <= 0) ...[
            Icon(
              Icons.done_rounded,
              size: 14,
              color: meta,
            ),
          ] else ...[
            const Icon(
              Icons.done_all_rounded,
              size: 14,
              color: HasimTokens.readReceipt,
            ),
          ],
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

    final header = _SenderHeader(message: message, mine: mine);

    if (textOnly) {
      final quoted = splitQuotedReply(message.content);
      final textRow = Wrap(
        spacing: 6,
        runSpacing: 2,
        crossAxisAlignment: WrapCrossAlignment.end,
        alignment: WrapAlignment.end,
        children: [
          Text(quoted.body, style: _textStyle.copyWith(color: fg)),
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
            const SizedBox(height: 4),
          ],
          textRow,
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
              top: (hasAttachments || pendingUpload) ? 6 : 0,
            ),
            child: Text(message.content, style: _textStyle.copyWith(color: fg)),
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

    final isEmployee = message.isFromEmployee;
    final isAi = message.isFromAi;
    final isWorker = message.isFromWorker;

    final IconData icon;
    final Color color;
    final Color badgeBg;
    final String roleLabel;
    final String name;

    if (isAi) {
      icon = Icons.smart_toy_outlined;
      color = const Color(0xFF6366F1);
      badgeBg = const Color(0x1F6366F1);
      roleLabel = 'ذكاء اصطناعي';
      name = message.senderDisplayName;
    } else if (isWorker) {
      icon = Icons.work_outline;
      color = Colors.amber.shade900;
      badgeBg = Colors.amber.shade100;
      roleLabel = 'موظف مستقل';
      name = message.senderDisplayName;
    } else if (isEmployee) {
      icon = Icons.badge_outlined;
      color = HasimTokens.accentDark;
      badgeBg = HasimTokens.mintSoft;
      roleLabel = 'موظف';
      name = message.senderDisplayName;
    } else {
      icon = Icons.person_outline;
      color = HasimTokens.mutedColor(context);
      badgeBg = HasimTokens.lineColor(context);
      roleLabel = 'عميل';
      name = message.senderDisplayName;
    }

    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 12, color: color),
          const SizedBox(width: 4),
          Flexible(
            child: Text(
              name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w700,
                color: color,
                height: 1.1,
              ),
            ),
          ),
          const SizedBox(width: 5),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
            decoration: BoxDecoration(
              color: badgeBg,
              borderRadius: BorderRadius.circular(4),
              border: Border.all(color: color.withValues(alpha: 0.25), width: 0.5),
            ),
            child: Text(
              roleLabel,
              style: TextStyle(
                fontSize: 9,
                fontWeight: FontWeight.w800,
                color: color,
                height: 1.1,
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
