import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/theme_mode_controller.dart';
import 'package:hasim/core/widgets/hasim_shell_header.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/home/providers/home_controller.dart';

/// شاشة «المزيد» — مركز الوصول للحساب والإدارة والإعدادات (ليست Dashboard).
class MoreScreen extends ConsumerWidget {
  const MoreScreen({super.key});

  static const _ink = Color(0xFF1A2B28);
  static const _muted = Color(0xFF7A8B87);
  static const _line = Color(0xFFE7F0ED);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    final snap = ref.watch(homeControllerProvider).snapshot;
    final user = auth.user;
    final theme = Theme.of(context);
    final notifCount = snap?.unreadNotifications ?? 0;
    final mint = Color(AppConfig.brand.surface);

    return Scaffold(
      backgroundColor: mint,
      appBar: HasimShellHeader(backgroundColor: mint),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 28),
        children: [
          Padding(
            padding: const EdgeInsetsDirectional.only(start: 4, bottom: 10),
            child: Text(
              'المزيد',
              style: theme.textTheme.headlineSmall?.copyWith(
                fontWeight: FontWeight.w800,
                fontSize: 26,
                height: 1.25,
                color: _ink,
              ),
            ),
          ),
          _MoreCard(
            child: InkWell(
              onTap: () => context.push('/profile'),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                child: Row(
                  children: [
                    CircleAvatar(
                      radius: 24,
                      backgroundColor: AppTheme.brand.withValues(alpha: 0.14),
                      backgroundImage: user?.avatarUrl != null ? CachedNetworkImageProvider(user!.avatarUrl!) : null,
                      child: user?.avatarUrl == null
                          ? Text(
                              (user?.name.isNotEmpty == true ? user!.name[0] : 'ح'),
                              style: const TextStyle(
                                fontSize: 20,
                                fontWeight: FontWeight.w700,
                                color: AppTheme.brand,
                              ),
                            )
                          : null,
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            user?.name ?? 'مستخدم',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.titleMedium?.copyWith(
                              fontWeight: FontWeight.w700,
                              fontSize: 16,
                              height: 1.3,
                              color: _ink,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            auth.workspace?.name ?? 'مساحة العمل',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: _muted,
                              fontWeight: FontWeight.w500,
                              height: 1.35,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          const SizedBox(height: 10),
          _MoreCard(
            child: _MoreRow(
              icon: Icons.swap_horiz,
              title: 'تبديل مساحة العمل',
              subtitle: auth.workspace?.name ?? 'اختر مساحة',
              onTap: () => context.push('/workspaces'),
            ),
          ),
          const _SectionLabel('الحساب'),
          _MoreCard(
            child: Column(
              children: [
                _MoreRow(
                  icon: Icons.person_outline,
                  title: 'حسابي',
                  onTap: () => context.push('/profile'),
                ),
                const _MoreDivider(),
                _MoreRow(
                  icon: Icons.security_outlined,
                  title: 'الأمان والجلسات',
                  onTap: () => context.push('/more/security'),
                ),
              ],
            ),
          ),
          const _SectionLabel('إدارة النشاط'),
          _MoreCard(
            child: Column(
              children: [
                _MoreRow(
                  icon: Icons.contacts_outlined,
                  title: 'جهات الاتصال',
                  onTap: () => context.push('/contacts'),
                ),
                const _MoreDivider(),
                _MoreRow(
                  icon: Icons.groups_outlined,
                  title: 'مجموعات جهات الاتصال',
                  onTap: () => context.push('/contact-groups'),
                ),
                const _MoreDivider(),
                _MoreRow(
                  icon: Icons.hub_outlined,
                  title: 'القنوات',
                  onTap: () => context.push('/channels'),
                ),
                const _MoreDivider(),
                _MoreRow(
                  icon: Icons.auto_awesome_mosaic_outlined,
                  title: 'نشاط اليوم',
                  subtitle: 'إحصاءات مختصرة',
                  onTap: () => context.push('/activity'),
                ),
              ],
            ),
          ),
          const _SectionLabel('الإعدادات'),
          _MoreCard(
            child: Column(
              children: [
                _MoreRow(
                  icon: Icons.notifications_outlined,
                  title: 'الإشعارات',
                  badge: notifCount > 0 ? notifCount : null,
                  onTap: () => context.push('/notifications'),
                ),
                const _MoreDivider(),
                _MoreRow(
                  icon: Icons.notifications_active_outlined,
                  title: 'تفضيلات الإشعارات',
                  onTap: () => context.push('/notification-preferences'),
                ),
                const _MoreDivider(),
                _MoreRow(
                  icon: Icons.workspace_premium_outlined,
                  title: 'الاشتراك والاستخدام',
                  onTap: () => context.push('/plans'),
                ),
                const _MoreDivider(),
                _MoreRow(
                  icon: Icons.brightness_6_outlined,
                  title: 'المظهر',
                  subtitle: _themeLabel(ref.watch(themeModeControllerProvider)),
                  onTap: () => _showThemeSheet(context, ref),
                ),
                const _MoreDivider(),
                _MoreRow(
                  icon: Icons.settings_outlined,
                  title: 'الإعدادات',
                  onTap: () => context.push('/settings'),
                ),
              ],
            ),
          ),
          const SizedBox(height: 18),
          OutlinedButton(
            style: OutlinedButton.styleFrom(
              foregroundColor: theme.colorScheme.error,
              backgroundColor: theme.colorScheme.error.withValues(alpha: 0.04),
              side: BorderSide(color: theme.colorScheme.error.withValues(alpha: 0.42)),
              minimumSize: const Size.fromHeight(50),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
              textStyle: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
            ),
            onPressed: () async {
              await ref.read(authControllerProvider.notifier).logout();
              if (context.mounted) context.go('/login');
            },
            child: const Row(
              mainAxisAlignment: MainAxisAlignment.center,
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.logout, size: 18),
                SizedBox(width: 8),
                Text('تسجيل الخروج'),
              ],
            ),
          ),
        ],
      ),
    );
  }

  static String _themeLabel(ThemeMode mode) => switch (mode) {
        ThemeMode.light => 'فاتح',
        ThemeMode.dark => 'داكن',
        ThemeMode.system => 'حسب النظام',
      };

  Future<void> _showThemeSheet(BuildContext context, WidgetRef ref) async {
    final current = ref.read(themeModeControllerProvider);
    await showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (final mode in ThemeMode.values)
              ListTile(
                title: Text(_themeLabel(mode)),
                trailing: current == mode ? Icon(Icons.check, color: Theme.of(ctx).colorScheme.primary) : null,
                onTap: () {
                  ref.read(themeModeControllerProvider.notifier).setMode(mode);
                  Navigator.pop(ctx);
                },
              ),
          ],
        ),
      ),
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);
  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 16, 8, 8),
      child: Text(
        text,
        style: Theme.of(context).textTheme.titleSmall?.copyWith(
              fontWeight: FontWeight.w700,
              fontSize: 13.5,
              height: 1.35,
              color: AppTheme.brand,
            ),
      ),
    );
  }
}

class _MoreCard extends StatelessWidget {
  const _MoreCard({required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return DecoratedBox(
      decoration: BoxDecoration(
        color: isDark ? const Color(0xFF15201D) : Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: isDark ? const Color(0xFF1F2E2A) : MoreScreen._line),
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(18),
        child: child,
      ),
    );
  }
}

class _MoreDivider extends StatelessWidget {
  const _MoreDivider();

  @override
  Widget build(BuildContext context) {
    return const Divider(
      height: 1,
      thickness: 1,
      indent: 66,
      endIndent: 16,
      color: MoreScreen._line,
    );
  }
}

class _MoreRow extends StatelessWidget {
  const _MoreRow({
    required this.icon,
    required this.title,
    required this.onTap,
    this.subtitle,
    this.badge,
  });

  final IconData icon;
  final String title;
  final String? subtitle;
  final int? badge;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Row(
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: AppTheme.brand.withValues(alpha: 0.10),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, size: 20, color: MoreScreen._ink.withValues(alpha: 0.82)),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Flexible(
                        child: Text(
                          title,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodyLarge?.copyWith(
                            fontWeight: FontWeight.w600,
                            fontSize: 15,
                            height: 1.35,
                            color: MoreScreen._ink,
                          ),
                        ),
                      ),
                      if (badge != null) ...[
                        const SizedBox(width: 8),
                        Badge(label: Text('$badge')),
                      ],
                    ],
                  ),
                  if (subtitle != null) ...[
                    const SizedBox(height: 2),
                    Text(
                      subtitle!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: MoreScreen._muted,
                        fontWeight: FontWeight.w500,
                        height: 1.35,
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
