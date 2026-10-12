import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

/// محتوى صفحة إعدادات (مضمّنة أو مستقلة) — عرض أقصى ومسافات موحّدة.
class HasimSettingsBody extends StatelessWidget {
  const HasimSettingsBody({
    super.key,
    required this.children,
    this.padding,
    this.mobileLayout = false,
  });

  final List<Widget> children;
  final EdgeInsetsGeometry? padding;
  /// تخطيط موبايل فقط — عرض كامل ومسافات أوسع للمس.
  final bool mobileLayout;

  @override
  Widget build(BuildContext context) {
    final list = ListView(
      padding: padding ??
          (mobileLayout
              ? const EdgeInsets.fromLTRB(16, 12, 16, 28)
              : const EdgeInsets.fromLTRB(24, 12, 24, HasimTokens.spaceXl)),
      children: children,
    );

    return ColoredBox(
      color: HasimTokens.pageBg(context),
      child: mobileLayout
          ? list
          : Align(
              alignment: Alignment.topCenter,
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: HasimTokens.settingsContentMaxWidth),
                child: list,
              ),
            ),
    );
  }
}
