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
import 'package:hasim/core/widgets/hasim_buttons.dart';
import 'package:hasim/core/widgets/hasim_card.dart';
import 'package:hasim/router/hasim_nav.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/core/widgets/hasim_search_bar.dart';
import 'package:hasim/core/widgets/hasim_settings_body.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/home/providers/home_controller.dart';
import 'package:hasim/features/commerce/presentation/orders_list_screen.dart';
import 'package:hasim/features/customers/presentation/customers_list_screen.dart';
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
  customers,
  orders,
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
          icon: Icons.person_outline_rounded,
          title: 'ملفي الشخصي',
          subtitle: profileSubtitle,
          route: workerAccountLocation,
          category: _SettingsCategory.account,
        ),
        const _SettingsItem(
          id: _SettingsId.workerAccount,
          icon: Icons.badge_outlined,
          title: 'المعلومات والتوثيق',
          route: workerAccountLocation,
          group: 'الحساب والأمان',
          category: _SettingsCategory.account,
        ),
        const _SettingsItem(
          id: _SettingsId.security,
          icon: Icons.shield_outlined,
          title: 'الأمان والجلسات',
          route: '/more/security',
          group: 'الحساب والأمان',
          category: _SettingsCategory.security,
        ),
        const _SettingsItem(
          id: _SettingsId.privacyLock,
          icon: Icons.lock_outline_rounded,
          title: 'القفل والخصوصية',
          subtitle: 'رمز PIN والبصمة والدردشات المقفلة',
          group: 'الحساب والأمان',
          category: _SettingsCategory.privacy,
        ),
        _SettingsItem(
          id: _SettingsId.appearance,
          icon: Icons.palette_outlined,
          title: 'المظهر والسمات',
          subtitle: '${_themeLabel(themeMode)} · خلفية المحادثة',
          route: '/chat-themes',
          group: 'الحساب والأمان',
          category: _SettingsCategory.appearance,
        ),
        const _SettingsItem(
          id: _SettingsId.workerSalesReference,
          icon: Icons.menu_book_outlined,
          title: 'مرجع المبيعات',
          subtitle: 'منتجات وخدمات الشركة الحالية',
          route: workerSalesReferenceLocation,
          sectionStart: true,
          group: 'العمل والمبيعات',
          category: _SettingsCategory.worker,
        ),
        const _SettingsItem(
          id: _SettingsId.workerOffers,
          icon: Icons.local_offer_outlined,
          title: 'العروض',
          route: workerOffersLocation,
          group: 'العمل والمبيعات',
          category: _SettingsCategory.worker,
        ),
        const _SettingsItem(
          id: _SettingsId.workerEngagements,
          icon: Icons.work_outline_rounded,
          title: 'الأعمال',
          route: workerEngagementsLocation,
          group: 'العمل والمبيعات',
          category: _SettingsCategory.worker,
        ),
        const _SettingsItem(
          id: _SettingsId.logout,
          icon: Icons.logout_rounded,
          title: 'تسجيل الخروج',
          danger: true,
          category: _SettingsCategory.danger,
        ),
      ];
    }
    return [
      _SettingsItem(
        id: _SettingsId.profile,
        icon: Icons.person_outline_rounded,
        title: 'حسابي',
        subtitle: profileSubtitle,
        route: '/profile',
        category: _SettingsCategory.account,
      ),
      const _SettingsItem(
        id: _SettingsId.security,
        icon: Icons.shield_outlined,
        title: 'الأمان والجلسات',
        route: '/more/security',
        group: 'الأمان والإشعارات',
        category: _SettingsCategory.security,
      ),
      const _SettingsItem(
        id: _SettingsId.privacyLock,
        icon: Icons.lock_outline_rounded,
        title: 'القفل والخصوصية',
        subtitle: 'رمز PIN والبصمة',
        group: 'الأمان والإشعارات',
        category: _SettingsCategory.privacy,
      ),
      const _SettingsItem(
        id: _SettingsId.notifications,
        icon: Icons.notifications_outlined,
        title: 'تفضيلات الإشعارات',
        route: '/notification-preferences',
        group: 'الأمان والإشعارات',
        category: _SettingsCategory.notifications,
      ),
      const _SettingsItem(
        id: _SettingsId.channels,
        icon: Icons.hub_outlined,
        title: 'القنوات',
        route: '/channels',
        group: 'العمل والذكاء',
        category: _SettingsCategory.channels,
      ),
      const _SettingsItem(
        id: _SettingsId.customers,
        icon: Icons.people_outline_rounded,
        title: 'العملاء',
        subtitle: 'إدارة بيانات العملاء، الملاحظات، وسجل التواصل',
        route: '/customers',
        group: 'العمل والذكاء',
        category: _SettingsCategory.commerce,
      ),
      const _SettingsItem(
        id: _SettingsId.orders,
        icon: Icons.shopping_bag_outlined,
        title: 'الطلبات والمبيعات',
        subtitle: 'إدارة الطلبات، الحالات، والفواتير',
        route: '/orders',
        group: 'العمل والذكاء',
        category: _SettingsCategory.commerce,
      ),
      const _SettingsItem(
        id: _SettingsId.products,
        icon: Icons.inventory_2_outlined,
        title: 'المنتجات والمخزون',
        subtitle: 'عرض المنتجات والتصنيفات والكميات كمرجع للذكاء الاصطناعي',
        route: '/catalog',
        group: 'العمل والذكاء',
        category: _SettingsCategory.commerce,
      ),
      const _SettingsItem(
        id: _SettingsId.ai,
        icon: Icons.smart_toy_outlined,
        title: 'إعدادات الذكاء الاصطناعي',
        route: '/ai-settings',
        group: 'العمل والذكاء',
        category: _SettingsCategory.ai,
      ),
      const _SettingsItem(
        id: _SettingsId.companyProfile,
        icon: Icons.business_outlined,
        title: 'نبذة عن الشركة',
        subtitle: 'نشاط الشركة وما تبيعه والتعليمات للرد',
        route: '/ai-settings/company-profile',
        group: 'العمل والذكاء',
        category: _SettingsCategory.company,
      ),
      _SettingsItem(
        id: _SettingsId.appearance,
        icon: Icons.palette_outlined,
        title: 'المظهر والسمات',
        subtitle: '${_themeLabel(themeMode)} · خلفية المحادثة',
        route: '/chat-themes',
        group: 'التفضيلات',
        category: _SettingsCategory.appearance,
      ),
      const _SettingsItem(
        id: _SettingsId.logout,
        icon: Icons.logout_rounded,
        title: 'تسجيل الخروج',
        danger: true,
        category: _SettingsCategory.danger,
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

    final workspaceName = !independentWorker ? auth.workspace?.name : null;

    if (compact) {
      return Scaffold(
        backgroundColor: HasimTokens.isDark(context)
            ? HasimTokens.darkPage
            : const Color(0xFFF6F9F9),
        body: SafeArea(
          child: _MobileSettingsList(
            search: _search,
            items: items,
            userName: user?.name,
            avatarUrl: user?.avatarUrl,
            workspaceName: workspaceName,
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
              workspaceName: workspaceName,
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
                workspaceName: workspaceName,
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
              color: HasimTokens.settingsCanvasBg(context),
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

enum _SettingsCategory {
  account,
  security,
  privacy,
  notifications,
  channels,
  commerce,
  ai,
  company,
  appearance,
  worker,
  danger,
}

Color _categoryAccent(BuildContext context, _SettingsCategory category) {
  final scheme = Theme.of(context).colorScheme;
  final dark = HasimTokens.isDark(context);
  return switch (category) {
    _SettingsCategory.account => HasimTokens.primary,
    _SettingsCategory.security =>
      dark ? HasimTokens.primary : HasimTokens.accentDark,
    _SettingsCategory.privacy => HasimTokens.chatGreen,
    _SettingsCategory.notifications => HasimTokens.warning,
    _SettingsCategory.channels => HasimTokens.primary,
    _SettingsCategory.commerce => scheme.secondary,
    _SettingsCategory.ai => scheme.tertiary,
    _SettingsCategory.company => HasimTokens.mutedColor(context),
    _SettingsCategory.appearance => scheme.primary,
    _SettingsCategory.worker =>
      dark ? HasimTokens.primary : HasimTokens.accentDark,
    _SettingsCategory.danger => HasimTokens.danger,
  };
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
    this.group,
    this.category = _SettingsCategory.account,
  });

  final _SettingsId id;
  final IconData icon;
  final String title;
  final String? subtitle;
  final String? route;
  final bool danger;
  final bool sectionStart;
  final String? group;
  final _SettingsCategory category;
}

class _SettingsBucket {
  const _SettingsBucket({this.title, required this.items});

  final String? title;
  final List<_SettingsItem> items;
}

List<_SettingsBucket> _groupSettingsItems(List<_SettingsItem> items) {
  final buckets = <_SettingsBucket>[];
  String? currentGroup;
  var current = <_SettingsItem>[];

  void flush() {
    if (current.isEmpty) return;
    buckets.add(_SettingsBucket(title: currentGroup, items: current));
    current = <_SettingsItem>[];
  }

  for (final item in items) {
    if (item.id == _SettingsId.profile || item.id == _SettingsId.logout) {
      continue;
    }
    if (item.group != currentGroup) {
      flush();
      currentGroup = item.group;
    }
    current.add(item);
  }
  flush();
  return buckets;
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
    this.workspaceName,
    this.header,
  });

  final TextEditingController search;
  final List<_SettingsItem> items;
  final String? userName;
  final String? avatarUrl;
  final String? workspaceName;
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
    _SettingsItem? profileItem;
    _SettingsItem? logoutItem;
    for (final item in widget.items) {
      if (item.id == _SettingsId.profile) profileItem = item;
      if (item.id == _SettingsId.logout) logoutItem = item;
    }
    final buckets = _groupSettingsItems(widget.items);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          decoration: BoxDecoration(
            color: HasimTokens.cardBg(context),
            border: Border(
              bottom: BorderSide(color: HasimTokens.lineColor(context)),
            ),
          ),
          padding: EdgeInsetsDirectional.fromSTEB(
            _searchOpen ? 16 : 4,
            4,
            _searchOpen ? 16 : 8,
            4,
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
                            fontWeight: FontWeight.w800,
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
                    size: 24,
                    color: HasimTokens.inkColor(context),
                  ),
                ),
            ],
          ),
        ),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(14, 10, 14, 28),
            children: [
              if (widget.header != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: widget.header!,
                ),
              if (widget.items.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 48),
                  child: EmptyState(
                    icon: Icons.search_off_rounded,
                    title: 'لا توجد نتائج',
                    subtitle: 'جرّب كلمة أخرى للبحث في الإعدادات.',
                  ),
                ),
              if (profileItem != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: _PhoneProfileRow(
                    title: profileItem.title,
                    email: profileItem.subtitle,
                    name: widget.userName,
                    avatarUrl: widget.avatarUrl,
                    workspaceName: widget.workspaceName,
                    onTap: () => widget.onSelect(profileItem!),
                  ),
                ),
              for (final bucket in buckets) ...[
                if (bucket.title != null)
                  Padding(
                    padding: const EdgeInsetsDirectional.fromSTEB(
                      6,
                      4,
                      6,
                      4,
                    ),
                    child: Text(
                      bucket.title!,
                      style: theme.textTheme.labelMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: HasimTokens.mutedColor(context),
                      ),
                    ),
                  ),
                HasimCard(
                  padding: EdgeInsets.zero,
                  radius: 14,
                  margin: const EdgeInsets.only(bottom: 8),
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(13),
                    child: Column(
                      children: [
                        for (var i = 0; i < bucket.items.length; i++) ...[
                          if (i > 0)
                            Divider(
                              height: 1,
                              indent: 60,
                              color: HasimTokens.lineColor(context),
                            ),
                          _PhoneSettingsRow(
                            item: bucket.items[i],
                            onTap: () => widget.onSelect(bucket.items[i]),
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
              ],
              if (logoutItem != null)
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: HasimCard(
                    padding: EdgeInsets.zero,
                    radius: 14,
                    borderColor: HasimTokens.danger.withValues(alpha: 0.22),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(13),
                      child: _PhoneSettingsRow(
                        item: logoutItem,
                        onTap: () => widget.onSelect(logoutItem!),
                      ),
                    ),
                  ),
                ),
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
    this.workspaceName,
  });

  final String title;
  final String? email;
  final String? name;
  final String? avatarUrl;
  final String? workspaceName;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final initial = (name != null && name!.trim().isNotEmpty)
        ? name!.trim()[0]
        : 'ح';
    final displayName = (name != null && name!.trim().isNotEmpty)
        ? name!.trim()
        : null;
    final dark = HasimTokens.isDark(context);
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: dark
            ? null
            : const LinearGradient(
                begin: AlignmentDirectional.topStart,
                end: AlignmentDirectional.bottomEnd,
                colors: [Color(0xFFEAF9F7), Colors.white],
              ),
        color: dark ? HasimTokens.darkCard : null,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(
          color: HasimTokens.primary.withValues(alpha: dark ? 0.35 : 0.25),
        ),
        boxShadow: HasimTokens.softShadow(context),
      ),
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(18),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(18),
          child: Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(12, 11, 14, 11),
            child: Row(
              children: [
                Container(
                  width: 44,
                  height: 44,
                  padding: const EdgeInsets.all(2),
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    border: Border.all(
                      color: HasimTokens.primary.withValues(alpha: 0.45),
                      width: 1.5,
                    ),
                  ),
                  child: _ProfileAvatar(
                    name: name ?? initial,
                    avatarUrl: avatarUrl,
                    radius: 19,
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Wrap(
                        spacing: 8,
                        runSpacing: 4,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          Text(
                            title,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.titleMedium?.copyWith(
                              fontWeight: FontWeight.w800,
                              fontSize: 16,
                              height: 1.3,
                              color: HasimTokens.inkColor(context),
                            ),
                          ),
                          if (workspaceName != null &&
                              workspaceName!.trim().isNotEmpty)
                            HasimBadge(label: workspaceName!.trim()),
                        ],
                      ),
                      if (displayName != null && displayName != title) ...[
                        const SizedBox(height: 2),
                        Text(
                          displayName,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: HasimTokens.inkColor(context),
                            fontWeight: FontWeight.w600,
                            fontSize: 13.5,
                          ),
                        ),
                      ],
                      if (email != null && email!.isNotEmpty) ...[
                        const SizedBox(height: 2),
                        Text(
                          email!,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          textDirection: TextDirection.ltr,
                          textAlign: TextAlign.end,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: HasimTokens.mutedColor(context),
                            fontWeight: FontWeight.w500,
                            fontSize: 12.5,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Container(
                  width: 3,
                  height: 30,
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
    final accent = _categoryAccent(context, item.category);
    final dark = HasimTokens.isDark(context);
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(12, 9, 12, 9),
          child: Row(
            children: [
              Container(
                width: 34,
                height: 34,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: danger
                      ? HasimTokens.dangerSoftColor(context)
                      : accent.withValues(alpha: dark ? 0.16 : 0.10),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Icon(
                  item.icon,
                  size: 18,
                  color: danger ? HasimTokens.danger : accent,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall?.copyWith(
                        fontSize: 15,
                        fontWeight: FontWeight.w700,
                        color: ink,
                      ),
                    ),
                    if (item.subtitle != null) ...[
                      const SizedBox(height: 2),
                      Text(
                        item.subtitle!,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: HasimTokens.mutedColor(context),
                          fontSize: 12.5,
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
    this.workspaceName,
  });

  final List<_SettingsItem> items;
  final ValueChanged<_SettingsItem> onSelect;
  final String? userName;
  final String? avatarUrl;
  final String? workspaceName;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) {
      return const Center(
        child: EmptyState(
          icon: Icons.search_off_rounded,
          title: 'لا توجد نتائج',
          subtitle: 'جرّب كلمة بحث أخرى.',
        ),
      );
    }
    return ListView(
      padding: const EdgeInsets.fromLTRB(12, 4, 12, 20),
      children: _buildPanelChildren(
        context,
        items: items,
        selected: null,
        userName: userName,
        avatarUrl: avatarUrl,
        workspaceName: workspaceName,
        onSelect: onSelect,
      ),
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
    this.workspaceName,
  });

  final TextEditingController search;
  final List<_SettingsItem> items;
  final _SettingsId? selected;
  final String? userName;
  final String? avatarUrl;
  final String? workspaceName;
  final ValueChanged<String> onQueryChanged;
  final VoidCallback onQueryCleared;
  final ValueChanged<_SettingsItem> onSelect;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Padding(
          padding: EdgeInsets.fromLTRB(14, 8, 14, 0),
          child: HasimPageHeader(title: 'الإعدادات'),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 0, 14, 8),
          child: HasimSearchBar(
            controller: search,
            hintText: 'ابحث في الإعدادات',
            onChanged: onQueryChanged,
            onClear: onQueryCleared,
          ),
        ),
        Divider(height: 1, color: HasimTokens.lineColor(context)),
        Expanded(
          child: items.isEmpty
              ? const Center(
                  child: EmptyState(
                    icon: Icons.search_off_rounded,
                    title: 'لا توجد نتائج',
                    subtitle: 'جرّب كلمة بحث أخرى.',
                  ),
                )
              : ListView(
                  padding: const EdgeInsets.fromLTRB(10, 4, 10, 16),
                  children: _buildPanelChildren(
                    context,
                    items: items,
                    selected: selected,
                    userName: userName,
                    avatarUrl: avatarUrl,
                    workspaceName: workspaceName,
                    onSelect: onSelect,
                  ),
                ),
        ),
      ],
    );
  }
}

List<Widget> _buildPanelChildren(
  BuildContext context, {
  required List<_SettingsItem> items,
  required _SettingsId? selected,
  required String? userName,
  required String? avatarUrl,
  required String? workspaceName,
  required ValueChanged<_SettingsItem> onSelect,
}) {
  final children = <Widget>[];
  String? lastGroup;
  for (final item in items) {
    if (item.id == _SettingsId.logout) {
      children.add(
        Padding(
          padding: const EdgeInsets.fromLTRB(6, 10, 6, 8),
          child: Divider(height: 1, color: HasimTokens.lineColor(context)),
        ),
      );
    } else if (item.group != null && item.group != lastGroup) {
      if (children.isNotEmpty) {
        children.add(const SizedBox(height: 5));
      }
      children.add(
        Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(10, 2, 10, 2),
          child: Text(
            item.group!,
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
              color: HasimTokens.mutedColor(context),
              fontWeight: FontWeight.w700,
            ),
          ),
        ),
      );
      lastGroup = item.group;
    }
    children.add(
      _SettingsPanelRow(
        item: item,
        selected: selected == item.id,
        leading: item.id == _SettingsId.profile
            ? _ProfileAvatar(name: userName, avatarUrl: avatarUrl)
            : null,
        trailingBadge:
            item.id == _SettingsId.profile &&
                workspaceName != null &&
                workspaceName.trim().isNotEmpty
            ? workspaceName.trim()
            : null,
        onTap: () => onSelect(item),
      ),
    );
  }
  return children;
}

class _SettingsPanelRow extends StatelessWidget {
  const _SettingsPanelRow({
    required this.item,
    required this.selected,
    required this.onTap,
    this.leading,
    this.trailingBadge,
  });

  final _SettingsItem item;
  final bool selected;
  final VoidCallback onTap;
  final Widget? leading;
  final String? trailingBadge;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final danger = item.danger;
    final ink = danger
        ? HasimTokens.danger
        : (selected
              ? (HasimTokens.isDark(context)
                    ? Colors.white
                    : HasimTokens.ink)
              : HasimTokens.inkColor(context));
    final muted = HasimTokens.mutedColor(context);
    final accent = _categoryAccent(context, item.category);
    final dark = HasimTokens.isDark(context);
    final rowBg = selected
        ? HasimTokens.mintFill(context)
        : Colors.transparent;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 1),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        decoration: BoxDecoration(
          color: rowBg,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: selected
                ? HasimTokens.primary.withValues(alpha: dark ? 0.35 : 0.25)
                : Colors.transparent,
          ),
        ),
        child: Material(
          color: Colors.transparent,
          borderRadius: BorderRadius.circular(12),
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(12),
            hoverColor: danger
                ? HasimTokens.danger.withValues(alpha: 0.06)
                : HasimTokens.primary.withValues(alpha: 0.06),
            child: Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(8, 6, 10, 6),
              child: Row(
                children: [
                  AnimatedContainer(
                    duration: const Duration(milliseconds: 150),
                    width: selected ? 3 : 0,
                    height: selected ? 22 : 0,
                    margin: EdgeInsetsDirectional.only(end: selected ? 8 : 0),
                    decoration: BoxDecoration(
                      color: AppTheme.brand,
                      borderRadius: BorderRadius.circular(4),
                    ),
                  ),
                  leading ??
                      Container(
                        width: 30,
                        height: 30,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: danger
                              ? HasimTokens.dangerSoftColor(context)
                              : (selected
                                    ? accent.withValues(
                                        alpha: dark ? 0.22 : 0.14,
                                      )
                                    : accent.withValues(
                                        alpha: dark ? 0.14 : 0.09,
                                      )),
                          borderRadius: BorderRadius.circular(9),
                        ),
                        child: Icon(
                          item.icon,
                          size: 16,
                          color: danger ? HasimTokens.danger : accent,
                        ),
                      ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(
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
                            ),
                            if (trailingBadge != null) ...[
                              const SizedBox(width: 6),
                              HasimBadge(label: trailingBadge!),
                            ],
                          ],
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
      ),
    );
  }
}

class _ProfileAvatar extends StatelessWidget {
  const _ProfileAvatar({this.name, this.avatarUrl, this.radius = 20});

  final String? name;
  final String? avatarUrl;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final size = radius * 2;
    final fallback = CircleAvatar(
      radius: radius,
      backgroundColor: AppTheme.brand.withValues(alpha: 0.14),
      child: Text(
        (name != null && name!.isNotEmpty) ? name![0] : 'ح',
        style: TextStyle(
          fontSize: radius * 0.8,
          fontWeight: FontWeight.w800,
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
        width: size,
        height: size,
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

    final Widget content = switch (selected) {
      _SettingsId.profile => const _EmbeddedDetail(
        key: ValueKey('detail-profile'),
        title: 'حسابي',
        subtitle: 'إدارة معلومات حسابك الشخصي وكلمة المرور',
        icon: Icons.person_outline_rounded,
        child: ProfileScreen(embedded: true),
      ),
      _SettingsId.security => const _EmbeddedDetail(
        key: ValueKey('detail-security'),
        title: 'الأمان والجلسات',
        subtitle: 'إدارة أمان حسابك ومتابعة الأجهزة النشطة',
        icon: Icons.shield_outlined,
        child: SecuritySessionsScreen(embedded: true),
      ),
      _SettingsId.privacyLock => _EmbeddedDetail(
        key: const ValueKey('detail-privacy'),
        title: 'القفل والخصوصية',
        subtitle: isCompanyUser
            ? 'رمز PIN والبصمة'
            : 'رمز PIN والبصمة والدردشات المقفلة',
        icon: Icons.lock_outline_rounded,
        child: const PrivacyLockSettings(embedded: true),
      ),
      _SettingsId.channels => const _EmbeddedDetail(
        key: ValueKey('detail-channels'),
        title: 'القنوات',
        subtitle: 'اربط قنوات التواصل لإدارة رسائلك من مكان واحد',
        icon: Icons.hub_outlined,
        child: ChannelsScreen(embedded: true),
      ),
      _SettingsId.notifications => const _EmbeddedDetail(
        key: ValueKey('detail-notifications'),
        title: 'تفضيلات الإشعارات',
        subtitle: 'تحكم في تنبيهات الرسائل والبريد والعروض',
        icon: Icons.notifications_outlined,
        child: NotificationPreferencesScreen(embedded: true),
      ),
      _SettingsId.customers => const _EmbeddedDetail(
        key: ValueKey('detail-customers'),
        title: 'العملاء',
        subtitle: 'إدارة بيانات العملاء، الملاحظات، وسجل التواصل',
        icon: Icons.people_outline_rounded,
        constrainHeader: false,
        child: CustomersListScreen(),
      ),
      _SettingsId.orders => const _EmbeddedDetail(
        key: ValueKey('detail-orders'),
        title: 'الطلبات والمبيعات',
        subtitle: 'إدارة الطلبات، الحالات، والفواتير',
        icon: Icons.shopping_bag_outlined,
        constrainHeader: false,
        child: OrdersListScreen(),
      ),
      _SettingsId.products => const _EmbeddedDetail(
        key: ValueKey('detail-products'),
        title: 'المنتجات والمخزون',
        subtitle: 'عرض المنتجات والتصنيفات والكميات كمرجع للذكاء الاصطناعي',
        icon: Icons.inventory_2_outlined,
        constrainHeader: false,
        child: CatalogListScreen(embedded: true),
      ),
      _SettingsId.ai => const _EmbeddedDetail(
        key: ValueKey('detail-ai'),
        title: 'إعدادات الذكاء الاصطناعي',
        subtitle: 'ضبط موظفي الذكاء الاصطناعي وأسلوب الرد والتعاقد',
        icon: Icons.smart_toy_outlined,
        child: AiSettingsScreen(embedded: true),
      ),
      _SettingsId.companyProfile => const _EmbeddedDetail(
        key: ValueKey('detail-company'),
        title: 'نبذة عن الشركة',
        subtitle: 'نشاط الشركة وما تبيعه والتعليمات للرد',
        icon: Icons.business_outlined,
        child: CompanyProfileScreen(embedded: true),
      ),
      _SettingsId.workerSalesReference ||
      _SettingsId.workerOffers ||
      _SettingsId.workerEngagements ||
      _SettingsId.workerAccount => KeyedSubtree(
        key: ValueKey('detail-$selected'),
        child: _workerDetail(selected),
      ),
      _SettingsId.appearance => _AppearanceDetail(
        key: const ValueKey('detail-appearance'),
        themeMode: themeMode,
        onThemeChanged: onThemeChanged,
      ),
      _SettingsId.logout || null => const EmptyState(
        key: ValueKey('detail-empty'),
        icon: Icons.settings_outlined,
        title: 'الإعدادات',
        subtitle: 'اختر أحد الإعدادات من القائمة لعرض التفاصيل هنا.',
      ),
    };

    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 160),
      switchInCurve: Curves.easeOutCubic,
      switchOutCurve: Curves.easeInCubic,
      child: content,
    );
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
    super.key,
    required this.title,
    required this.child,
    this.subtitle,
    this.icon,
    this.constrainHeader = true,
  });

  final String title;
  final String? subtitle;
  final IconData? icon;
  final bool constrainHeader;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final detailBg = HasimTokens.settingsCanvasBg(context);
    final header = Padding(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 10),
      child: HasimPageHeader(
        title: title,
        subtitle: subtitle,
        icon: icon,
      ),
    );
    return ColoredBox(
      color: detailBg,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ColoredBox(
            color: HasimTokens.cardBg(context),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (constrainHeader)
                  Align(
                    alignment: Alignment.topCenter,
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(
                        maxWidth: HasimTokens.settingsContentMaxWidth,
                      ),
                      child: header,
                    ),
                  )
                else
                  header,
                Divider(height: 1, color: HasimTokens.lineColor(context)),
              ],
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
    super.key,
    required this.themeMode,
    required this.onThemeChanged,
  });

  final ThemeMode themeMode;
  final ValueChanged<ThemeMode> onThemeChanged;

  @override
  Widget build(BuildContext context) {
    return _EmbeddedDetail(
      title: 'المظهر والسمات',
      subtitle: 'وضع التطبيق وخلفية شاشة المحادثة',
      icon: Icons.palette_outlined,
      child: HasimSettingsBody(
        children: [
          DisplayAppearanceSettings(
            themeMode: themeMode,
            onThemeChanged: onThemeChanged,
          ),
        ],
      ),
    );
  }
}
