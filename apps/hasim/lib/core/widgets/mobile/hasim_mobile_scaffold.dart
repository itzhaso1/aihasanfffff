import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

/// Scaffold موحّد للموبايل فقط.
class HasimMobileScaffold extends StatelessWidget {
  const HasimMobileScaffold({
    super.key,
    required this.title,
    required this.body,
    this.leading,
    this.actions,
    this.appBarBottom,
    this.floatingActionButton,
    this.floatingActionButtonLocation,
    this.centerTitle = false,
  });

  final String title;
  final Widget body;
  final Widget? leading;
  final List<Widget>? actions;
  final PreferredSizeWidget? appBarBottom;
  final Widget? floatingActionButton;
  final FloatingActionButtonLocation? floatingActionButtonLocation;
  final bool centerTitle;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: Text(title),
        centerTitle: centerTitle,
        backgroundColor: HasimTokens.pageBg(context),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        leading: leading,
        automaticallyImplyLeading: leading == null,
        actions: actions,
        bottom: appBarBottom,
      ),
      body: body,
      floatingActionButton: floatingActionButton,
      floatingActionButtonLocation: floatingActionButtonLocation,
    );
  }
}
