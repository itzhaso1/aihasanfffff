import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

/// شريط الكتابة أسفل الدردشة — إدخال مستدير مع زر صوت منفصل.
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
    this.onCamera,
    required this.onEmoji,
    this.onRecord,
    this.onCancelRecord,
    this.onConfirmRecord,
    this.onTogglePause,
    this.recording = false,
    this.recordElapsed = Duration.zero,
    this.voicePaused = false,
    this.voiceAmplitude = 0.35,
    this.emojiAsKeyboard = false,
    this.bottomSafe = true,
  });

  final TextEditingController controller;
  final FocusNode? focusNode;
  final Key? emojiButtonKey;
  final Key? attachButtonKey;

  /// يعطّل الإرسال والمرفقات فقط — مربع الكتابة يبقى نشطًا لئلا يضيع التركيز.
  final bool sendEnabled;
  final VoidCallback onSend;
  final VoidCallback onAttach;
  final VoidCallback? onCamera;
  final VoidCallback onEmoji;
  final VoidCallback? onRecord;
  final VoidCallback? onCancelRecord;
  final VoidCallback? onConfirmRecord;
  final VoidCallback? onTogglePause;
  final bool recording;
  final Duration recordElapsed;
  final bool voicePaused;
  final double voiceAmplitude;
  final bool emojiAsKeyboard;
  final bool bottomSafe;

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
    final canRecord =
        widget.sendEnabled && widget.onRecord != null && !_hasText;
    final isDark = HasimTokens.isDark(context);
    final barColor = isDark ? const Color(0xFF20292B) : Colors.white;
    final micBackground = isDark
        ? const Color(0xFFD7DADB)
        : HasimTokens.chatGreen;
    final micForeground = isDark ? const Color(0xFF20292B) : Colors.white;

    if (widget.recording) {
      return _recordingBar(context, barColor);
    }

    return SafeArea(
      top: false,
      bottom: widget.bottomSafe,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 4, 12, 10),
        child: Row(
          textDirection: TextDirection.ltr,
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            Material(
              color: micBackground,
              shape: const CircleBorder(),
              clipBehavior: Clip.antiAlias,
              child: InkWell(
                key: const Key('chat-send-button'),
                onTap: canSend
                    ? widget.onSend
                    : (canRecord ? widget.onRecord : null),
                child: SizedBox(
                  width: 44,
                  height: 44,
                  child: Icon(
                    canSend ? Icons.send_rounded : Icons.mic_none_rounded,
                    size: 22,
                    color: canSend || canRecord ? micForeground : muted,
                  ),
                ),
              ),
            ),
            const SizedBox(width: 6),
            Expanded(
              child: Material(
                color: barColor,
                borderRadius: BorderRadius.circular(25),
                clipBehavior: Clip.antiAlias,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(4, 4, 4, 4),
                  child: Row(
                    textDirection: TextDirection.rtl,
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      _iconAction(
                        key:
                            widget.emojiButtonKey ??
                            const Key('chat-emoji-button'),
                        onPressed: widget.sendEnabled ? widget.onEmoji : null,
                        icon: widget.emojiAsKeyboard
                            ? Icons.keyboard_alt_outlined
                            : Icons.emoji_emotions_outlined,
                        color: muted,
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
                              onChanged: (value) {
                                if (!value.contains('\n')) return;
                                final cleaned = value.replaceAll('\n', '');
                                widget.controller.value = TextEditingValue(
                                  text: cleaned,
                                  selection: TextSelection.collapsed(
                                    offset: cleaned.length,
                                  ),
                                );
                                if (widget.sendEnabled &&
                                    cleaned.trim().isNotEmpty) {
                                  widget.onSend();
                                }
                              },
                              onSubmitted: (_) {
                                if (canSend) widget.onSend();
                              },
                              decoration: InputDecoration(
                                hintText: 'الرسالة',
                                hintStyle: TextStyle(
                                  color: muted,
                                  fontWeight: FontWeight.w500,
                                  fontSize: 14.5,
                                ),
                                filled: true,
                                fillColor: Colors.transparent,
                                isDense: true,
                                contentPadding: const EdgeInsets.symmetric(
                                  horizontal: 8,
                                  vertical: 10,
                                ),
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
                        key:
                            widget.attachButtonKey ??
                            const Key('chat-attach-button'),
                        onPressed: widget.sendEnabled ? widget.onAttach : null,
                        icon: Icons.attach_file_rounded,
                        color: muted,
                      ),
                      _iconAction(
                        key: const Key('chat-camera-button'),
                        onPressed: widget.sendEnabled
                            ? (widget.onCamera ?? widget.onAttach)
                            : null,
                        icon: Icons.camera_alt_outlined,
                        color: muted,
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _recordingBar(BuildContext context, Color barColor) {
    final elapsed = widget.recordElapsed;
    final clock =
        '${elapsed.inMinutes.remainder(60).toString().padLeft(2, '0')}:'
        '${elapsed.inSeconds.remainder(60).toString().padLeft(2, '0')}';
    final mediaWidth = MediaQuery.sizeOf(context).width;
    final responsiveBottomGap = (mediaWidth * 0.055)
        .clamp(20.0, 32.0)
        .toDouble();
    final systemBottomInset = MediaQuery.viewPaddingOf(context).bottom;
    final bottomGap = systemBottomInset > responsiveBottomGap
        ? systemBottomInset
        : responsiveBottomGap;
    final panelHeight = (mediaWidth * 0.23).clamp(145.0, 250.0).toDouble();
    final surface = HasimTokens.isDark(context)
        ? const Color(0xFF101619)
        : barColor;
    final muted = HasimTokens.isDark(context)
        ? const Color(0xFFB8C0C2)
        : HasimTokens.mutedColor(context);

    return SafeArea(
      top: false,
      bottom: false,
      child: Padding(
        padding: const EdgeInsets.only(top: 8),
        child: SizedBox(
          height: panelHeight + bottomGap,
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: surface,
              borderRadius: const BorderRadius.vertical(
                top: Radius.circular(18),
              ),
            ),
            child: Padding(
              padding: EdgeInsets.only(bottom: bottomGap),
              child: LayoutBuilder(
                builder: (context, constraints) {
                  final actionSize = (constraints.maxWidth * 0.135)
                      .clamp(54.0, 140.0)
                      .toDouble();
                  final iconSize = (actionSize * 0.45).clamp(26.0, 34.0);
                  final gap = (constraints.maxWidth * 0.02)
                      .clamp(8.0, 20.0)
                      .toDouble();
                  final labelSize = (actionSize * 0.25)
                      .clamp(15.0, 20.0)
                      .toDouble();
                  final timerSize = (actionSize * 0.27)
                      .clamp(17.0, 22.0)
                      .toDouble();
                  final waveformWidth = (constraints.maxWidth * 0.30)
                      .clamp(104.0, 320.0)
                      .toDouble();

                  return Padding(
                    padding: const EdgeInsets.fromLTRB(14, 0, 14, 0),
                    child: Column(
                      children: [
                        SizedBox(
                          height: constraints.maxHeight * 0.40,
                          child: Center(
                            child: SizedBox(
                              width: waveformWidth,
                              child: _VoiceWaveform(
                                paused: widget.voicePaused,
                                amplitude: widget.voiceAmplitude,
                                color: muted,
                              ),
                            ),
                          ),
                        ),
                        const Spacer(),
                        Row(
                          textDirection: TextDirection.ltr,
                          crossAxisAlignment: CrossAxisAlignment.center,
                          children: [
                            _recordingAction(
                              key: const Key('chat-voice-send'),
                              background: Colors.white,
                              foreground: const Color(0xFF17212B),
                              icon: Icons.play_arrow_rounded,
                              rotate: 3.1415926535,
                              size: actionSize,
                              iconSize: iconSize,
                              onPressed: widget.sendEnabled
                                  ? widget.onConfirmRecord
                                  : null,
                            ),
                            SizedBox(width: gap),
                            Expanded(
                              child: Material(
                                color: const Color(0xFF2A3033),
                                borderRadius: BorderRadius.circular(40),
                                clipBehavior: Clip.antiAlias,
                                child: InkWell(
                                  key: const Key('chat-voice-pause'),
                                  onTap: widget.onTogglePause,
                                  child: SizedBox(
                                    height: actionSize,
                                    child: Center(
                                      child: FittedBox(
                                        fit: BoxFit.scaleDown,
                                        child: Row(
                                          mainAxisSize: MainAxisSize.min,
                                          textDirection: TextDirection.rtl,
                                          children: [
                                            Text(
                                              widget.voicePaused
                                                  ? 'استئناف'
                                                  : 'إيقاف مؤقت',
                                              style: TextStyle(
                                                color: Colors.white,
                                                fontSize: labelSize,
                                                fontWeight: FontWeight.w700,
                                              ),
                                            ),
                                            SizedBox(width: gap),
                                            Text(
                                              clock,
                                              key: const Key(
                                                'chat-voice-timer',
                                              ),
                                              style: TextStyle(
                                                color: Colors.white,
                                                fontSize: timerSize,
                                                fontWeight: FontWeight.w800,
                                              ),
                                            ),
                                          ],
                                        ),
                                      ),
                                    ),
                                  ),
                                ),
                              ),
                            ),
                            SizedBox(width: gap),
                            _recordingAction(
                              key: const Key('chat-voice-cancel'),
                              background: const Color(0xFF4A1420),
                              foreground: const Color(0xFFFF5C70),
                              icon: Icons.delete_outline_rounded,
                              size: actionSize,
                              iconSize: iconSize,
                              onPressed: widget.onCancelRecord,
                            ),
                          ],
                        ),
                      ],
                    ),
                  );
                },
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _recordingAction({
    required Key key,
    required Color background,
    required Color foreground,
    required IconData icon,
    required VoidCallback? onPressed,
    required double size,
    required double iconSize,
    double rotate = 0,
  }) {
    return Material(
      color: background,
      shape: const CircleBorder(),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        key: key,
        onTap: onPressed,
        child: SizedBox(
          width: size,
          height: size,
          child: Transform.rotate(
            angle: rotate,
            child: Icon(icon, color: foreground, size: iconSize),
          ),
        ),
      ),
    );
  }
}

class _VoiceWaveform extends StatelessWidget {
  const _VoiceWaveform({
    required this.paused,
    required this.amplitude,
    required this.color,
  });

  final bool paused;
  final double amplitude;
  final Color color;

  static const _bars = <double>[
    12,
    20,
    32,
    18,
    26,
    42,
    24,
    16,
    34,
    48,
    28,
    20,
    38,
    52,
    30,
    22,
    44,
    26,
    18,
    36,
    50,
    28,
    18,
    34,
    22,
    42,
    30,
    16,
    26,
    44,
    20,
    12,
  ];

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.max,
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        for (final height in _bars)
          AnimatedContainer(
            duration: const Duration(milliseconds: 180),
            width: 3,
            height: paused
                ? height * 0.5
                : height * (0.25 + amplitude.clamp(0.0, 1.0) * 0.75),
            decoration: BoxDecoration(
              color: color,
              borderRadius: BorderRadius.circular(4),
            ),
          ),
      ],
    );
  }
}
