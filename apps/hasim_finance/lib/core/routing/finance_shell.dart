import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/permissions/finance_permissions.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class _Dest {
  const _Dest(this.id, this.path, this.icon, this.label, this.visible);
  final String id;
  final String path;
  final IconData icon;
  final String Function(AppLocalizations l) label;
  final bool Function(FinancePermissions p) visible;

  bool matches(String path) => path == this.path || path.startsWith('${this.path}/');
}

class _Group {
  const _Group(this.id, this.icon, this.title, this.items);
  final String id;
  final IconData icon;
  final String Function(AppLocalizations l) title;
  final List<_Dest> items;

  bool containsPath(String path) => items.any((item) => item.matches(path));
}

class FinanceSidebarExpansion extends Notifier<Set<String>> {
  @override
  Set<String> build() => <String>{};

  void toggle(String id) {
    final next = {...state};
    if (next.contains(id)) {
      next.remove(id);
    } else {
      next.add(id);
    }
    state = next;
  }

  void ensure(String id) {
    if (state.contains(id)) return;
    state = {...state, id};
  }
}

final financeSidebarExpansionProvider = NotifierProvider<FinanceSidebarExpansion, Set<String>>(FinanceSidebarExpansion.new);

List<_Dest> _leaves(FinancePermissions _) => [
      _Dest('dashboard', '/dashboard', Icons.grid_view_rounded, (l) => l.dashboard, (p) => p.financeView),
      _Dest('billing', '/billing', Icons.receipt_long_outlined, (l) => l.billingHub, (p) => p.invoicesView),
    ];

List<_Group> _navGroups(FinancePermissions _) => [
      _Group('sales', Icons.trending_up_rounded, (l) => l.navSales, [
        _Dest('sales', '/sales', Icons.trending_up_rounded, (l) => l.salesHub, (p) => p.financeView),
        _Dest('quotes', '/quotes', Icons.request_quote_outlined, (l) => l.quotes, (p) => p.quotesView),
        _Dest('invoices', '/invoices', Icons.description_outlined, (l) => l.invoices, (p) => p.invoicesView),
        _Dest('contracts', '/contracts', Icons.handshake_outlined, (l) => l.contracts, (p) => p.contractsView),
        _Dest('price-lists', '/price-lists', Icons.sell_outlined, (l) => l.priceLists, (p) => p.priceListsView || p.financeView),
        _Dest('leads', '/leads', Icons.person_search_outlined, (l) => l.leads, (p) => p.financeView),
        _Dest('notes', '/notes', Icons.note_alt_outlined, (l) => l.notes, (p) => p.notesView),
      ]),
      _Group('payments', Icons.payments_outlined, (l) => l.navPayments, [
        _Dest('payments', '/payments', Icons.payments_outlined, (l) => l.payments, (p) => p.paymentsView),
        _Dest('receipts', '/receipts', Icons.assignment_turned_in_outlined, (l) => l.receipts, (p) => p.receiptsView),
        _Dest('statements', '/statements', Icons.account_balance_wallet_outlined, (l) => l.statements, (p) => p.statementsView),
      ]),
      _Group('parties', Icons.groups_outlined, (l) => l.navParties, [
        _Dest('customers', '/customers', Icons.groups_outlined, (l) => l.customers, (p) => p.customersView),
        _Dest('suppliers', '/suppliers', Icons.storefront_outlined, (l) => l.suppliers, (p) => p.purchasesView),
      ]),
      _Group('purchases', Icons.shopping_bag_outlined, (l) => l.navPurchases, [
        _Dest('purchases', '/purchases', Icons.shopping_bag_outlined, (l) => l.purchases, (p) => p.purchasesView),
        _Dest('purchase-orders', '/purchase-orders', Icons.local_shipping_outlined, (l) => l.purchaseOrders, (p) => p.purchasesView || p.financeView),
      ]),
      _Group('ops', Icons.inventory_2_outlined, (l) => l.navOps, [
        _Dest('expenses', '/expenses', Icons.money_off_outlined, (l) => l.expenses, (p) => p.expensesView),
        _Dest('products', '/products', Icons.inventory_2_outlined, (l) => l.products, (p) => p.financeView),
        _Dest('inventory', '/inventory', Icons.warehouse_outlined, (l) => l.inventory, (p) => p.financeView),
        _Dest('projects', '/projects', Icons.folder_outlined, (l) => l.projects, (p) => p.financeView),
      ]),
      _Group('reports', Icons.bar_chart_outlined, (l) => l.navReports, [
        _Dest('reports', '/reports', Icons.bar_chart_outlined, (l) => l.reports, (p) => p.reportsView),
        _Dest('exports', '/exports', Icons.file_download_outlined, (l) => l.exports, (p) => p.financeView),
        _Dest('alerts', '/alerts', Icons.notifications_outlined, (l) => l.alerts, (p) => p.financeView),
        _Dest('copilot', '/copilot', Icons.psychology_outlined, (l) => l.copilot, (p) => p.financeView),
      ]),
      _Group('accounting', Icons.account_tree_outlined, (l) => l.navAccounting, [
        _Dest('accounting', '/accounting', Icons.account_tree_outlined, (l) => l.accountingHub, (p) => p.accountingView || p.financeView),
        _Dest('fiscal-years', '/fiscal-years', Icons.calendar_month_outlined, (l) => l.fiscalYears, (p) => p.fiscalYearsView || p.financeView),
        _Dest('vat', '/vat', Icons.percent_outlined, (l) => l.vatPage, (p) => p.accountingView || p.financeView),
      ]),
      _Group('banks', Icons.account_balance_outlined, (l) => l.navBanks, [
        _Dest('banks', '/banks', Icons.account_balance_outlined, (l) => l.banks, (p) => p.financeView),
        _Dest('treasury', '/treasury', Icons.swap_horiz_outlined, (l) => l.treasury, (p) => p.financeView),
      ]),
      _Group('people', Icons.badge_outlined, (l) => l.navPeople, [
        _Dest('people', '/people', Icons.badge_outlined, (l) => l.peopleObligations, (p) => p.payrollView),
        _Dest('payroll', '/payroll', Icons.account_balance_wallet_outlined, (l) => l.payroll, (p) => p.payrollView),
        _Dest('advances', '/advances', Icons.payments_outlined, (l) => l.salaryAdvances, (p) => p.salaryAdvancesView),
        _Dest('allowances', '/allowances', Icons.add_card_outlined, (l) => l.allowances, (p) => p.adjustmentsView),
        _Dest('bonuses', '/bonuses', Icons.emoji_events_outlined, (l) => l.bonuses, (p) => p.adjustmentsView),
        _Dest('deductions', '/deductions', Icons.remove_circle_outline, (l) => l.deductions, (p) => p.adjustmentsView),
      ]),
    ];

_Dest _settingsDest() => _Dest('settings', '/settings', Icons.settings_outlined, (l) => l.settings, (_) => true);

class FinanceShell extends ConsumerWidget {
  const FinanceShell({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    final leaves = _leaves(auth.permissions).where((item) => item.visible(auth.permissions)).toList();
    final groups = _navGroups(auth.permissions)
        .map(
          (group) => _Group(
            group.id,
            group.icon,
            group.title,
            group.items.where((item) => item.visible(auth.permissions)).toList(),
          ),
        )
        .where((group) => group.items.isNotEmpty)
        .toList();
    final settings = _settingsDest();
    final dests = [
      ...leaves,
      for (final group in groups) ...group.items,
      if (settings.visible(auth.permissions)) settings,
    ];
    final path = GoRouterState.of(context).uri.path;
    final wide = MediaQuery.sizeOf(context).width >= 980;
    final selectedIndex = dests.indexWhere((d) => d.matches(path));
    final _Group? activeGroup = () {
      for (final group in groups) {
        if (group.containsPath(path)) return group;
      }
      return null;
    }();
    final opened = ref.watch(financeSidebarExpansionProvider);

    if (activeGroup != null && !opened.contains(activeGroup.id)) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (context.mounted) ref.read(financeSidebarExpansionProvider.notifier).ensure(activeGroup.id);
      });
    }

    final palette = context.hs;
    final sidebar = Container(
      width: MediaQuery.sizeOf(context).width >= 1440 ? FinanceTokens.sidebarWidthWide : FinanceTokens.sidebarWidth,
      decoration: BoxDecoration(
        color: palette.sidebar,
        border: Border(left: BorderSide(color: palette.border), right: BorderSide(color: palette.border)),
      ),
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 18, 16, 12),
            child: Row(
              children: [
                Container(
                  width: 38,
                  height: 38,
                  decoration: BoxDecoration(
                    color: FinanceTokens.brand,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: const Icon(Icons.account_balance_wallet_rounded, color: Colors.white, size: 20),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(l.appName, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, height: 1.2)),
                      Text(
                        auth.workspace?.name ?? l.workspace,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(fontSize: 11, color: palette.textMuted, fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(10, 10, 10, 24),
              children: [
                for (final item in leaves)
                  _NavTile(
                    icon: item.icon,
                    label: item.label(l),
                    selected: item.matches(path),
                    onTap: () => context.go(item.path),
                  ),
                if (leaves.isNotEmpty && groups.isNotEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 8, horizontal: 8),
                    child: Divider(height: 1),
                  ),
                for (final group in groups)
                  _NavGroup(
                    icon: group.icon,
                    label: group.title(l),
                    expanded: opened.contains(group.id) || group.containsPath(path),
                    childActive: group.containsPath(path),
                    onToggle: () => ref.read(financeSidebarExpansionProvider.notifier).toggle(group.id),
                    children: [
                      for (final item in group.items)
                        _NavTile(
                          icon: item.icon,
                          label: item.label(l),
                          selected: item.matches(path),
                          nested: true,
                          onTap: () => context.go(item.path),
                        ),
                    ],
                  ),
                if (settings.visible(auth.permissions)) ...[
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 8, horizontal: 8),
                    child: Divider(height: 1),
                  ),
                  _NavTile(
                    icon: settings.icon,
                    label: settings.label(l),
                    selected: settings.matches(path),
                    onTap: () => context.go(settings.path),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );

    final compact = MediaQuery.sizeOf(context).width < 720;
    final header = Container(
      height: FinanceTokens.headerHeight,
      decoration: BoxDecoration(
        color: palette.header,
        border: Border(bottom: BorderSide(color: palette.border)),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 12),
      child: Row(
        children: [
          _HeaderIconButton(icon: Icons.search_rounded, onPressed: () => context.go('/search'), tooltip: l.globalSearch),
          if (!compact) ...[
            const SizedBox(width: 10),
            Expanded(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 520),
                child: InkWell(
                  onTap: () => context.go('/search'),
                  borderRadius: BorderRadius.circular(999),
                  child: Container(
                    height: 40,
                    padding: const EdgeInsets.symmetric(horizontal: 14),
                    decoration: BoxDecoration(
                      color: palette.input,
                      borderRadius: BorderRadius.circular(999),
                      border: Border.all(color: palette.border),
                    ),
                    child: Row(
                      children: [
                        Icon(Icons.search_rounded, size: 18, color: palette.textFaint),
                        const SizedBox(width: 8),
                        Text(l.searchPlaceholder, style: TextStyle(color: palette.textFaint, fontSize: 13)),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ] else
            const Spacer(),
          const SizedBox(width: 8),
          _HeaderIconButton(icon: Icons.notifications_none_rounded, onPressed: () => context.go('/alerts'), tooltip: l.alerts),
          const SizedBox(width: 4),
          if (!compact) ...[
            _HeaderIconButton(icon: Icons.settings_outlined, onPressed: () => context.go('/settings'), tooltip: l.settings),
            const SizedBox(width: 8),
          ],
          _UserChip(
            name: auth.user?.name ?? '',
            workspace: auth.workspace?.name ?? '',
            compact: compact,
            onPressed: () => context.go('/settings'),
          ),
        ],
      ),
    );

    final bottom = NavigationBar(
      selectedIndex: selectedIndex < 0 ? 0 : (selectedIndex > 3 ? 3 : selectedIndex),
      onDestinationSelected: (i) {
        if (i < 3 && dests.length > i) {
          context.go(dests[i].path);
        } else {
          _showMore(context, ref, leaves, groups, settings, dests.take(3).map((d) => d.path).toSet(), path, l);
        }
      },
      destinations: [
        for (final d in dests.take(3)) NavigationDestination(icon: Icon(d.icon), label: d.label(l)),
        NavigationDestination(icon: const Icon(Icons.more_horiz), label: l.more),
      ],
    );

    return Scaffold(
      body: wide
          ? Row(
              children: [
                sidebar,
                Expanded(
                  child: Column(
                    children: [
                      header,
                      Expanded(child: child),
                    ],
                  ),
                ),
              ],
            )
          : Column(
              children: [
                header,
                Expanded(child: child),
              ],
            ),
      bottomNavigationBar: wide ? null : bottom,
    );
  }

  void _showMore(
    BuildContext shellContext,
    WidgetRef ref,
    List<_Dest> leaves,
    List<_Group> groups,
    _Dest settings,
    Set<String> pinned,
    String path,
    AppLocalizations l,
  ) {
    showModalBottomSheet<void>(
      context: shellContext,
      isScrollControlled: true,
      builder: (ctx) => Consumer(
        builder: (context, ref, _) {
          final opened = ref.watch(financeSidebarExpansionProvider);
          return DraggableScrollableSheet(
            expand: false,
            initialChildSize: 0.7,
            builder: (_, controller) => ListView(
              controller: controller,
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 24),
              children: [
                for (final item in leaves.where((item) => !pinned.contains(item.path)))
                  _NavTile(
                    icon: item.icon,
                    label: item.label(l),
                    selected: item.matches(path),
                    onTap: () {
                      Navigator.pop(ctx);
                      shellContext.go(item.path);
                    },
                  ),
                for (final group in groups)
                  _NavGroup(
                    icon: group.icon,
                    label: group.title(l),
                    expanded: opened.contains(group.id) || group.containsPath(path),
                    childActive: group.containsPath(path),
                    onToggle: () => ref.read(financeSidebarExpansionProvider.notifier).toggle(group.id),
                    children: [
                      for (final item in group.items)
                        _NavTile(
                          icon: item.icon,
                          label: item.label(l),
                          selected: item.matches(path),
                          nested: true,
                          onTap: () {
                            Navigator.pop(ctx);
                            shellContext.go(item.path);
                          },
                        ),
                    ],
                  ),
                _NavTile(
                  icon: settings.icon,
                  label: settings.label(l),
                  selected: settings.matches(path),
                  onTap: () {
                    Navigator.pop(ctx);
                    shellContext.go(settings.path);
                  },
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _NavGroup extends StatelessWidget {
  const _NavGroup({
    required this.icon,
    required this.label,
    required this.expanded,
    required this.childActive,
    required this.onToggle,
    required this.children,
  });

  final IconData icon;
  final String label;
  final bool expanded;
  final bool childActive;
  final VoidCallback onToggle;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final p = context.hs;
    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 1),
            child: InkWell(
              onTap: onToggle,
              borderRadius: BorderRadius.circular(10),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 160),
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                decoration: BoxDecoration(
                  color: childActive && !expanded ? p.brandSoft : Colors.transparent,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Row(
                  children: [
                    Icon(icon, size: 18, color: childActive ? p.brandAccent : p.textMuted),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        label,
                        style: TextStyle(
                          fontSize: 13,
                          fontWeight: childActive || expanded ? FontWeight.w800 : FontWeight.w700,
                          color: childActive ? p.brandAccent : p.text,
                        ),
                      ),
                    ),
                    AnimatedRotation(
                      turns: expanded ? 0.5 : 0,
                      duration: const Duration(milliseconds: 200),
                      child: Icon(
                        Icons.keyboard_arrow_down_rounded,
                        size: 18,
                        color: childActive ? p.brandAccent : p.textFaint,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          ClipRect(
            child: AnimatedSize(
              duration: const Duration(milliseconds: 220),
              curve: Curves.easeInOutCubic,
              alignment: Alignment.topCenter,
              child: expanded
                  ? Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: children,
                    )
                  : const SizedBox(width: double.infinity),
            ),
          ),
        ],
      ),
    );
  }
}

class _NavTile extends StatelessWidget {
  const _NavTile({
    required this.icon,
    required this.label,
    required this.selected,
    required this.onTap,
    this.nested = false,
  });

  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback onTap;
  final bool nested;

  @override
  Widget build(BuildContext context) {
    final p = context.hs;
    return Padding(
      padding: EdgeInsetsDirectional.only(start: nested ? 14 : 0, top: 1, bottom: 1),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(10),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 160),
          padding: EdgeInsetsDirectional.only(start: nested ? 10 : 10, end: 10, top: nested ? 7 : 8, bottom: nested ? 7 : 8),
          decoration: FinanceTokens.sidebarItem(selected: selected, context: context),
          child: Row(
            children: [
              Icon(
                icon,
                size: nested ? 16 : 18,
                color: selected ? p.brandAccent : p.textMuted,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  label,
                  style: TextStyle(
                    fontSize: nested ? 12.5 : 13,
                    fontWeight: selected ? FontWeight.w800 : FontWeight.w600,
                    color: selected ? p.brandAccent : p.text,
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

class _HeaderIconButton extends StatelessWidget {
  const _HeaderIconButton({required this.icon, required this.onPressed, required this.tooltip});

  final IconData icon;
  final VoidCallback onPressed;
  final String tooltip;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: tooltip,
      child: InkWell(
        onTap: onPressed,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          width: 40,
          height: 40,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: context.hs.border),
            color: context.hs.card,
          ),
          child: Icon(icon, size: 18, color: context.hs.textMuted),
        ),
      ),
    );
  }
}

class _UserChip extends StatelessWidget {
  const _UserChip({required this.name, required this.workspace, required this.onPressed, this.compact = false});

  final String name;
  final String workspace;
  final VoidCallback onPressed;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onPressed,
      borderRadius: BorderRadius.circular(999),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: context.hs.border),
        ),
        child: Row(
          children: [
            CircleAvatar(
              radius: 14,
              backgroundColor: context.hs.brandSoft,
              child: Text(
                name.isEmpty ? 'ح' : name.substring(0, 1),
                style: TextStyle(fontWeight: FontWeight.w800, color: context.hs.brandAccent, fontSize: 12),
              ),
            ),
            if (!compact) ...[
              const SizedBox(width: 8),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12, height: 1.1)),
                  Text(workspace, style: TextStyle(fontSize: 10, color: context.hs.textMuted, height: 1.1)),
                ],
              ),
              const SizedBox(width: 4),
            ],
          ],
        ),
      ),
    );
  }
}
