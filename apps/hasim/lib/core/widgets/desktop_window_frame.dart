import 'package:flutter/material.dart';
import 'package:hasim/core/widgets/desktop_window_title_bar.dart';

/// إطار Windows — يجب أن يكون داخل [Navigator] حتى تعمل القوائم والـ Tooltips.
class DesktopWindowFrame extends StatelessWidget {
  const DesktopWindowFrame({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    if (!hasimUsesDesktopTitleBar) return child;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const DesktopWindowTitleBar(),
        Expanded(child: child),
      ],
    );
  }
}
