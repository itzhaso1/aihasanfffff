import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/theme/theme_mode_controller.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

/// إعدادات تفصيلية — تُفتح من «المزيد». الحساب/القنوات/الباقة في شاشة المزيد.
class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  String _themeLabel(ThemeMode mode) {
    return switch (mode) {
      ThemeMode.light => 'فاتح',
      ThemeMode.dark => 'داكن',
      ThemeMode.system => 'تلقائي',
    };
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final push = ref.watch(pushServiceProvider);
    final themeMode = ref.watch(themeModeControllerProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('الإعدادات')),
      body: ListView(
        children: [
          ListTile(
            leading: const Icon(Icons.person_outline),
            title: const Text('حسابي'),
            trailing: const Icon(Icons.chevron_left),
            onTap: () => context.push('/profile'),
          ),
          ListTile(
            leading: const Icon(Icons.security_outlined),
            title: const Text('الأمان والجلسات'),
            trailing: const Icon(Icons.chevron_left),
            onTap: () => context.push('/more/security'),
          ),
          ListTile(
            leading: const Icon(Icons.notifications_active_outlined),
            title: const Text('تفضيلات الإشعارات'),
            trailing: const Icon(Icons.chevron_left),
            onTap: () => context.push('/notification-preferences'),
          ),
          ListTile(
            leading: const Icon(Icons.brightness_6_outlined),
            title: const Text('المظهر'),
            subtitle: Text(_themeLabel(themeMode)),
            trailing: SegmentedButton<ThemeMode>(
              segments: const [
                ButtonSegment(value: ThemeMode.system, icon: Icon(Icons.brightness_auto, size: 18)),
                ButtonSegment(value: ThemeMode.light, icon: Icon(Icons.light_mode, size: 18)),
                ButtonSegment(value: ThemeMode.dark, icon: Icon(Icons.dark_mode, size: 18)),
              ],
              selected: {themeMode},
              onSelectionChanged: (s) => ref.read(themeModeControllerProvider.notifier).setMode(s.first),
            ),
          ),
          const Divider(),
          ListTile(
            title: const Text('عنوان API'),
            subtitle: Text(AppConfig.apiBase, textDirection: TextDirection.ltr),
          ),
          ListTile(
            title: const Text('الإشعارات الفورية (Push)'),
            subtitle: Text(push.statusLabel),
          ),
          const Divider(),
          ListTile(
            leading: Icon(Icons.logout, color: Theme.of(context).colorScheme.error),
            title: Text('تسجيل الخروج', style: TextStyle(color: Theme.of(context).colorScheme.error)),
            onTap: () async {
              await ref.read(authControllerProvider.notifier).logout();
              if (context.mounted) context.go('/login');
            },
          ),
        ],
      ),
    );
  }
}
