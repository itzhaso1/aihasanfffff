import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/theme/theme_mode_controller.dart';
import 'package:hasim/core/widgets/hasim_settings_body.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/settings/presentation/widgets/display_appearance_settings.dart';
/// من قائمة المحادثة — نفس محتوى «المظهر والسمات» في الإعدادات.
class ChatThemesScreen extends ConsumerWidget {
  const ChatThemesScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final themeMode = ref.watch(themeModeControllerProvider);
    final compact = HasimLayout.isCompact(context);

    final body = HasimSettingsBody(
      mobileLayout: compact && !embedded,
      children: [
        DisplayAppearanceSettings(
          themeMode: themeMode,
          compactPreview: compact,
          onThemeChanged: (mode) => ref.read(themeModeControllerProvider.notifier).setMode(mode),
        ),
      ],
    );

    if (embedded) {
      return ColoredBox(color: HasimTokens.pageBg(context), child: body);
    }

    const title = 'المظهر والسمات';

    if (compact) {
      return HasimMobileScaffold(title: title, body: body);
    }

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: const Text(title),
        backgroundColor: HasimTokens.pageBg(context),
        surfaceTintColor: Colors.transparent,
      ),
      body: body,
    );
  }
}
