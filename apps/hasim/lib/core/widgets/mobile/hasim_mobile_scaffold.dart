import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

/// Scaffold موحّد للموبايل فقط.
class HasimMobileScaffold extends StatelessWidget {
  const HasimMobileScaffold({
    super.key,
    required this.title,
    required this.body,
    this.actions,
    this.appBarBottom,
  });

  final String title;
  final Widget body;
  final List<Widget>? actions;
  final PreferredSizeWidget? appBarBottom;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: Text(title),
        centerTitle: false,
        backgroundColor: HasimTokens.pageBg(context),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        actions: actions,
        bottom: appBarBottom,
      ),
      body: body,
    );
  }
}
