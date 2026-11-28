import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/theme/theme_mode_controller.dart';
import 'package:hasim/core/widgets/hasim_card.dart';
import 'package:hasim/core/widgets/hasim_settings_body.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_bubble_color_picker.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_wallpaper_picker.dart';
import 'package:hasim/features/conversations/providers/conversation_theme_controller.dart';
import 'package:hasim/features/settings/presentation/widgets/display_appearance_settings.dart';

/// من قائمة المحادثة — نفس محتوى «المظهر والسمات» في الإعدادات.
class ChatThemesScreen extends ConsumerWidget {
  const ChatThemesScreen({
    super.key,
    this.embedded = false,
    this.conversationId,
  });

  final bool embedded;
  final int? conversationId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final themeMode = ref.watch(themeModeControllerProvider);
    final compact = HasimLayout.isCompact(context);

    final forConversation = conversationId != null;
    final body = HasimSettingsBody(
      mobileLayout: compact && !embedded,
      children: [
        if (forConversation) ...[
          HasimCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                HasimSettingsSectionHeader(
                  icon: Icons.palette_outlined,
                  title: 'سمة هذه المحادثة',
                  subtitle:
                      'تُحفظ لهذه المحادثة فقط، وباقي المحادثات تبقى على السمة العامة.',
                  trailing: TextButton(
                    onPressed: () => ref
                        .read(conversationThemeProvider(conversationId!).notifier)
                        .useSharedTheme(),
                    child: const Text('استخدام السمة العامة'),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: HasimTokens.spaceMd),
          HasimCard(
            child: ChatWallpaperPicker(
              compact: compact,
              conversationId: conversationId,
            ),
          ),
          const SizedBox(height: HasimTokens.spaceMd),
          HasimCard(
            child: ChatBubbleColorPicker(conversationId: conversationId),
          ),
        ] else
          DisplayAppearanceSettings(
            themeMode: themeMode,
            compactPreview: compact,
            onThemeChanged: (mode) =>
                ref.read(themeModeControllerProvider.notifier).setMode(mode),
          ),
      ],
    );

    if (embedded) {
      return ColoredBox(
        color: HasimTokens.settingsCanvasBg(context),
        child: body,
      );
    }

    final title = forConversation ? 'سمة هذه المحادثة' : 'المظهر والسمات';

    if (compact) {
      return HasimMobileScaffold(title: title, body: body);
    }

    return Scaffold(
      backgroundColor: HasimTokens.settingsCanvasBg(context),
      appBar: AppBar(
        title: Text(title),
        backgroundColor: HasimTokens.settingsCanvasBg(context),
        surfaceTintColor: Colors.transparent,
      ),
      body: body,
    );
  }
}
