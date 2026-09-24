import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/theme/theme_mode_controller.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/hasim_adaptive_scaffold.dart';
import 'package:hasim/core/widgets/hasim_bottom_nav.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/core/widgets/hasim_search_bar.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_list_row.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/home/providers/home_controller.dart';
import 'package:hasim/features/more/presentation/security_sessions_screen.dart';
import 'package:hasim/features/catalog/presentation/chat_catalog_settings_screen.dart';
import 'package:hasim/features/settings/presentation/widgets/display_appearance_settings.dart';
import 'package:hasim/features/settings/presentation/ai_settings_screen.dart';
import 'package:hasim/features/settings/presentation/notification_preferences_screen.dart';
import 'package:hasim/features/settings/presentation/profile_screen.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
import 'package:hasim/router/hasim_nav.dart';

/// إعدادات تفصيلية — تُفتح من «المزيد». الحساب/القنوات/الباقة في شاشة المزيد.
class SettingsScreen extends ConsumerStatefulWidget {
  const SettingsScreen({super.key});

  @override
  ConsumerState<SettingsScreen> createState() => _SettingsScreenState();
}

enum _SettingsId {
  profile,
  security,
  notifications,
  chatCatalog,
  ai,
  appearance,
  api,
  push,
  logout,
}

class _SettingsScreenState extends ConsumerState<SettingsScreen> {
  final _search = TextEditingController();
  String _query = '';
  _SettingsId? _selected = _SettingsId.profile;

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  String _themeLabel(ThemeMode mode) {
    return switch (mode) {
      ThemeMode.light => 'فاتح',
      ThemeMode.dark => 'داكن',
      ThemeMode.system => 'حسب النظام',
    };
  }

  List<_SettingsItem> _items({
    required ThemeMode themeMode,
    required String pushLabel,
    String? profileSubtitle,
  }) {
    return [
      _SettingsItem(
        id: _SettingsId.profile,
        icon: Icons.person_outline,
        title: 'حسابي',
        subtitle: profileSubtitle,
        route: '/profile',
      ),
      const _SettingsItem(
        id: _SettingsId.security,
        icon: Icons.security_outlined,
        title: 'الأمان والجلسات',
        route: '/more/security',
      ),
      const _SettingsItem(
        id: _SettingsId.notifications,
        icon: Icons.notifications_active_outlined,
        title: 'تفضيلات الإشعارات',
        route: '/notification-preferences',
      ),
      const _SettingsItem(
        id: _SettingsId.chatCatalog,
        icon: Icons.tune_outlined,
        title: 'إعدادات كتالوج الشات',
        route: '/chat-catalog-settings',
      ),
      const _SettingsItem(
        id: _SettingsId.ai,
        icon: Icons.smart_toy_outlined,
        title: 'إعدادات الذكاء الاصطناعي',
        route: '/ai-settings',
      ),
      _SettingsItem(
        id: _SettingsId.appearance,
        icon: Icons.brightness_6_outlined,
        title: 'المظهر والسمات',
        subtitle: '${_themeLabel(themeMode)} · خلفية المحادثة',
        route: '/chat-themes',
      ),
      _SettingsItem(
        id: _SettingsId.api,
        icon: Icons.dns_outlined,
        title: 'عنوان API',
        subtitle: AppConfig.apiBase,
      ),
      _SettingsItem(
        id: _SettingsId.push,
        icon: Icons.notifications_outlined,
        title: 'الإشعارات الفورية (Push)',
        subtitle: pushLabel,
      ),
      const _SettingsItem(
        id: _SettingsId.logout,
        icon: Icons.logout,
        title: 'تسجيل الخروج',
        danger: true,
      ),
    ];
  }

  List<_SettingsItem> _filtered(List<_SettingsItem> items) {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return items;
    return items
        .where(
          (item) =>
              item.title.toLowerCase().contains(q) ||
              (item.subtitle?.toLowerCase().contains(q) ?? false),
        )
        .toList();
  }

  Future<void> _logout() async {
    await ref.read(authControllerProvider.notifier).logout();
    if (mounted) context.go('/login');
  }

  void _onSelect(_SettingsItem item, {required bool split}) {
    if (item.id == _SettingsId.logout) {
      _logout();
      return;
    }
    if (split) {
      setState(() => _selected = item.id);
      return;
    }
    if (item.id == _SettingsId.appearance) {
      context.push('/chat-themes');
      return;
    }
    if (item.route != null) {
      context.push(item.route!);
    }
  }

  Widget _railShell({required Widget body}) {
    final snap = ref.watch(homeControllerProvider).snapshot;
    final stories = ref.watch(storiesControllerProvider);
    return HasimAdaptiveScaffold(
      selectedIndex: -1,
      settingsSelected: true,
      onSettingsTap: () {},
      onDestinationSelected: (index) => HasimAdaptiveScaffold.goBranch(context, index),
      items: hasimBottomNavItems(
        chatBadge: snap?.unreadConversations ?? 0,
        emailBadge: snap?.unreadEmail ?? 0,
        updatesBadge: stories.unviewedCount,
      ),
      body: body,
    );
  }

  @override
  Widget build(BuildContext context) {
    final push = ref.watch(pushServiceProvider);
    final themeMode = ref.watch(themeModeControllerProvider);
    final user = ref.watch(authControllerProvider).user;
    final compact = HasimLayout.isCompact(context);
    final split = HasimLayout.useSettingsSplit(context);
    final items = _filtered(
      _items(
        themeMode: themeMode,
        pushLabel: push.statusLabel,
        profileSubtitle: user?.email,
      ),
    );

    if (compact) {
      return HasimMobileScaffold(
        title: 'الإعدادات',
        body: _MobileSettingsList(
          items: items,
          themeMode: themeMode,
          onSelect: (item) => _onSelect(item, split: false),
          onThemeChanged: (mode) => ref.read(themeModeControllerProvider.notifier).setMode(mode),
        ),
      );
    }

    if (!split) {
      return _railShell(
        body: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: HasimPageHeader(title: 'الإعدادات'),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
              child: HasimSearchBar(
                controller: _search,
                hintText: 'ابحث في الإعدادات',
                onChanged: (v) => setState(() => _query = v),
                onClear: () => setState(() => _query = ''),
              ),
            ),
            Expanded(
              child: _RailFlatSettingsList(
                items: items,
                userName: user?.name,
                avatarUrl: user?.avatarUrl,
                onSelect: (item) => _onSelect(item, split: false),
              ),
            ),
          ],
        ),
      );
    }

    return _railShell(
      body: ColoredBox(
        color: HasimTokens.pageBg(context),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(
              key: const Key('hasim-settings-panel'),
              width: HasimLayout.conversationPanelWidth(context),
              child: DecoratedBox(
                decoration: BoxDecoration(
                  color: HasimTokens.cardBg(context),
                  border: Border(
                    left: BorderSide(color: HasimTokens.lineColor(context)),
                  ),
                  boxShadow: HasimTokens.softShadow(context),
                ),
                child: _SettingsPanel(
                    search: _search,
                    items: items,
                    selected: _selected,
                    userName: user?.name,
                    avatarUrl: user?.avatarUrl,
                    onQueryChanged: (v) => setState(() => _query = v),
                    onQueryCleared: () => setState(() => _query = ''),
                    onSelect: (item) => _onSelect(item, split: true),
                  ),
                ),
              ),
            Expanded(
              child: ColoredBox(
                color: HasimTokens.pageBg(context),
                child: KeyedSubtree(
                  key: const Key('hasim-settings-detail'),
                  child: _SettingsDetailPane(
                  selected: _selected,
                  themeMode: themeMode,
                  apiBase: AppConfig.apiBase,
                  pushLabel: push.statusLabel,
                  onThemeChanged: (mode) => ref.read(themeModeControllerProvider.notifier).setMode(mode),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _SettingsItem {
  const _SettingsItem({
    required this.id,
    required this.icon,
    required this.title,
    this.subtitle,
    this.route,
    this.danger = false,
  });

  final _SettingsId id;
  final IconData icon;
  final String title;
  final String? subtitle;
  final String? route;
  final bool danger;
}

class _MobileSettingsList extends StatelessWidget {
  const _MobileSettingsList({
    required this.items,
    required this.themeMode,
    required this.onSelect,
    required this.onThemeChanged,
  });

  final List<_SettingsItem> items;
  final ThemeMode themeMode;
  final ValueChanged<_SettingsItem> onSelect;
  final ValueChanged<ThemeMode> onThemeChanged;

  @override
  Widget build(BuildContext context) {
    return HasimMobileListShell(
      children: [
        for (final item in items)
          if (item.id == _SettingsId.api || item.id == _SettingsId.push)
            HasimMobileListRow(
              title: item.title,
              subtitle: item.subtitle,
              subtitleTextDirection: item.id == _SettingsId.api ? TextDirection.ltr : null,
              icon: item.icon,
              showChevron: false,
            )
          else
            HasimMobileListRow(
              title: item.title,
              subtitle: item.subtitle,
              icon: item.icon,
              danger: item.danger,
              showChevron: item.route != null || item.danger,
              onTap: item.route != null || item.danger ? () => onSelect(item) : null,
            ),
      ],
    );
  }
}

/// قائمة إعدادات مسطّحة مع سكة التنقل (700–900px) — نفس صفوف اللوحة، بدون بطاقات الموبايل.
class _RailFlatSettingsList extends StatelessWidget {
  const _RailFlatSettingsList({
    required this.items,
    required this.onSelect,
    this.userName,
    this.avatarUrl,
  });

  final List<_SettingsItem> items;
  final ValueChanged<_SettingsItem> onSelect;
  final String? userName;
  final String? avatarUrl;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(10, 4, 10, 16),
      children: [
        for (final item in items) ...[
          if (item.id == _SettingsId.logout)
            Padding(
              padding: const EdgeInsets.fromLTRB(4, 12, 4, 8),
              child: Divider(height: 1, color: HasimTokens.lineColor(context)),
            ),
          _SettingsPanelRow(
            item: item,
            selected: false,
            leading: item.id == _SettingsId.profile
                ? _ProfileAvatar(name: userName, avatarUrl: avatarUrl)
                : null,
            onTap: () => onSelect(item),
          ),
        ],
      ],
    );
  }
}

class _SettingsPanel extends StatelessWidget {
  const _SettingsPanel({
    required this.search,
    required this.items,
    required this.selected,
    required this.onQueryChanged,
    required this.onQueryCleared,
    required this.onSelect,
    this.userName,
    this.avatarUrl,
  });

  final TextEditingController search;
  final List<_SettingsItem> items;
  final _SettingsId? selected;
  final String? userName;
  final String? avatarUrl;
  final ValueChanged<String> onQueryChanged;
  final VoidCallback onQueryCleared;
  final ValueChanged<_SettingsItem> onSelect;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Padding(
          padding: EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: HasimPageHeader(title: 'الإعدادات'),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: HasimSearchBar(
            controller: search,
            hintText: 'ابحث في الإعدادات',
            onChanged: onQueryChanged,
            onClear: onQueryCleared,
          ),
        ),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(10, 4, 10, 16),
            children: [
              for (final item in items) ...[
                if (item.id == _SettingsId.logout)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(4, 12, 4, 8),
                    child: Divider(height: 1, color: HasimTokens.lineColor(context)),
                  ),
                _SettingsPanelRow(
                  item: item,
                  selected: selected == item.id,
                  leading: item.id == _SettingsId.profile
                      ? _ProfileAvatar(name: userName, avatarUrl: avatarUrl)
                      : null,
                  onTap: () => onSelect(item),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _SettingsPanelRow extends StatelessWidget {
  const _SettingsPanelRow({
    required this.item,
    required this.selected,
    required this.onTap,
    this.leading,
  });

  final _SettingsItem item;
  final bool selected;
  final VoidCallback onTap;
  final Widget? leading;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final danger = item.danger;
    final ink = danger ? theme.colorScheme.error : HasimTokens.inkColor(context);
    final muted = HasimTokens.mutedColor(context);

    final fill = selected
        ? HasimTokens.conversationSelected(context)
        : Colors.transparent;
    final borderColor = selected ? AppTheme.brand.withValues(alpha: 0.38) : Colors.transparent;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Material(
        color: fill,
        elevation: selected ? 0 : 0,
        shadowColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: borderColor),
        ),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(12),
          hoverColor: HasimTokens.mintFill(context).withValues(alpha: 0.65),
          splashColor: AppTheme.brand.withValues(alpha: 0.08),
          child: Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(10, 10, 12, 10),
            child: Row(
              children: [
                if (selected)
                  Container(
                    width: 3,
                    height: 36,
                    margin: const EdgeInsetsDirectional.only(end: 8),
                    decoration: BoxDecoration(
                      color: AppTheme.brand,
                      borderRadius: BorderRadius.circular(4),
                    ),
                  ),
                leading ??
                    Container(
                      width: 38,
                      height: 38,
                      decoration: BoxDecoration(
                        color: danger
                            ? theme.colorScheme.error.withValues(alpha: 0.10)
                            : AppTheme.brand.withValues(alpha: 0.10),
                        borderRadius: BorderRadius.circular(11),
                      ),
                      child: Icon(
                        item.icon,
                        size: 19,
                        color: danger ? theme.colorScheme.error : AppTheme.brand,
                      ),
                    ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        item.title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodyLarge?.copyWith(
                          fontWeight: selected ? FontWeight.w700 : FontWeight.w600,
                          fontSize: 14.5,
                          height: 1.35,
                          color: ink,
                        ),
                      ),
                      if (item.subtitle != null) ...[
                        const SizedBox(height: 2),
                        Text(
                          item.subtitle!,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          textDirection: item.id == _SettingsId.api ? TextDirection.ltr : null,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: muted,
                            fontWeight: FontWeight.w500,
                            fontSize: 12,
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
        ),
      ),
    );
  }
}

class _ProfileAvatar extends StatelessWidget {
  const _ProfileAvatar({this.name, this.avatarUrl});

  final String? name;
  final String? avatarUrl;

  @override
  Widget build(BuildContext context) {
    return CircleAvatar(
      radius: 20,
      backgroundColor: AppTheme.brand.withValues(alpha: 0.14),
      backgroundImage: avatarUrl != null ? CachedNetworkImageProvider(avatarUrl!) : null,
      child: avatarUrl == null
          ? Text(
              (name != null && name!.isNotEmpty) ? name![0] : 'ح',
              style: const TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.w700,
                color: AppTheme.brand,
              ),
            )
          : null,
    );
  }
}

class _SettingsDetailPane extends StatelessWidget {
  const _SettingsDetailPane({
    required this.selected,
    required this.themeMode,
    required this.apiBase,
    required this.pushLabel,
    required this.onThemeChanged,
  });

  final _SettingsId? selected;
  final ThemeMode themeMode;
  final String apiBase;
  final String pushLabel;
  final ValueChanged<ThemeMode> onThemeChanged;

  @override
  Widget build(BuildContext context) {
    return switch (selected) {
      _SettingsId.profile => const _EmbeddedDetail(
          title: 'حسابي',
          subtitle: 'إدارة معلومات حسابك الشخصي',
          child: ProfileScreen(embedded: true),
        ),
      _SettingsId.security => const _EmbeddedDetail(
          title: 'الأمان والجلسات',
          subtitle: 'إدارة أمان حسابك ومتابعة الأجهزة النشطة',
          child: SecuritySessionsScreen(embedded: true),
        ),
      _SettingsId.notifications => const _EmbeddedDetail(
          title: 'تفضيلات الإشعارات',
          child: NotificationPreferencesScreen(embedded: true),
        ),
      _SettingsId.chatCatalog => const _EmbeddedDetail(
          title: 'إعدادات كتالوج الشات',
          child: ChatCatalogSettingsScreen(embedded: true),
        ),
      _SettingsId.ai => const _EmbeddedDetail(
          title: 'إعدادات الذكاء الاصطناعي',
          child: AiSettingsScreen(embedded: true),
        ),
      _SettingsId.appearance => _AppearanceDetail(themeMode: themeMode, onThemeChanged: onThemeChanged),
      _SettingsId.api => _InfoDetail(title: 'عنوان API', value: apiBase, ltr: true),
      _SettingsId.push => _InfoDetail(title: 'الإشعارات الفورية (Push)', value: pushLabel),
      _SettingsId.logout || null => const EmptyState(
          icon: Icons.settings_outlined,
          title: 'الإعدادات',
          subtitle: 'اختر أحد الإعدادات من القائمة لعرض التفاصيل هنا.',
        ),
    };
  }
}

class _EmbeddedDetail extends StatelessWidget {
  const _EmbeddedDetail({
    required this.title,
    required this.child,
    this.subtitle,
  });

  final String title;
  final String? subtitle;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: HasimTokens.pageBg(context),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 16, 24, 0),
            child: HasimPageHeader(title: title, subtitle: subtitle),
          ),
          Expanded(child: child),
        ],
      ),
    );
  }
}

class _AppearanceDetail extends StatelessWidget {
  const _AppearanceDetail({required this.themeMode, required this.onThemeChanged});

  final ThemeMode themeMode;
  final ValueChanged<ThemeMode> onThemeChanged;

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: HasimTokens.pageBg(context),
      child: Align(
        alignment: Alignment.topCenter,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: HasimTokens.settingsContentMaxWidth),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(24, 16, 24, 32),
            children: [
              const HasimPageHeader(
                title: 'المظهر والسمات',
                subtitle: 'وضع التطبيق وخلفية شاشة المحادثة',
              ),
              DisplayAppearanceSettings(
                themeMode: themeMode,
                onThemeChanged: onThemeChanged,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _InfoDetail extends StatelessWidget {
  const _InfoDetail({required this.title, required this.value, this.ltr = false});

  final String title;
  final String value;
  final bool ltr;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Align(
        alignment: Alignment.topCenter,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 560),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              HasimPageHeader(title: title),
              DecoratedBox(
                decoration: BoxDecoration(
                  color: HasimTokens.cardBg(context),
                  borderRadius: BorderRadius.circular(HasimTokens.radiusCard),
                  border: Border.all(color: HasimTokens.lineColor(context)),
                  boxShadow: HasimTokens.softShadow(context),
                ),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: Text(
                      value,
                      textDirection: ltr ? TextDirection.ltr : null,
                      style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                            color: HasimTokens.mutedColor(context),
                            height: 1.45,
                          ),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
