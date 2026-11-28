import 'dart:collection';
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
import 'package:url_launcher/url_launcher.dart';

/// Pre-resolved styling/localization context shared across visible message bubbles
/// so scrolled rows avoid redundant `Theme.of`, `MediaQuery.of`, and `AppLocalizations.of` lookups.
class ChatBubbleRenderStyle {
  const ChatBubbleRenderStyle({
    required this.dark,
    required this.compact,
    required this.maxWidth,
    required this.fg,
    required this.meta,
    required this.cardBg,
    required this.lineColor,
    required this.outgoingBubbleBg,
    required this.dangerSoftBg,
    required this.retryLabel,
    required this.openLabel,
    required this.uploadingLabel,
    required this.sendingLabel,
    this.mediaHeaders,
  });

  final bool dark;
  final bool compact;
  final double maxWidth;
  final Color fg;
  final Color meta;
  final Color cardBg;
  final Color lineColor;
  final Color outgoingBubbleBg;
  final Color dangerSoftBg;
  final String retryLabel;
  final String openLabel;
  final String uploadingLabel;
  final String sendingLabel;
  final Map<String, String>? mediaHeaders;

  static ChatBubbleRenderStyle resolve(
    BuildContext context, {
    double? maxWidthOverride,
    String? bubbleColorId,
    Map<String, String>? mediaHeaders,
  }) {
    final dark = HasimTokens.isDark(context);
    final compact = HasimLayout.isCompact(context);
    final l10n = AppLocalizations.of(context);
    final activeBubbleColor = bubbleColorId ?? 'default';
    return ChatBubbleRenderStyle(
      dark: dark,
      compact: compact,
      maxWidth:
          maxWidthOverride ?? MediaQuery.sizeOf(context).width * (compact ? 0.80 : 0.68),
      fg: HasimTokens.inkColor(context),
      meta: HasimTokens.mutedColor(context),
      cardBg: HasimTokens.cardBg(context),
      lineColor: HasimTokens.lineColor(context),
      outgoingBubbleBg: ChatBubbleColorCatalog.outgoingBubble(context, activeBubbleColor),
      dangerSoftBg: HasimTokens.dangerSoftColor(context),
      retryLabel: l10n.chatAttachmentRetry,
      openLabel: l10n.chatAttachmentOpen,
      uploadingLabel: l10n.chatAttachmentUploading,
      sendingLabel: l10n.chatAttachmentSending,
      mediaHeaders: mediaHeaders,
    );
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ChatBubbleRenderStyle &&
          dark == other.dark &&
          compact == other.compact &&
          maxWidth == other.maxWidth &&
          fg == other.fg &&
          meta == other.meta &&
          cardBg == other.cardBg &&
          lineColor == other.lineColor &&
          outgoingBubbleBg == other.outgoingBubbleBg &&
          dangerSoftBg == other.dangerSoftBg &&
          retryLabel == other.retryLabel &&
          identical(mediaHeaders, other.mediaHeaders);

  @override
  int get hashCode => Object.hash(
        dark,
        compact,
        maxWidth,
        fg,
        meta,
        outgoingBubbleBg,
        retryLabel,
        identityHashCode(mediaHeaders),
      );
}

class _ParsedBubbleText {
  const _ParsedBubbleText({
    required this.quote,
    required this.body,
    required this.direction,
  });

  final String? quote;
  final String body;
  final TextDirection? direction;
}

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
    this.renderStyle,
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
  final ChatBubbleRenderStyle? renderStyle;

  static final LinkedHashMap<String, _ParsedBubbleText> _parsedTextCache =
      LinkedHashMap<String, _ParsedBubbleText>();
  static const int _maxParsedTextEntries = 360;

  static _ParsedBubbleText _parseText(String content) {
    final cached = _parsedTextCache.remove(content);
    if (cached != null) {
      _parsedTextCache[content] = cached;
      return cached;
    }
    final quoted = splitQuotedReply(content);
    final dir = _detectBidiDirection(quoted.body);
    final entry = _ParsedBubbleText(
      quote: quoted.quote,
      body: quoted.body,
      direction: dir,
    );
    _parsedTextCache[content] = entry;
    while (_parsedTextCache.length > _maxParsedTextEntries) {
      _parsedTextCache.remove(_parsedTextCache.keys.first);
    }
    return entry;
  }

  static final List<String> _twoDigits = List<String>.generate(
    60,
    (i) => i < 10 ? '0$i' : '$i',
    growable: false,
  );

  static String _formatClock(DateTime? dt) {
    if (dt == null) return '';
    final local = dt.toLocal();
    return '${_twoDigits[local.hour]}:${_twoDigits[local.minute]}';
  }

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
    final style = renderStyle;
    final dark = style?.dark ?? HasimTokens.isDark(context);
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
            constraints: BoxConstraints(maxWidth: maxWidth ?? style?.maxWidth ?? 360),
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
                      textDirection: _parseText(message.content).direction,
                      style: TextStyle(
                        fontSize: 12.5,
                        height: 1.35,
                        fontWeight: FontWeight.w600,
                        color: style?.fg ?? HasimTokens.inkColor(context),
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

    final activeBubbleColor = bubbleColorId ?? 'default';
    final mine = message.isOutbound;
    final compact = style?.compact ?? HasimLayout.isCompact(context);
    // Sent (outbound) on the physical left, received on the right — all breakpoints.
    final onPhysicalLeft = mine;
    // Alignment (not Directional) — physical left/right even when the app is RTL.
    final alignment = onPhysicalLeft
        ? Alignment.centerLeft
        : Alignment.centerRight;
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
      bg = style?.dangerSoftBg ?? HasimTokens.dangerSoftColor(context);
      bubbleBorder = Border.all(
        color: dark ? const Color(0x73EF4444) : const Color(0x73EF4444),
        width: 0.9,
      );
    } else if (isAiBubble && activeBubbleColor == 'default') {
      bg = dark ? const Color(0xFF1A2236) : const Color(0xFFEEF4FF);
      bubbleBorder = Border.all(
        color: dark ? const Color(0x6B818CF8) : const Color(0x476366F1),
        width: 0.9,
      );
    } else if (mine) {
      bg = style?.outgoingBubbleBg ??
          ChatBubbleColorCatalog.outgoingBubble(context, activeBubbleColor);
      if (isWorkerBubble) {
        bubbleBorder = Border.all(
          color: dark ? const Color(0x73D97706) : const Color(0x4CD97706),
          width: 0.8,
        );
      } else if (activeBubbleColor == 'default') {
        bubbleBorder = Border.all(
          color: dark ? const Color(0x14FFFFFF) : const Color(0xFFC9F0E8),
          width: 0.7,
        );
      } else {
        bubbleBorder = Border.all(
          color: dark ? const Color(0x18FFFFFF) : const Color(0x14000000),
          width: 0.7,
        );
      }
    } else {
      bg = style?.cardBg ?? HasimTokens.cardBg(context);
      bubbleBorder = Border.all(
        color: style?.lineColor ?? HasimTokens.lineColor(context),
        width: 0.7,
      );
    }

    final fg = style?.fg ?? HasimTokens.inkColor(context);
    final meta = style?.meta ?? HasimTokens.mutedColor(context);
    final retryLabel = style?.retryLabel ?? AppLocalizations.of(context).chatAttachmentRetry;
    final uploadingLabel = style?.uploadingLabel ?? AppLocalizations.of(context).chatAttachmentUploading;
    final sendingLabel = style?.sendingLabel ?? AppLocalizations.of(context).chatAttachmentSending;
    final showCaption = message.content.isNotEmpty && message.content != '📎';
    final radius = _bubbleRadius(
      onPhysicalLeft: onPhysicalLeft,
      groupedPrev: groupedWithPrevious,
      groupedNext: groupedWithNext,
    );

    final openMenu = onContextMenu;
    final bubbleTap = selectionMode ? onToggleSelected : onRetry;

    final Widget body = _BubbleBody(
      message: message,
      mine: mine,
      failed: failed,
      dark: dark,
      showCaption: showCaption,
      showSenderHeader: !groupedWithPrevious,
      pinned: pinned,
      starred: starred,
      fg: fg,
      meta: meta,
      lineColor: style?.lineColor ?? HasimTokens.lineColor(context),
      retryLabel: retryLabel,
      uploadingLabel: uploadingLabel,
      sendingLabel: sendingLabel,
      mediaHeaders: style?.mediaHeaders,
      onImageTap: onImageTap,
      onMediaUrlExpired: onMediaUrlExpired,
      onOpenFailed: onOpenFailed,
    );

    final Widget decoratedBubble = DecoratedBox(
      decoration: BoxDecoration(
        color: bg,
        borderRadius: radius,
        border: bubbleBorder,
      ),
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          compact ? 10 : 12,
          compact ? 5 : 6,
          compact ? 10 : 12,
          compact ? 4 : 5,
        ),
        child: selectionMode ? AbsorbPointer(child: body) : body,
      ),
    );

    final effectiveMaxWidth =
        maxWidth ??
        style?.maxWidth ??
        MediaQuery.sizeOf(context).width * (compact ? 0.80 : 0.68);

    final row = SizedBox(
      width: double.infinity,
      child: Align(
        alignment: alignment,
        child: Padding(
          padding: bubblePadding,
          child: GestureDetector(
            key: Key('chat-message-gesture-${message.id}'),
            behavior: HitTestBehavior.opaque,
            onTap: bubbleTap,
            onLongPressStart: openMenu == null
                ? null
                : (details) {
                    Feedback.forLongPress(context);
                    openMenu(details.globalPosition);
                  },
            onSecondaryTapUp: openMenu == null
                ? null
                : (details) => openMenu(details.globalPosition),
            child: ConstrainedBox(
              constraints: BoxConstraints(maxWidth: effectiveMaxWidth),
              child: decoratedBubble,
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
    required this.dark,
    required this.showCaption,
    required this.showSenderHeader,
    required this.fg,
    required this.meta,
    required this.lineColor,
    required this.retryLabel,
    required this.uploadingLabel,
    required this.sendingLabel,
    this.pinned = false,
    this.starred = false,
    this.mediaHeaders,
    this.onImageTap,
    this.onMediaUrlExpired,
    this.onOpenFailed,
  });

  final MessageModel message;
  final bool mine;
  final bool failed;
  final bool dark;
  final bool showCaption;
  final bool showSenderHeader;
  final bool pinned;
  final bool starred;
  final Color fg;
  final Color meta;
  final Color lineColor;
  final String retryLabel;
  final String uploadingLabel;
  final String sendingLabel;
  final Map<String, String>? mediaHeaders;
  final void Function(String url)? onImageTap;
  final VoidCallback? onMediaUrlExpired;
  final VoidCallback? onOpenFailed;

  static const _textStyle = TextStyle(
    height: 1.36,
    fontSize: 14,
    fontWeight: FontWeight.w500,
  );

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
          ChatMessageBubble._formatClock(message.createdAt),
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
                  retryLabel,
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
    final hasLocation = message.location != null;
    final effectiveCaption = hasLocation && message.messageType == 'location'
        ? false
        : showCaption;
    final textOnly =
        effectiveCaption && !hasAttachments && !pendingUpload && !hasLocation;

    final header = showSenderHeader
        ? _SenderHeader(
            message: message,
            dark: dark,
            metaColor: meta,
            lineColor: lineColor,
          )
        : const SizedBox.shrink();

    if (textOnly) {
      final parsed = ChatMessageBubble._parseText(message.content);
      final textRow = Wrap(
        spacing: 6,
        runSpacing: 2,
        crossAxisAlignment: WrapCrossAlignment.end,
        alignment: WrapAlignment.end,
        children: [
          Text(
            parsed.body,
            textDirection: parsed.direction,
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
          if (parsed.quote != null) ...[
            _QuotedReplyBlock(text: parsed.quote!, mine: mine, meta: meta),
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
              mediaHeaders: mediaHeaders,
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
              mediaHeaders: mediaHeaders,
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
                cacheWidth: 480,
                filterQuality: FilterQuality.low,
                fit: BoxFit.cover,
                errorBuilder: (_, _, _) => const SizedBox.shrink(),
              ),
            ),
          ),
        if (hasLocation)
          Padding(
            padding: const EdgeInsets.only(bottom: 4),
            child: _LocationCard(
              location: message.location!,
              dark: dark,
              fg: fg,
              meta: meta,
              lineColor: lineColor,
            ),
          ),
        if (effectiveCaption)
          Padding(
            padding: EdgeInsets.only(
              top: (hasAttachments || pendingUpload || hasLocation) ? 5 : 0,
            ),
            child: Text(
              message.content,
              textDirection: ChatMessageBubble._parseText(
                message.content,
              ).direction,
              style: _textStyle.copyWith(color: fg),
            ),
          ),
        if (pendingUpload)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              message.localUploading ? uploadingLabel : sendingLabel,
              style: TextStyle(
                color: meta,
                fontSize: 12,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        Padding(
          padding: EdgeInsets.only(
            top: (effectiveCaption || hasAttachments || pendingUpload || hasLocation) ? 4 : 0,
          ),
          child: _metaRow(),
        ),
      ],
    );
  }
}

class _LocationCard extends StatelessWidget {
  const _LocationCard({
    required this.location,
    required this.dark,
    required this.fg,
    required this.meta,
    required this.lineColor,
  });

  final MessageLocationModel location;
  final bool dark;
  final Color fg;
  final Color meta;
  final Color lineColor;

  @override
  Widget build(BuildContext context) {
    final status = location.verificationStatus;
    final Color statusBg;
    final Color statusFg;
    if (status == 'confirmed') {
      statusBg = dark ? const Color(0x2810B981) : const Color(0xFFD1FAE5);
      statusFg = dark ? const Color(0xFF6EE7B7) : const Color(0xFF065F46);
    } else if (status == 'needs_adjustment') {
      statusBg = dark ? const Color(0x28F43F5E) : const Color(0xFFFFE4E6);
      statusFg = dark ? const Color(0xFFFDA4AF) : const Color(0xFF9F1239);
    } else {
      statusBg = dark ? const Color(0x28F59E0B) : const Color(0xFFFEF3C7);
      statusFg = dark ? const Color(0xFFFCD34D) : const Color(0xFF92400E);
    }

    final statusText = location.verificationLabel ??
        switch (status) {
          'confirmed' => 'موقع مؤكد',
          'needs_adjustment' => 'يحتاج مراجعة / تعديل',
          _ => 'بانتظار التأكيد',
        };
    final addressText = (location.formattedAddress?.trim().isNotEmpty ?? false)
        ? location.formattedAddress!.trim()
        : '${location.latitude.toStringAsFixed(5)}, ${location.longitude.toStringAsFixed(5)}';
    final areaParts = <String>[
      if (location.city?.trim().isNotEmpty ?? false) location.city!.trim(),
      if (location.district?.trim().isNotEmpty ?? false) location.district!.trim(),
    ];

    return DecoratedBox(
      key: const Key('chat-message-location-card'),
      decoration: BoxDecoration(
        color: dark ? const Color(0xFF0F2420) : const Color(0xFFF2FBF8),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: dark ? const Color(0xFF1F4E45) : const Color(0xFFBEE9DF),
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(
                      Icons.location_on_rounded,
                      size: 16,
                      color: dark ? const Color(0xFF34D399) : const Color(0xFF067E6B),
                    ),
                    const SizedBox(width: 4),
                    Text(
                      'موقع التوصيل',
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                        color: dark ? const Color(0xFF6EE7B7) : const Color(0xFF067E6B),
                      ),
                    ),
                  ],
                ),
                const SizedBox(width: 8),
                DecoratedBox(
                  decoration: BoxDecoration(
                    color: statusBg,
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                    child: Text(
                      statusText,
                      style: TextStyle(
                        fontSize: 10,
                        fontWeight: FontWeight.w700,
                        color: statusFg,
                      ),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              addressText,
              style: TextStyle(
                fontSize: 12.5,
                height: 1.35,
                fontWeight: FontWeight.w600,
                color: fg,
              ),
            ),
            if (areaParts.isNotEmpty) ...[
              const SizedBox(height: 2),
              Text(
                areaParts.join(' · '),
                style: TextStyle(fontSize: 11, color: meta),
              ),
            ],
            if (location.customerNotes?.trim().isNotEmpty ?? false) ...[
              const SizedBox(height: 5),
              Text(
                'ملاحظة: ${location.customerNotes!.trim()}',
                style: TextStyle(fontSize: 11, color: meta),
              ),
            ],
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                if (location.sourceLabel?.trim().isNotEmpty ?? false)
                  Text(
                    location.sourceLabel!,
                    style: TextStyle(fontSize: 10.5, color: meta, fontWeight: FontWeight.w600),
                  ),
                if (location.accuracyMeters != null)
                  Text(
                    '±${location.accuracyMeters}م',
                    style: TextStyle(fontSize: 10.5, color: meta),
                  ),
                InkWell(
                  onTap: () async {
                    final raw = location.googleMapsUrl ??
                        'https://www.google.com/maps?q=${location.latitude},${location.longitude}';
                    final uri = Uri.tryParse(raw);
                    if (uri != null) {
                      await launchUrl(uri, mode: LaunchMode.externalApplication);
                    }
                  },
                  borderRadius: BorderRadius.circular(8),
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      color: const Color(0xFF067E6B),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: const Padding(
                      padding: EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.map_outlined, size: 12, color: Colors.white),
                          SizedBox(width: 4),
                          Text(
                            'فتح في الخريطة',
                            style: TextStyle(
                              fontSize: 11,
                              fontWeight: FontWeight.w700,
                              color: Colors.white,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// شريط تعريفي واضح بأعلى كل رسالة يحدد بدقة من أرسلها (موظف، ذكاء اصطناعي، عميل، موظف مستقل)
class _SenderHeader extends StatelessWidget {
  const _SenderHeader({
    required this.message,
    required this.dark,
    required this.metaColor,
    required this.lineColor,
  });

  final MessageModel message;
  final bool dark;
  final Color metaColor;
  final Color lineColor;

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
      color = metaColor;
      badgeBg = lineColor.withValues(alpha: dark ? 0.45 : 0.6);
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
  const _QuotedReplyBlock({
    required this.text,
    required this.mine,
    required this.meta,
  });

  final String text;
  final bool mine;
  final Color meta;

  @override
  Widget build(BuildContext context) {
    const accent = HasimTokens.chatGreen;
    return DecoratedBox(
      key: const Key('chat-message-quote'),
      decoration: BoxDecoration(
        color: mine ? const Color(0x0F000000) : const Color(0x0A000000),
        borderRadius: BorderRadius.circular(6),
        border: const BorderDirectional(start: BorderSide(color: accent, width: 3)),
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
            color: meta,
          ),
        ),
      ),
    );
  }
}

class ChatDateChip extends StatelessWidget {
  const ChatDateChip({super.key, required this.date});

  final DateTime date;

  static int? _cachedTodayKey;
  static final Map<int, String> _labelCache = <int, String>{};

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
    final now = DateTime.now();
    final todayKey = now.year * 10000 + now.month * 100 + now.day;
    if (_cachedTodayKey != todayKey) {
      _cachedTodayKey = todayKey;
      _labelCache.clear();
    }
    final local = date.toLocal();
    final dayKey = local.year * 10000 + local.month * 100 + local.day;
    final cached = _labelCache[dayKey];
    if (cached != null) return cached;

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
    final day = DateTime(local.year, local.month, local.day);
    final today = DateTime(now.year, now.month, now.day);
    final result = day == today
        ? 'اليوم · $formatted'
        : (day == today.subtract(const Duration(days: 1))
              ? 'أمس · $formatted'
              : formatted);
    if (_labelCache.length < 120) {
      _labelCache[dayKey] = result;
    }
    return result;
  }
}
