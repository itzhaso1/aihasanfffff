import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/theme/theme_mode_controller.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/hasim_adaptive_scaffold.dart';
import 'package:hasim/core/widgets/hasim_bottom_nav.dart';
import 'package:hasim/router/hasim_nav.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/core/widgets/hasim_search_bar.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/home/providers/home_controller.dart';
import 'package:hasim/features/more/presentation/security_sessions_screen.dart';
import 'package:hasim/features/catalog/presentation/catalog_list_screen.dart';
import 'package:hasim/features/settings/presentation/widgets/display_appearance_settings.dart';
import 'package:hasim/features/settings/presentation/ai_settings_screen.dart';
import 'package:hasim/features/settings/presentation/company_profile_screen.dart';
import 'package:hasim/features/settings/presentation/channels_screen.dart';
import 'package:hasim/features/settings/presentation/notification_preferences_screen.dart';
import 'package:hasim/features/settings/presentation/profile_screen.dart';
import 'package:hasim/features/privacy/presentation/locked_chats_navigation.dart';
import 'package:hasim/features/privacy/presentation/privacy_lock_settings.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
import 'package:hasim/features/worker/presentation/worker_inbox_workspace_selector.dart';
import 'package:hasim/features/worker/presentation/worker_account_screen.dart';
import 'package:hasim/features/worker/presentation/worker_disputes_screen.dart';
import 'package:hasim/features/worker/presentation/worker_engagement_detail_screen.dart';
import 'package:hasim/features/worker/presentation/worker_engagement_list_screen.dart';
import 'package:hasim/features/worker/presentation/worker_payouts_screen.dart';
import 'package:hasim/features/worker/presentation/worker_profile_screen.dart';
import 'package:hasim/features/worker/presentation/worker_sales_reference_screen.dart';
import 'package:hasim/features/worker/presentation/worker_scaffold.dart';
import 'package:hasim/router/worker_nav.dart';

/// إعدادات تفصيلية — تُفتح من «المزيد». الحساب/القنوات/الباقة في شاشة المزيد.
class SettingsScreen extends ConsumerStatefulWidget {
  const SettingsScreen({super.key, this.withNavigation = true});

  final bool withNavigation;

  @override
  ConsumerState<SettingsScreen> createState() => _SettingsScreenState();
}

enum _SettingsId {
  profile,
  security,
  privacyLock,
  channels,
  notifications,
  products,
  ai,
  companyProfile,
  appearance,
  workerSalesReference,
  workerOffers,
  workerEngagements,
  workerAccount,
  logout,
}

class _SettingsScreenState extends ConsumerState<SettingsScreen> {
  final _search = TextEditingController();
  String _query = '';
  _SettingsId? _selected;
  String? _workerPaneLocation;

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
    required bool independentWorker,
    required ThemeMode themeMode,
    String? profileSubtitle,
  }) {
    if (independentWorker) {
      return [
        _SettingsItem(
          id: _SettingsId.profile,
          icon: Icons.person_outline,
          title: 'ملفي الشخصي',
          subtitle: profileSubtitle,
          route: workerAccountLocation,
        ),
        const _SettingsItem(
          id: _SettingsId.workerAccount,
          icon: Icons.person_outline,
          title: 'المعلومات والتوثيق',
          route: workerAccountLocation,
        ),
        const _SettingsItem(
          id: _SettingsId.security,
          icon: Icons.shield_outlined,
          title: 'الأمان والجلسات',
          route: '/more/security',
        ),
        const _SettingsItem(
          id: _SettingsId.privacyLock,
          icon: Icons.lock_outline,
          title: 'القفل والخصوصية',
          subtitle: 'رمز PIN والبصمة والدردشات المقفلة',
        ),
        _SettingsItem(
          id: _SettingsId.appearance,
          icon: Icons.palette_outlined,
          title: 'المظهر والسمات',
          subtitle: '${_themeLabel(themeMode)} · خلفية المحادثة',
          route: '/chat-themes',
        ),
        const _SettingsItem(
          id: _SettingsId.workerSalesReference,
          icon: Icons.menu_book_outlined,
          title: 'مرجع المبيعات',
          subtitle: 'منتجات وخدمات الشركة الحالية',
          route: workerSalesReferenceLocation,
          sectionStart: true,
        ),
        _SettingsItem(
          id: _SettingsId.workerOffers,
          icon: Icons.local_offer_outlined,
          title: 'العروض',
          route: workerOffersLocation,
        ),
        const _SettingsItem(
          id: _SettingsId.workerEngagements,
          icon: Icons.work_outline,
          title: 'الأعمال',
          route: workerEngagementsLocation,
        ),
        const _SettingsItem(
          id: _SettingsId.logout,
          icon: Icons.logout,
          title: 'تسجيل الخروج',
          danger: true,
        ),
      ];
    }
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
        icon: Icons.shield_outlined,
        title: 'الأمان والجلسات',
        route: '/more/security',
      ),
      const _SettingsItem(
        id: _SettingsId.privacyLock,
        icon: Icons.lock_outline,
        title: 'القفل والخصوصية',
        subtitle: 'رمز PIN والبصمة',
      ),
      const _SettingsItem(
        id: _SettingsId.channels,
        icon: Icons.hub_outlined,
        title: 'القنوات',
        route: '/channels',
      ),
      const _SettingsItem(
        id: _SettingsId.notifications,
        icon: Icons.notifications_outlined,
        title: 'تفضيلات الإشعارات',
        route: '/notification-preferences',
      ),
      const _SettingsItem(
        id: _SettingsId.products,
        icon: Icons.inventory_2_outlined,
        title: 'المنتجات والمخزون',
        subtitle: 'عرض المنتجات والتصنيفات والكميات كمرجع للذكاء الاصطناعي',
        route: '/catalog',
      ),
      const _SettingsItem(
        id: _SettingsId.ai,
        icon: Icons.smart_toy_outlined,
        title: 'إعدادات الذكاء الاصطناعي',
        route: '/ai-settings',
      ),
      const _SettingsItem(
        id: _SettingsId.companyProfile,
        icon: Icons.business_outlined,
        title: 'نبذة عن الشركة',
        subtitle: 'نشاط الشركة وما تبيعه والتعليمات للرد',
        route: '/ai-settings/company-profile',
      ),
      _SettingsItem(
        id: _SettingsId.appearance,
        icon: Icons.palette_outlined,
        title: 'المظهر والسمات',
        subtitle: '${_themeLabel(themeMode)} · خلفية المحادثة',
        route: '/chat-themes',
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

  void _onSelect(
    _SettingsItem item, {
    required bool split,
    required bool independentWorker,
  }) {
    if (item.id == _SettingsId.logout) {
      _logout();
      return;
    }
    if (item.id == _SettingsId.profile && independentWorker) {
      if (split) {
        setState(() {
          _selected = _SettingsId.workerAccount;
          _workerPaneLocation = '$workerAccountLocation?surface=profile';
        });
        return;
      }
      context.push('$workerAccountLocation?surface=profile');
      return;
    }
    if (independentWorker &&
        (item.id == _SettingsId.workerSalesReference ||
            item.id == _SettingsId.workerOffers ||
            item.id == _SettingsId.workerEngagements ||
            item.id == _SettingsId.workerAccount)) {
      if (split) {
        setState(() {
          _selected = item.id;
          _workerPaneLocation = null;
        });
        return;
      }
      context.push(item.route!);
      return;
    }
    if (item.id == _SettingsId.privacyLock) {
      if (split) {
        setState(() {
          _selected = item.id;
          _workerPaneLocation = null;
        });
        return;
      }
      context.push('/privacy-lock');
      return;
    }
    if (split) {
      setState(() {
        _selected = item.id;
        _workerPaneLocation = null;
      });
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

  void _openWorkerPane(String location) {
    setState(() => _workerPaneLocation = location);
  }

  void _backWorkerPane() {
    setState(() => _workerPaneLocation = null);
  }

  _SettingsId? _detailSelection({required bool independentWorker}) {
    final fallback = independentWorker
        ? _SettingsId.appearance
        : _SettingsId.profile;
    final id = _selected ?? fallback;
    if (independentWorker && id == _SettingsId.profile) {
      return fallback;
    }
    return id;
  }

  Widget _railShell({required Widget body}) {
    final auth = ref.read(authControllerProvider);
    final isIndependentWorker = auth.isIndependentWorker;
    final showSpecialInboxSections = auth.canSeeSpecialInboxSections;
    final homeSnapshot = isIndependentWorker
        ? null
        : ref.watch(homeControllerProvider).snapshot;
    final updatesBadge = isIndependentWorker
        ? 0
        : ref.watch(
            storiesControllerProvider.select((state) => state.unviewedCount),
          );
    return HasimAdaptiveScaffold(
      selectedIndex: -1,
      settingsSelected: true,
      onSettingsTap: () {},
      onArchiveTap: showSpecialInboxSections
          ? () => openArchivePage(context, ref)
          : null,
      onLockedTap: showSpecialInboxSections
          ? () => openLockedPage(context)
          : null,
      showSpecialInboxSections: showSpecialInboxSections,
      onDestinationSelected: (index) {
        if (index == HasimNav.conversationsIndex) {
          leaveSpecialInbox(ref);
        }
        HasimAdaptiveScaffold.goBranch(context, index);
      },
      items: hasimBottomNavItems(
        chatBadge: homeSnapshot?.unreadConversations ?? 0,
        emailBadge: homeSnapshot?.unreadEmail ?? 0,
        updatesBadge: updatesBadge,
      ),
      body: body,
    );
  }

  Widget _phoneCompanySelector() {
    final unreadCount = ref.watch(
      conversationsControllerProvider.select(
        (state) => state.items.where((item) => item.unreadCount > 0).length,
      ),
    );
    return WorkerInboxWorkspacePicker(newMessagesCount: unreadCount);
  }

  @override
  Widget build(BuildContext context) {
    final themeMode = ref.watch(themeModeControllerProvider);
    final auth = ref.watch(authControllerProvider);
    final independentWorker = auth.isIndependentWorker;
    final user = auth.user;
    final compact = HasimLayout.isCompact(context);
    final split = HasimLayout.useSettingsSplit(context);
    final items = _filtered(
      _items(
        independentWorker: independentWorker,
        themeMode: themeMode,
        profileSubtitle: user?.email,
      ),
    );
    final selected = _detailSelection(independentWorker: independentWorker);
    final companySelector = independentWorker && compact
        ? _phoneCompanySelector()
        : null;

    if (compact) {
      return Scaffold(
        backgroundColor: HasimTokens.isDark(context)
            ? HasimTokens.darkPage
            : const Color(0xFFFFFFFF),
        body: SafeArea(
          child: _MobileSettingsList(
            search: _search,
            items: items,
            userName: user?.name,
            avatarUrl: user?.avatarUrl,
            header: companySelector,
            onQueryChanged: (value) => setState(() => _query = value),
            onQueryCleared: () => setState(() => _query = ''),
            onSelect: (item) => _onSelect(
              item,
              split: false,
              independentWorker: independentWorker,
            ),
          ),
        ),
      );
    }

    if (!split) {
      final body = Column(
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
              onSelect: (item) => _onSelect(
                item,
                split: false,
                independentWorker: independentWorker,
              ),
            ),
          ),
        ],
      );
      return widget.withNavigation
          ? _railShell(body: body)
          : Material(type: MaterialType.transparency, child: body);
    }

    final body = ColoredBox(
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
                selected: selected,
                userName: user?.name,
                avatarUrl: user?.avatarUrl,
                onQueryChanged: (v) => setState(() => _query = v),
                onQueryCleared: () => setState(() => _query = ''),
                onSelect: (item) => _onSelect(
                  item,
                  split: true,
                  independentWorker: independentWorker,
                ),
              ),
            ),
          ),
          Expanded(
            child: ColoredBox(
              color: HasimTokens.pageBg(context),
              child: KeyedSubtree(
                key: const Key('hasim-settings-detail'),
                child: _SettingsDetailPane(
                  selected: selected,
                  workerLocation: _workerPaneLocation,
                  onWorkerNavigate: _openWorkerPane,
                  onWorkerBack: _backWorkerPane,
                  isCompanyUser: auth.isCompanyUser,
                  themeMode: themeMode,
                  onThemeChanged: (mode) => ref
                      .read(themeModeControllerProvider.notifier)
                      .setMode(mode),
                ),
              ),
            ),
          ),
        ],
      ),
    );
    return widget.withNavigation
        ? _railShell(body: body)
        : Material(type: MaterialType.transparency, child: body);
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
    this.sectionStart = false,
  });

  final _SettingsId id;
  final IconData icon;
  final String title;
  final String? subtitle;
  final String? route;
  final bool danger;
  final bool sectionStart;
}

class _MobileSettingsList extends StatefulWidget {
  const _MobileSettingsList({
    required this.search,
    required this.items,
    required this.onQueryChanged,
    required this.onQueryCleared,
    required this.onSelect,
    this.userName,
    this.avatarUrl,
    this.header,
  });

  final TextEditingController search;
  final List<_SettingsItem> items;
  final String? userName;
  final String? avatarUrl;
  final Widget? header;
  final ValueChanged<String> onQueryChanged;
  final VoidCallback onQueryCleared;
  final ValueChanged<_SettingsItem> onSelect;

  @override
  State<_MobileSettingsList> createState() => _MobileSettingsListState();
}

class _MobileSettingsListState extends State<_MobileSettingsList> {
  bool _searchOpen = false;
  final _searchFocus = FocusNode();

  @override
  void dispose() {
    _searchFocus.dispose();
    super.dispose();
  }

  void _toggleSearch() {
    setState(() => _searchOpen = !_searchOpen);
    if (_searchOpen) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _searchFocus.requestFocus();
      });
      return;
    }
    widget.search.clear();
    widget.onQueryCleared();
    _searchFocus.unfocus();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsetsDirectional.fromSTEB(
            _searchOpen ? 16 : 4,
            6,
            _searchOpen ? 16 : 8,
            0,
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              if (!_searchOpen)
                IconButton(
                  key: const Key('settings-back-button'),
                  tooltip: 'رجوع',
                  onPressed: () {
                    if (context.canPop()) {
                      context.pop();
                      return;
                    }
                    context.go(HasimNav.initialShellRoute);
                  },
                  icon: Icon(
                    Icons.arrow_forward_rounded,
                    size: 24,
                    color: HasimTokens.inkColor(context),
                  ),
                ),
              Expanded(
                child: _searchOpen
                    ? _PhoneSettingsSearch(
                        key: const ValueKey('settings-search-field'),
                        controller: widget.search,
                        focusNode: _searchFocus,
                        onChanged: widget.onQueryChanged,
                        onClose: _toggleSearch,
                      )
                    : Align(
                        alignment: AlignmentDirectional.centerStart,
                        child: Text(
                          'الإعدادات',
                          textAlign: TextAlign.start,
                          style: theme.textTheme.titleLarge?.copyWith(
                            fontWeight: FontWeight.w700,
                            fontSize: 20,
                            height: 1.2,
                            color: HasimTokens.inkColor(context),
                          ),
                        ),
                      ),
              ),
              if (!_searchOpen)
                IconButton(
                  key: const Key('settings-search-button'),
                  tooltip: 'بحث',
                  onPressed: _toggleSearch,
                  icon: Icon(
                    Icons.search_rounded,
                    size: 26,
                    color: HasimTokens.inkColor(context),
                  ),
                ),
            ],
          ),
        ),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(8, 14, 8, 36),
            children: [
              if (widget.header != null)
                Padding(
                  padding: const EdgeInsets.fromLTRB(8, 4, 8, 8),
                  child: widget.header!,
                ),
              for (final item in widget.items) ...[
                if (item.sectionStart)
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    child: Divider(height: 1),
                  ),
                if (item.id == _SettingsId.logout) const SizedBox(height: 18),
                if (item.id == _SettingsId.profile)
                  _PhoneProfileRow(
                    title: item.title,
                    email: item.subtitle,
                    name: widget.userName,
                    avatarUrl: widget.avatarUrl,
                    onTap: () => widget.onSelect(item),
                  )
                else
                  _PhoneSettingsRow(
                    item: item,
                    onTap: () => widget.onSelect(item),
                  ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _PhoneSettingsSearch extends StatelessWidget {
  const _PhoneSettingsSearch({
    super.key,
    required this.controller,
    required this.focusNode,
    required this.onChanged,
    required this.onClose,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final ValueChanged<String> onChanged;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    final muted = HasimTokens.mutedColor(context);
    final radius = BorderRadius.circular(24);
    final idle = OutlineInputBorder(
      borderRadius: radius,
      borderSide: BorderSide(color: HasimTokens.lineColor(context)),
    );
    final focused = OutlineInputBorder(
      borderRadius: radius,
      borderSide: const BorderSide(color: AppTheme.brand, width: 1.4),
    );
    return TextField(
      controller: controller,
      focusNode: focusNode,
      onChanged: onChanged,
      textAlign: TextAlign.start,
      textAlignVertical: TextAlignVertical.center,
      textInputAction: TextInputAction.search,
      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
        fontWeight: FontWeight.w500,
        color: HasimTokens.inkColor(context),
      ),
      decoration: InputDecoration(
        hintText: 'ابحث في الإعدادات',
        hintStyle: Theme.of(context).textTheme.bodyMedium?.copyWith(
          color: muted,
          fontWeight: FontWeight.w500,
        ),
        isDense: true,
        filled: true,
        fillColor: HasimTokens.cardBg(context),
        contentPadding: const EdgeInsetsDirectional.fromSTEB(16, 10, 4, 10),
        suffixIcon: IconButton(
          key: const Key('settings-search-close'),
          tooltip: 'إغلاق البحث',
          onPressed: onClose,
          icon: Icon(Icons.close_rounded, size: 20, color: muted),
        ),
        border: idle,
        enabledBorder: idle,
        focusedBorder: focused,
        disabledBorder: idle,
        errorBorder: idle,
        focusedErrorBorder: focused,
      ),
    );
  }
}

class _PhoneProfileRow extends StatelessWidget {
  const _PhoneProfileRow({
    required this.title,
    required this.onTap,
    this.email,
    this.name,
    this.avatarUrl,
  });

  final String title;
  final String? email;
  final String? name;
  final String? avatarUrl;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final initial = (name != null && name!.trim().isNotEmpty)
        ? name!.trim()[0]
        : 'ح';
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 10),
      child: Material(
        color: HasimTokens.mintFill(context),
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(16),
          child: Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(14, 16, 16, 16),
            child: Row(
              children: [
                _ProfileAvatar(name: name ?? initial, avatarUrl: avatarUrl),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w700,
                          fontSize: 16,
                          height: 1.3,
                          color: HasimTokens.inkColor(context),
                        ),
                      ),
                      if (email != null && email!.isNotEmpty) ...[
                        const SizedBox(height: 3),
                        Text(
                          email!,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          textDirection: TextDirection.ltr,
                          textAlign: TextAlign.end,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: HasimTokens.mutedColor(context),
                            fontWeight: FontWeight.w500,
                            fontSize: 13,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                Container(
                  width: 3,
                  height: 28,
                  decoration: BoxDecoration(
                    color: HasimTokens.primary,
                    borderRadius: BorderRadius.circular(4),
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

class _PhoneSettingsRow extends StatelessWidget {
  const _PhoneSettingsRow({required this.item, required this.onTap});

  final _SettingsItem item;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final danger = item.danger;
    final ink = danger ? HasimTokens.danger : HasimTokens.inkColor(context);
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(18, 15, 20, 15),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: danger
                      ? HasimTokens.dangerSoftColor(context)
                      : HasimTokens.mutedColor(context).withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(
                  item.icon,
                  size: 21,
                  color: danger
                      ? HasimTokens.danger
                      : HasimTokens.mutedColor(context),
                ),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                        color: ink,
                      ),
                    ),
                    if (item.subtitle != null) ...[
                      const SizedBox(height: 3),
                      Text(
                        item.subtitle!,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: HasimTokens.mutedColor(context),
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
          if (item.sectionStart)
            Padding(
              padding: const EdgeInsets.fromLTRB(4, 12, 4, 8),
              child: Divider(height: 1, color: HasimTokens.lineColor(context)),
            ),
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
                if (item.sectionStart)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(4, 12, 4, 8),
                    child: Divider(
                      height: 1,
                      color: HasimTokens.lineColor(context),
                    ),
                  ),
                if (item.id == _SettingsId.logout)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(4, 12, 4, 8),
                    child: Divider(
                      height: 1,
                      color: HasimTokens.lineColor(context),
                    ),
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
    final ink = danger
        ? theme.colorScheme.error
        : HasimTokens.inkColor(context);
    final muted = HasimTokens.mutedColor(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Material(
        color: Colors.transparent,
        elevation: 0,
        shadowColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(12),
          hoverColor: Colors.transparent,
          splashColor: Colors.transparent,
          highlightColor: Colors.transparent,
          child: Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(10, 10, 12, 10),
            child: Row(
              children: [
                if (selected)
                  Container(
                    width: 3,
                    height: 28,
                    margin: const EdgeInsetsDirectional.only(end: 8),
                    decoration: BoxDecoration(
                      color: AppTheme.brand,
                      borderRadius: BorderRadius.circular(4),
                    ),
                  ),
                leading ??
                    Container(
                      width: 28,
                      height: 28,
                      decoration: BoxDecoration(
                        color: danger
                            ? theme.colorScheme.error.withValues(alpha: 0.10)
                            : muted.withValues(alpha: 0.08),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Icon(
                        item.icon,
                        size: 16,
                        color: danger ? theme.colorScheme.error : muted,
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
                          fontWeight: selected
                              ? FontWeight.w700
                              : FontWeight.w600,
                          color: ink,
                        ),
                      ),
                      if (item.subtitle != null) ...[
                        const SizedBox(height: 2),
                        Text(
                          item.subtitle!,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: muted,
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
    final fallback = CircleAvatar(
      radius: 20,
      backgroundColor: AppTheme.brand.withValues(alpha: 0.14),
      child: Text(
        (name != null && name!.isNotEmpty) ? name![0] : 'ح',
        style: const TextStyle(
          fontSize: 16,
          fontWeight: FontWeight.w700,
          color: AppTheme.brand,
        ),
      ),
    );
    final url = AppConfig.resolvePublicUrl(avatarUrl);
    if (url == null) {
      return fallback;
    }
    return ClipOval(
      child: CachedNetworkImage(
        imageUrl: url,
        width: 40,
        height: 40,
        fit: BoxFit.cover,
        errorWidget: (_, _, _) => fallback,
      ),
    );
  }
}

class _SettingsDetailPane extends StatelessWidget {
  const _SettingsDetailPane({
    required this.selected,
    required this.workerLocation,
    required this.onWorkerNavigate,
    required this.onWorkerBack,
    required this.isCompanyUser,
    required this.themeMode,
    required this.onThemeChanged,
  });

  final _SettingsId? selected;
  final String? workerLocation;
  final ValueChanged<String> onWorkerNavigate;
  final VoidCallback onWorkerBack;
  final bool isCompanyUser;
  final ThemeMode themeMode;
  final ValueChanged<ThemeMode> onThemeChanged;

  @override
  Widget build(BuildContext context) {
    if (workerLocation != null) {
      return _workerLocationDetail(workerLocation!);
    }

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
      _SettingsId.privacyLock => _EmbeddedDetail(
        title: 'القفل والخصوصية',
        subtitle: isCompanyUser
            ? 'رمز PIN والبصمة'
            : 'رمز PIN والبصمة والدردشات المقفلة',
        icon: Icons.lock_outline,
        child: const PrivacyLockSettings(),
      ),
      _SettingsId.channels => const _EmbeddedDetail(
        title: 'القنوات',
        subtitle: 'اربط قنوات التواصل لإدارة رسائلك من مكان واحد',
        child: ChannelsScreen(embedded: true),
      ),
      _SettingsId.notifications => const _EmbeddedDetail(
        title: 'تفضيلات الإشعارات',
        child: NotificationPreferencesScreen(embedded: true),
      ),
      _SettingsId.products => const _EmbeddedDetail(
        title: 'المنتجات والمخزون',
        subtitle: 'عرض المنتجات والتصنيفات والكميات كمرجع للذكاء الاصطناعي',
        child: CatalogListScreen(embedded: true),
      ),
      _SettingsId.ai => const _EmbeddedDetail(
        title: 'إعدادات الذكاء الاصطناعي',
        child: AiSettingsScreen(embedded: true),
      ),
      _SettingsId.companyProfile => const _EmbeddedDetail(
        title: 'نبذة عن الشركة',
        subtitle: 'نشاط الشركة وما تبيعه والتعليمات للرد',
        child: CompanyProfileScreen(embedded: true),
      ),
      _SettingsId.workerSalesReference ||
      _SettingsId.workerOffers ||
      _SettingsId.workerEngagements ||
      _SettingsId.workerAccount => _workerDetail(selected),
      _SettingsId.appearance => _AppearanceDetail(
        themeMode: themeMode,
        onThemeChanged: onThemeChanged,
      ),
      _SettingsId.logout || null => const EmptyState(
        icon: Icons.settings_outlined,
        title: 'الإعدادات',
        subtitle: 'اختر أحد الإعدادات من القائمة لعرض التفاصيل هنا.',
      ),
    };
  }

  Widget _workerDetail(_SettingsId? selected) {
    final child = switch (selected) {
      _SettingsId.workerSalesReference => const WorkerSalesReferenceScreen(
        embedded: true,
      ),
      _SettingsId.workerOffers => const WorkerOffersScreen(embedded: true),
      _SettingsId.workerEngagements => const WorkerEngagementsScreen(
        embedded: true,
      ),
      _SettingsId.workerAccount => const WorkerAccountScreen(embedded: true),
      _ => const EmptyState(
        icon: Icons.work_outline,
        title: 'تنقل العامل',
        subtitle: 'اختر وجهة العامل من قائمة الإعدادات.',
      ),
    };
    return _workerPage(child);
  }

  Widget _workerLocationDetail(String location) {
    final uri = Uri.parse(location);
    final path = uri.path;
    final child = switch (path) {
      workerSalesReferenceLocation => const WorkerSalesReferenceScreen(
        embedded: true,
      ),
      workerOffersLocation => const WorkerOffersScreen(embedded: true),
      workerEngagementsLocation => const WorkerEngagementsScreen(
        embedded: true,
      ),
      workerPayoutsLocation => const WorkerPayoutsScreen(embedded: true),
      workerDisputesLocation => const WorkerDisputesScreen(embedded: true),
      workerAccountLocation =>
        uri.queryParameters['surface'] == 'profile'
            ? const WorkerProfileScreen(embedded: true)
            : const WorkerAccountScreen(embedded: true),
      _ when path.startsWith('$workerEngagementsLocation/') =>
        WorkerEngagementDetailScreen(
          engagementId: int.tryParse(path.split('/').last),
          embedded: true,
        ),
      _ when path.startsWith('$workerDisputesLocation/') =>
        WorkerDisputeDetailScreen(
          disputeId: int.tryParse(path.split('/').last),
          embedded: true,
        ),
      _ => const EmptyState(
        icon: Icons.work_outline,
        title: 'تنقل العامل',
        subtitle: 'الصفحة المطلوبة غير متاحة.',
      ),
    };
    return _workerPage(child);
  }

  Widget _workerPage(Widget child) {
    return WorkerSidePaneScope(
      onNavigate: onWorkerNavigate,
      onBack: onWorkerBack,
      child: child,
    );
  }
}

class _EmbeddedDetail extends StatelessWidget {
  const _EmbeddedDetail({
    required this.title,
    required this.child,
    this.subtitle,
    this.icon,
  });

  final String title;
  final String? subtitle;
  final IconData? icon;
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
            child: HasimPageHeader(
              title: title,
              subtitle: subtitle,
              icon: icon,
            ),
          ),
          Expanded(child: child),
        ],
      ),
    );
  }
}

class _AppearanceDetail extends StatelessWidget {
  const _AppearanceDetail({
    required this.themeMode,
    required this.onThemeChanged,
  });

  final ThemeMode themeMode;
  final ValueChanged<ThemeMode> onThemeChanged;

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: HasimTokens.pageBg(context),
      child: Align(
        alignment: Alignment.topCenter,
        child: ConstrainedBox(
          constraints: const BoxConstraints(
            maxWidth: HasimTokens.settingsContentMaxWidth,
          ),
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
