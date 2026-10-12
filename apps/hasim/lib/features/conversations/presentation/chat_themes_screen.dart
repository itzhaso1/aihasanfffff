import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/theme/theme_mode_controller.dart';
import 'package:hasim/core/widgets/hasim_settings_body.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_bubble_color_picker.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_wallpaper_picker.dart';
import 'package:hasim/features/conversations/providers/conversation_theme_controller.dart';
import 'package:hasim/features/settings/presentation/widgets/display_appearance_settings.dart';
/// من قائمة المحادثة — نفس محتوى «المظهر والسمات» في الإعدادات.
class ChatThemesScreen extends ConsumerWidget {
  const ChatThemesScreen({super.key, this.embedded = false, this.conversationId});

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
          const Text('سمة هذه المحادثة', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
          const SizedBox(height: 8),
          const Text('تُحفظ لهذه المحادثة فقط، وباقي المحادثات تبقى على السمة العامة.'),
          const SizedBox(height: 12),
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: TextButton(
              onPressed: () => ref.read(conversationThemeProvider(conversationId!).notifier).useSharedTheme(),
              child: const Text('استخدام السمة العامة'),
            ),
          ),
          ChatWallpaperPicker(compact: compact, conversationId: conversationId),
          const SizedBox(height: 16),
          ChatBubbleColorPicker(conversationId: conversationId),
        ] else
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

    final title = forConversation ? 'سمة هذه المحادثة' : 'المظهر والسمات';

    if (compact) {
      return HasimMobileScaffold(title: title, body: body);
    }

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: Text(title),
        backgroundColor: HasimTokens.pageBg(context),
        surfaceTintColor: Colors.transparent,
      ),
      body: body,
    );
  }
}
