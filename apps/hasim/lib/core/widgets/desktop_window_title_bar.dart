import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:window_manager/window_manager.dart';

/// شريط عنوان Windows فقط. لا يُبنى على Android أو iOS.
bool get hasimUsesDesktopTitleBar {
  if (kIsWeb || !Platform.isWindows) return false;
  return !Platform.environment.containsKey('FLUTTER_TEST');
}

/// شريط عنوان مخصص: «حاسم» يمينًا، وأزرار النافذة يسارًا.
class DesktopWindowTitleBar extends StatefulWidget {
  const DesktopWindowTitleBar({super.key});

  @override
  State<DesktopWindowTitleBar> createState() => _DesktopWindowTitleBarState();
}

class _DesktopWindowTitleBarState extends State<DesktopWindowTitleBar> with WindowListener {
  bool _maximized = false;

  @override
  void initState() {
    super.initState();
    windowManager.addListener(this);
    windowManager.isMaximized().then((value) {
      if (mounted) setState(() => _maximized = value);
    });
  }

  @override
  void dispose() {
    windowManager.removeListener(this);
    super.dispose();
  }

  @override
  void onWindowMaximize() {
    if (mounted) setState(() => _maximized = true);
  }

  @override
  void onWindowUnmaximize() {
    if (mounted) setState(() => _maximized = false);
  }

  Future<void> _toggleMaximize() async {
    if (await windowManager.isMaximized()) {
      await windowManager.unmaximize();
    } else {
      await windowManager.maximize();
    }
  }

  @override
  Widget build(BuildContext context) {
    final bg = HasimTokens.pageBg(context);
    final fg = HasimTokens.inkColor(context);
    final line = HasimTokens.lineColor(context);
    final railInset = HasimLayout.useNavRail(context) ? HasimLayout.navRailWidth : 0.0;

    return Directionality(
      textDirection: TextDirection.ltr,
      child: Material(
        color: bg,
        child: SizedBox(
          height: 36,
          child: Stack(
            children: [
              Row(
                children: [
                  _CaptionButton(
                    tooltip: 'إغلاق',
                    icon: Icons.close_rounded,
                    onPressed: windowManager.close,
                    close: true,
                  ),
                  _CaptionButton(
                    tooltip: _maximized ? 'استعادة' : 'تكبير',
                    icon: _maximized ? Icons.filter_none_rounded : Icons.crop_square_rounded,
                    onPressed: _toggleMaximize,
                  ),
                  _CaptionButton(
                    tooltip: 'تصغير',
                    icon: Icons.remove_rounded,
                    onPressed: windowManager.minimize,
                  ),
                  Expanded(
                    child: DragToMoveArea(
                      child: Align(
                        alignment: Alignment.centerRight,
                        child: Padding(
                          padding: const EdgeInsetsDirectional.only(end: 14),
                          child: Text(
                            'حاسم',
                            style: TextStyle(
                              fontSize: 13,
                              fontWeight: FontWeight.w800,
                              color: fg,
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
              Positioned(
                left: 0,
                right: railInset,
                bottom: 0,
                child: ColoredBox(
                  color: line,
                  child: const SizedBox(height: 1),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CaptionButton extends StatefulWidget {
  const _CaptionButton({
    required this.tooltip,
    required this.icon,
    required this.onPressed,
    this.close = false,
  });

  final String tooltip;
  final IconData icon;
  final VoidCallback onPressed;
  final bool close;

  @override
  State<_CaptionButton> createState() => _CaptionButtonState();
}

class _CaptionButtonState extends State<_CaptionButton> {
  bool _hover = false;

  @override
  Widget build(BuildContext context) {
    final idle = HasimTokens.inkColor(context);
    final bg = widget.close && _hover ? const Color(0xFFE81123) : (_hover ? const Color(0x140F2A24) : Colors.transparent);
    final fg = widget.close && _hover ? Colors.white : idle;

    return Tooltip(
      message: widget.tooltip,
      child: MouseRegion(
        onEnter: (_) => setState(() => _hover = true),
        onExit: (_) => setState(() => _hover = false),
        child: InkWell(
          onTap: widget.onPressed,
          child: SizedBox(
            width: 46,
            height: 36,
            child: ColoredBox(
              color: bg,
              child: Icon(widget.icon, size: 16, color: fg),
            ),
          ),
        ),
      ),
    );
  }
}
