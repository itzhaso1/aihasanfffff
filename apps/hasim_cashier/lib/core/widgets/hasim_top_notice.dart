import 'package:flutter/material.dart';

import '../theme/hasim_colors.dart';
import '../theme/hasim_radius.dart';

/// Compact floating notice at the top of the current Scaffold.
void showHasimTopNotice(BuildContext context, String text) {
  final messenger = ScaffoldMessenger.of(context);
  messenger.hideCurrentSnackBar();
  messenger.showSnackBar(
    SnackBar(
      behavior: SnackBarBehavior.floating,
      duration: const Duration(seconds: 4),
      elevation: 1,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      margin: const EdgeInsets.fromLTRB(24, 12, 24, 12),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(HasimRadius.sm),
        side: const BorderSide(color: Color(0xFFA7F3D0)),
      ),
      backgroundColor: HasimColors.surface,
      content: Text(
        text,
        textAlign: TextAlign.center,
        maxLines: 6,
        overflow: TextOverflow.ellipsis,
        style: const TextStyle(
          fontSize: 12,
          height: 1.35,
          fontWeight: FontWeight.w700,
          color: HasimColors.ctaDark,
        ),
      ),
    ),
  );
}
