import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/desktop_window_title_bar.dart';
import 'package:hasim/core/widgets/hasim_inner_chrome_corner.dart';

/// إطار النافذة على مستوى غلاف التطبيق، ويحتوي محتوى الـ [Navigator] بالكامل.
class DesktopWindowFrame extends StatelessWidget {
  const DesktopWindowFrame({
    super.key,
    required this.child,
    this.showTitleBar = true,
    this.showNavRail = true,
  });

  final Widget child;
  final bool showTitleBar;
  final bool showNavRail;

  @override
  Widget build(BuildContext context) {
    if (!hasimUsesDesktopTitleBar || !showTitleBar) return child;
    final showCorner = showNavRail && HasimLayout.useNavRail(context);
    final content = showCorner
        ? Stack(
            fit: StackFit.expand,
            children: [
              child,
              Positioned(
                top: 0,
                right: HasimLayout.navRailWidth - 1,
                child: HasimInnerChromeCorner(
                  color: HasimTokens.pageBg(context),
                  lineColor: HasimTokens.lineColor(context),
                ),
              ),
            ],
          )
        : child;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SizedBox(
          height: 36,
          child: DesktopWindowTitleBar(showNavRail: showNavRail),
        ),
        Expanded(child: content),
      ],
    );
  }
}
