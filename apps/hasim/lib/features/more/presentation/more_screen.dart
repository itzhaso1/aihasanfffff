import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_list_row.dart';
import 'package:hasim/core/theme/theme_mode_controller.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/core/widgets/hasim_shell_header.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/home/providers/home_controller.dart';

/// شاشة «المزيد» — مركز الوصول للحساب والإدارة والإعدادات (ليست Dashboard).
class MoreScreen extends ConsumerWidget {
  const MoreScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    final snap = ref.watch(homeControllerProvider).snapshot;
    final user = auth.user;
    final theme = Theme.of(context);
    final notifCount = snap?.unreadNotifications ?? 0;
    final mint = HasimTokens.pageBg(context);
    final ink = HasimTokens.inkColor(context);
    final muted = HasimTokens.mutedColor(context);
    final compact = HasimLayout.isCompact(context);

    if (compact) {
      return Scaffold(
        backgroundColor: mint,
        appBar: HasimShellHeader(backgroundColor: mint),
        body: HasimMobileListShell(
          children: [
            _MobileProfileRow(user: user, workspaceName: auth.workspace?.name),
            const _SectionLabel('الحساب'),
            HasimMobileListRow(
              icon: Icons.swap_horiz,
              title: 'تبديل مساحة العمل',
              subtitle: auth.workspace?.name ?? 'اختر مساحة',
              onTap: () => context.push('/workspaces'),
            ),
            HasimMobileListRow(icon: Icons.person_outline, title: 'حسابي', onTap: () => context.push('/profile')),
            HasimMobileListRow(
              icon: Icons.security_outlined,
              title: 'الأمان والجلسات',
              onTap: () => context.push('/more/security'),
            ),
            const _SectionLabel('إدارة النشاط'),
            HasimMobileListRow(icon: Icons.contacts_outlined, title: 'جهات الاتصال', onTap: () => context.push('/contacts')),
            HasimMobileListRow(
              icon: Icons.groups_outlined,
              title: 'مجموعات جهات الاتصال',
              onTap: () => context.push('/contact-groups'),
            ),
            HasimMobileListRow(icon: Icons.hub_outlined, title: 'القنوات', onTap: () => context.push('/channels')),
            HasimMobileListRow(
              icon: Icons.inventory_2_outlined,
              title: 'كتالوج الشات',
              subtitle: 'منتجات وخدمات للذكاء الاصطناعي',
              onTap: () => context.push('/catalog'),
            ),
            HasimMobileListRow(
              icon: Icons.tune_outlined,
              title: 'إعدادات كتالوج الشات',
              onTap: () => context.push('/chat-catalog-settings'),
            ),
            HasimMobileListRow(
              icon: Icons.smart_toy_outlined,
              title: 'إعدادات الذكاء الاصطناعي',
              onTap: () => context.push('/ai-settings'),
            ),
            HasimMobileListRow(
              icon: Icons.auto_awesome_mosaic_outlined,
              title: 'نشاط اليوم',
              subtitle: 'إحصاءات مختصرة',
              onTap: () => context.push('/activity'),
            ),
            const _SectionLabel('الإعدادات'),
            HasimMobileListRow(
              icon: Icons.notifications_outlined,
              title: 'الإشعارات',
              subtitle: notifCount > 0 ? '$notifCount غير مقروء' : null,
              onTap: () => context.push('/notifications'),
            ),
            HasimMobileListRow(
              icon: Icons.notifications_active_outlined,
              title: 'تفضيلات الإشعارات',
              onTap: () => context.push('/notification-preferences'),
            ),
            HasimMobileListRow(
              icon: Icons.workspace_premium_outlined,
              title: 'الاشتراك والاستخدام',
              onTap: () => context.push('/plans'),
            ),
            HasimMobileListRow(
              icon: Icons.brightness_6_outlined,
              title: 'المظهر والسمات',
              subtitle: '${_themeLabel(ref.watch(themeModeControllerProvider))} · خلفية المحادثة',
              onTap: () => context.push('/settings'),
            ),
            HasimMobileListRow(
              icon: Icons.settings_outlined,
              title: 'الإعدادات',
              onTap: () => context.push('/settings'),
            ),
            HasimMobileListRow(
              icon: Icons.logout,
              title: 'تسجيل الخروج',
              danger: true,
              onTap: () async {
                await ref.read(authControllerProvider.notifier).logout();
                if (context.mounted) context.go('/login');
              },
            ),
          ],
        ),
      );
    }

    return Scaffold(
      backgroundColor: mint,
      appBar: HasimShellHeader(backgroundColor: mint),
      body: ListView(
        cacheExtent: 1200,
        padding: const EdgeInsets.fromLTRB(16, 4, 16, HasimTokens.pageBottom),
        children: [
          const HasimPageHeader(
            title: 'المزيد',
            subtitle: 'حسابك وإعداداتك في مكان واحد',
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
                              color: ink,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            auth.workspace?.name ?? 'مساحة العمل',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: muted,
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
                  icon: Icons.inventory_2_outlined,
                  title: 'كتالوج الشات',
                  subtitle: 'منتجات وخدمات للذكاء الاصطناعي',
                  onTap: () => context.push('/catalog'),
                ),
                const _MoreDivider(),
                _MoreRow(
                  icon: Icons.tune_outlined,
                  title: 'إعدادات كتالوج الشات',
                  onTap: () => context.push('/chat-catalog-settings'),
                ),
                const _MoreDivider(),
                _MoreRow(
                  icon: Icons.smart_toy_outlined,
                  title: 'إعدادات الذكاء الاصطناعي',
                  onTap: () => context.push('/ai-settings'),
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
                  title: 'المظهر والسمات',
                  subtitle: '${_themeLabel(ref.watch(themeModeControllerProvider))} · خلفية المحادثة',
                  onTap: () => context.push('/settings'),
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

}

class _MobileProfileRow extends StatelessWidget {
  const _MobileProfileRow({required this.user, this.workspaceName});

  final UserModel? user;
  final String? workspaceName;

  @override
  Widget build(BuildContext context) {
    final ink = HasimTokens.inkColor(context);
    final muted = HasimTokens.mutedColor(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: HasimTokens.cardBg(context),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: HasimTokens.lineColor(context)),
        ),
        child: InkWell(
          onTap: () => context.push('/profile'),
          borderRadius: BorderRadius.circular(12),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            child: Row(
              children: [
                CircleAvatar(
                  radius: 22,
                  backgroundColor: AppTheme.brand.withValues(alpha: 0.14),
                  backgroundImage: user?.avatarUrl != null ? CachedNetworkImageProvider(user!.avatarUrl!) : null,
                  child: user?.avatarUrl == null
                      ? Text(
                          (user?.name.isNotEmpty == true ? user!.name[0] : 'ح'),
                          style: const TextStyle(
                            fontSize: 18,
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
                        style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: ink),
                      ),
                      if (workspaceName != null) ...[
                        const SizedBox(height: 4),
                        Text(
                          workspaceName!,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(fontSize: 13, fontWeight: FontWeight.w500, color: muted),
                        ),
                      ],
                    ],
                  ),
                ),
                Icon(Icons.chevron_left_rounded, color: muted, size: 26),
              ],
            ),
          ),
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
    return DecoratedBox(
      key: const Key('more-card'),
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(HasimTokens.radiusCard),
        border: Border.all(color: HasimTokens.lineColor(context)),
        boxShadow: HasimTokens.softShadow(context),
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(HasimTokens.radiusCard),
        child: child,
      ),
    );
  }
}

class _MoreDivider extends StatelessWidget {
  const _MoreDivider();

  @override
  Widget build(BuildContext context) {
    return Divider(
      height: 1,
      thickness: 1,
      indent: 66,
      endIndent: 16,
      color: HasimTokens.lineColor(context),
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
              child: Icon(icon, size: 20, color: AppTheme.brand),
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
                            color: HasimTokens.inkColor(context),
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
                        color: HasimTokens.mutedColor(context),
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
