import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

/// شريط الكتابة أسفل الدردشة — شريط أبيض عائم فوق خلفية المحادثة.
class ChatComposer extends StatefulWidget {
  const ChatComposer({
    super.key,
    required this.controller,
    this.focusNode,
    this.emojiButtonKey,
    this.attachButtonKey,
    required this.sendEnabled,
    required this.onSend,
    required this.onAttach,
    required this.onEmoji,
  });

  final TextEditingController controller;
  final FocusNode? focusNode;
  final Key? emojiButtonKey;
  final Key? attachButtonKey;

  /// يعطّل الإرسال والمرفقات فقط — مربع الكتابة يبقى نشطًا لئلا يضيع التركيز.
  final bool sendEnabled;
  final VoidCallback onSend;
  final VoidCallback onAttach;
  final VoidCallback onEmoji;

  static const double actionSize = 40;

  /// ارتفاع تقريبي للشريط العائم (لحشو أسفل قائمة الرسائل).
  static const double floatingOuterHeight = 76;

  @override
  State<ChatComposer> createState() => _ChatComposerState();
}

class _ChatComposerState extends State<ChatComposer> {
  @override
  void initState() {
    super.initState();
    widget.controller.addListener(_onText);
  }

  @override
  void didUpdateWidget(covariant ChatComposer oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.controller != widget.controller) {
      oldWidget.controller.removeListener(_onText);
      widget.controller.addListener(_onText);
    }
  }

  @override
  void dispose() {
    widget.controller.removeListener(_onText);
    super.dispose();
  }

  void _onText() {
    if (mounted) setState(() {});
  }

  bool get _hasText => widget.controller.text.trim().isNotEmpty;

  static ButtonStyle get _flatIconStyle => IconButton.styleFrom(
        overlayColor: Colors.transparent,
        splashFactory: NoSplash.splashFactory,
      );

  Widget _iconAction({
    required Key? key,
    required VoidCallback? onPressed,
    required IconData icon,
    required Color color,
  }) {
    return IconButton(
      key: key,
      style: _flatIconStyle,
      onPressed: onPressed,
      visualDensity: VisualDensity.compact,
      constraints: const BoxConstraints.tightFor(
        width: ChatComposer.actionSize,
        height: ChatComposer.actionSize,
      ),
      padding: EdgeInsets.zero,
      icon: Icon(icon, color: color, size: 22),
    );
  }

  @override
  Widget build(BuildContext context) {
    final muted = HasimTokens.mutedColor(context);
    final canSend = widget.sendEnabled && _hasText;
    final barColor = HasimTokens.isDark(context) ? HasimTokens.darkCard : Colors.white;

    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 4, 12, 10),
        child: Material(
          color: barColor,
          elevation: 2,
          shadowColor: Colors.black.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(28),
          clipBehavior: Clip.antiAlias,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(4, 4, 6, 4),
            child: Row(
              textDirection: TextDirection.ltr,
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Focus(
                  canRequestFocus: false,
                  skipTraversal: true,
                  child: SizedBox(
                    key: const Key('chat-send-button'),
                    width: ChatComposer.actionSize,
                    height: ChatComposer.actionSize,
                    child: IconButton(
                      style: _flatIconStyle,
                      onPressed: canSend ? widget.onSend : null,
                      padding: EdgeInsets.zero,
                      icon: Icon(
                        canSend ? Icons.send_rounded : Icons.mic_none_rounded,
                        size: 22,
                        color: canSend ? HasimTokens.chatGreen : muted,
                      ),
                    ),
                  ),
                ),
                Expanded(
                  child: Theme(
                    data: Theme.of(context).copyWith(
                      hoverColor: Colors.transparent,
                      highlightColor: Colors.transparent,
                      splashColor: Colors.transparent,
                    ),
                    child: Directionality(
                      textDirection: TextDirection.rtl,
                      child: TextField(
                      controller: widget.controller,
                      focusNode: widget.focusNode,
                      minLines: 1,
                      maxLines: 4,
                      textAlign: TextAlign.right,
                      textInputAction: TextInputAction.send,
                      onSubmitted: (_) {
                        if (canSend) widget.onSend();
                      },
                      decoration: InputDecoration(
                        hintText: 'اكتب رسالة...',
                        hintStyle: TextStyle(
                          color: muted,
                          fontWeight: FontWeight.w500,
                          fontSize: 14.5,
                        ),
                        filled: true,
                        fillColor: barColor,
                        isDense: true,
                        contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                        border: InputBorder.none,
                        enabledBorder: InputBorder.none,
                        focusedBorder: InputBorder.none,
                        disabledBorder: InputBorder.none,
                      ),
                    ),
                    ),
                  ),
                ),
                _iconAction(
                  key: widget.emojiButtonKey ?? const Key('chat-emoji-button'),
                  onPressed: widget.sendEnabled ? widget.onEmoji : null,
                  icon: Icons.emoji_emotions_outlined,
                  color: muted,
                ),
                _iconAction(
                  key: widget.attachButtonKey ?? const Key('chat-attach-button'),
                  onPressed: widget.sendEnabled ? widget.onAttach : null,
                  icon: Icons.attach_file_rounded,
                  color: muted,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
