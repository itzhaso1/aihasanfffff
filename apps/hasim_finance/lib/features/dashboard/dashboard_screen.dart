import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/layout/finance_charts.dart';
import 'package:hasim_finance/core/layout/finance_layout.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/providers/catalog_provider.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';
import 'package:hasim_finance/core/widgets/date_field.dart';
import 'package:hasim_finance/core/widgets/widgets.dart';
import 'package:hasim_finance/features/shared/customer_select.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';
import 'package:intl/intl.dart';

class DashboardScreen extends ConsumerStatefulWidget {
  const DashboardScreen({super.key});

  @override
  ConsumerState<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends ConsumerState<DashboardScreen> {
  DashboardData? _data;
  bool _loading = true;
  String? _error;
  final _from = TextEditingController();
  final _to = TextEditingController();
  int? _customerId;
  int? _productId;
  int? _projectId;
  String? _lifecycle;
  String? _paymentMethod;

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _from.text = isoDate(DateTime(now.year, now.month, 1));
    _to.text = isoDate(now);
    _load();
  }

  @override
  void dispose() {
    _from.dispose();
    _to.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ref.read(financeApiProvider).dashboard(
            from: _from.text.trim(),
            to: _to.text.trim(),
            customerId: _customerId,
            productId: _productId,
            projectId: _projectId,
            lifecycle: _lifecycle,
            paymentMethod: _paymentMethod,
          );
      if (!mounted) return;
      setState(() {
        _data = data;
        _loading = false;
        final analyticsFrom = data.analytics['from']?.toString();
        final analyticsTo = data.analytics['to']?.toString();
        if (analyticsFrom != null && analyticsFrom.isNotEmpty) _from.text = analyticsFrom;
        if (analyticsTo != null && analyticsTo.isNotEmpty) _to.text = analyticsTo;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  void _resetFilters() {
    final now = DateTime.now();
    setState(() {
      _from.text = isoDate(DateTime(now.year, now.month, 1));
      _to.text = isoDate(now);
      _customerId = null;
      _productId = null;
      _projectId = null;
      _lifecycle = null;
      _paymentMethod = null;
    });
    _load();
  }

  String _card(String key, [String fallback = '0.00']) => _data?.cards[key] ?? fallback;

  @override
  Widget build(BuildContext context) {
    ref.listen(authControllerProvider.select((s) => s.workspace?.id), (prev, next) {
      if (prev != next) _load();
    });
    final l = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    return PermissionGate(
      allowed: auth.permissions.financeView,
      child: FinanceScaffold(
        title: l.dashboard,
        subtitle: l.dashboardSubtitle,
        primaryAction: FilledButton.icon(
          onPressed: () => context.go('/exports'),
          icon: const Icon(Icons.ios_share_rounded, size: 15),
          label: Text(l.exportReport),
          style: FilledButton.styleFrom(
            minimumSize: const Size(0, 32),
            maximumSize: const Size(double.infinity, 32),
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 0),
            visualDensity: VisualDensity.compact,
            tapTargetSize: MaterialTapTargetSize.shrinkWrap,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          ),
        ),
        actions: [
          IconButton(
            onPressed: _load,
            tooltip: l.refresh,
            style: IconButton.styleFrom(
              minimumSize: const Size(32, 32),
              maximumSize: const Size(32, 32),
              padding: EdgeInsets.zero,
              visualDensity: VisualDensity.compact,
              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(8),
                side: BorderSide(color: context.hs.border),
              ),
            ),
            icon: const Icon(Icons.refresh_rounded, size: 18),
          ),
        ],
        body: AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: _data == null
              ? const SizedBox.shrink()
              : FinancePage(
                  child: ListView(
                    padding: EdgeInsets.zero,
                    children: [
                      FinanceFilterBar(
                        margin: const EdgeInsets.only(bottom: 12),
                        trailing: Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            FilledButton.icon(
                              onPressed: _load,
                              icon: const Icon(Icons.filter_alt_rounded, size: 16),
                              label: Text(l.applyFilters),
                            ),
                            OutlinedButton(onPressed: _resetFilters, child: Text(l.resetFilters)),
                          ],
                        ),
                        children: [
                          FinanceFilterField(
                            label: l.from,
                            width: 160,
                            child: DateField(controller: _from, compact: true, allowClear: false),
                          ),
                          FinanceFilterField(
                            label: l.to,
                            width: 160,
                            child: DateField(controller: _to, compact: true, allowClear: false),
                          ),
                          FinanceFilterField(
                            label: l.selectCustomer,
                            width: 220,
                            child: CustomerSelectField(
                              selectedId: _customerId,
                              onSelected: (id) => setState(() => _customerId = id),
                              compact: true,
                              allowClear: true,
                              floatingLabel: false,
                            ),
                          ),
                          FinanceFilterField(
                            label: l.products,
                            width: 180,
                            child: OptionPicker(
                              label: l.products,
                              floatingLabel: false,
                              options: [
                                for (final product in ref.watch(financeCatalogProvider).valueOrNull?.products ?? const <CatalogOption>[])
                                  NamedOption(id: product.id, name: product.name),
                              ],
                              value: _productId,
                              onChanged: (id) => setState(() => _productId = id),
                            ),
                          ),
                          FinanceFilterField(
                            label: l.projects,
                            width: 180,
                            child: OptionPicker(
                              label: l.projects,
                              floatingLabel: false,
                              options: [
                                for (final project in ref.watch(financeCatalogProvider).valueOrNull?.projects ?? const <CatalogOption>[])
                                  NamedOption(id: project.id, name: project.name),
                              ],
                              value: _projectId,
                              onChanged: (id) => setState(() => _projectId = id),
                            ),
                          ),
                          FinanceFilterField(
                            label: l.status,
                            width: 180,
                            child: DropdownButtonFormField<String?>(
                              // ignore: deprecated_member_use
                              value: _lifecycle,
                              isExpanded: true,
                              isDense: true,
                              decoration: FinanceFilterField.decoration(),
                              items: [
                                DropdownMenuItem<String?>(value: null, child: Text(l.filterAll)),
                                DropdownMenuItem(value: 'draft', child: Text(l.lifecycleDraft)),
                                DropdownMenuItem(value: 'sent', child: Text(l.lifecycleSent)),
                              ],
                              onChanged: (value) => setState(() => _lifecycle = value),
                            ),
                          ),
                          FinanceFilterField(
                            label: l.paymentMethod,
                            width: 200,
                            child: DropdownButtonFormField<String?>(
                              // ignore: deprecated_member_use
                              value: _paymentMethod,
                              isExpanded: true,
                              isDense: true,
                              decoration: FinanceFilterField.decoration(),
                              items: [
                                DropdownMenuItem<String?>(value: null, child: Text(l.filterAll)),
                                DropdownMenuItem(value: 'cash', child: Text(l.methodCash)),
                                DropdownMenuItem(value: 'bank_transfer', child: Text(l.methodBank)),
                                DropdownMenuItem(value: 'card', child: Text(l.methodCard)),
                                DropdownMenuItem(value: 'other', child: Text(l.methodOther)),
                              ],
                              onChanged: (value) => setState(() => _paymentMethod = value),
                            ),
                          ),
                        ],
                      ),
                      _heroRow(l),
                      const SizedBox(height: 12),
                      _secondaryRow(l),
                      const SizedBox(height: 12),
                      _tertiaryRow(l),
                      if (auth.permissions.payrollView) ...[
                        const SizedBox(height: 12),
                        _payrollRow(l),
                      ],
                      const SizedBox(height: 12),
                      _bottomRow(context, l),
                      if ((_data!.analytics['attention'] as List?)?.isNotEmpty == true) ...[
                        const SizedBox(height: 12),
                        FinanceSurface(
                          title: l.attentionItems,
                          child: Column(
                            children: [
                              for (final row in (_data!.analytics['attention'] as List).whereType<Map>())
                                ListTile(
                                  contentPadding: EdgeInsets.zero,
                                  title: Text('${row['title'] ?? ''}'),
                                  subtitle: Text('${row['reason'] ?? ''}'),
                                ),
                            ],
                          ),
                        ),
                      ],
                      if (_data!.recentExpenses.isNotEmpty) ...[
                        const SizedBox(height: 12),
                        FinanceSurface(
                          title: l.recentExpenses,
                          child: Column(
                            children: [
                              for (final expense in _data!.recentExpenses)
                                ListTile(
                                  contentPadding: EdgeInsets.zero,
                                  title: Text(expense.description ?? l.expenses),
                                  trailing: Text(expense.total, style: const TextStyle(fontWeight: FontWeight.w800)),
                                ),
                            ],
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
        ),
      ),
    );
  }

  Widget _heroRow(AppLocalizations l) {
    final hero = (_data!.analytics['hero'] as List?)?.whereType<Map>().toList() ?? const [];
    if (hero.isNotEmpty) {
      final tones = [FinanceIconTone.teal, FinanceIconTone.rose, FinanceIconTone.orange, FinanceIconTone.indigo];
      final icons = [Icons.bar_chart_rounded, Icons.person_outline_rounded, Icons.credit_card_rounded, Icons.account_balance_wallet_outlined];
      return KpiGrid(
        minWidth: 260,
        cards: [
          for (var i = 0; i < hero.length; i++)
            KpiCard(
              label: '${hero[i]['label'] ?? ''}',
              value: '${hero[i]['value'] ?? '0'}',
              hint: '${hero[i]['hint'] ?? ''}',
              delta: hero[i]['delta']?.toString(),
              direction: int.tryParse('${hero[i]['direction'] ?? 0}') ?? 0,
              icon: icons[i % icons.length],
              tone: tones[i % tones.length],
            ),
        ],
      );
    }
    return KpiGrid(
      minWidth: 260,
      cards: [
        KpiCard(label: l.sales, value: _card('sales'), icon: Icons.bar_chart_rounded, tone: FinanceIconTone.teal, hint: l.paidThisPeriod),
        KpiCard(label: l.receivables, value: _card('outstanding_customer_balance'), icon: Icons.person_outline_rounded, tone: FinanceIconTone.rose),
        KpiCard(label: l.payables, value: _card('payables'), icon: Icons.credit_card_rounded, tone: FinanceIconTone.orange),
      ],
    );
  }

  Widget _secondaryRow(AppLocalizations l) {
    return KpiGrid(
      minWidth: 200,
      cards: [
        KpiCard(label: l.receivables, value: _card('receivables'), icon: Icons.savings_outlined, tone: FinanceIconTone.amber, compact: true),
        KpiCard(label: l.sales, value: _card('sales'), icon: Icons.shopping_cart_outlined, tone: FinanceIconTone.orange, compact: true),
        KpiCard(label: l.purchases, value: _card('purchases'), icon: Icons.shopping_bag_outlined, tone: FinanceIconTone.indigo, compact: true),
        KpiCard(label: l.expenses, value: _card('expenses'), icon: Icons.receipt_long_outlined, tone: FinanceIconTone.green, compact: true),
      ],
    );
  }

  Widget _tertiaryRow(AppLocalizations l) {
    return KpiGrid(
      minWidth: 170,
      cards: [
        KpiCard(label: l.netProfit, value: _card('net_profit'), icon: Icons.trending_up_rounded, tone: FinanceIconTone.teal, compact: true),
        KpiCard(label: l.tax, value: _card('output_vat'), icon: Icons.percent_rounded, tone: FinanceIconTone.indigo, compact: true),
        KpiCard(label: l.invoicesDue, value: _card('invoices_due'), icon: Icons.description_outlined, tone: FinanceIconTone.blue, compact: true, showCurrency: false),
        KpiCard(label: l.overdueInvoices, value: _card('overdue_invoices'), icon: Icons.error_outline_rounded, tone: FinanceIconTone.red, compact: true, showCurrency: false),
        KpiCard(label: l.cashBalance, value: _card('cash_balance'), icon: Icons.account_balance_wallet_outlined, tone: FinanceIconTone.green, compact: true),
      ],
    );
  }

  Widget _payrollRow(AppLocalizations l) {
    return KpiGrid(
      minWidth: 180,
      cards: [
        KpiCard(label: l.companyEmployees, value: _card('company_employees', '0'), icon: Icons.badge_outlined, tone: FinanceIconTone.blue, compact: true, showCurrency: false),
        KpiCard(label: l.payrollPaid, value: _card('payroll_paid_total'), icon: Icons.payments_outlined, tone: FinanceIconTone.teal, compact: true),
        KpiCard(label: l.openAdvances, value: _card('open_advances_total'), icon: Icons.front_hand_outlined, tone: FinanceIconTone.orange, compact: true),
        KpiCard(label: l.deductions, value: _card('deductions_total'), icon: Icons.remove_circle_outline, tone: FinanceIconTone.red, compact: true),
      ],
    );
  }

  Widget _bottomRow(BuildContext context, AppLocalizations l) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final stacked = constraints.maxWidth < 1100;
        final chart = FinanceSurface(
          title: l.salesVsExpenses,
          trailing: Text(l.lastSixMonths, style: TextStyle(fontSize: 12, color: context.hs.textMuted, fontWeight: FontWeight.w700)),
          child: FinanceBarChart(series: _series(), salesLabel: l.sales, expensesLabel: l.expenses),
        );
        final table = FinanceSurface(
          title: l.recentInvoices,
          trailing: Text(l.thisMonth, style: TextStyle(fontSize: 12, color: context.hs.textMuted, fontWeight: FontWeight.w700)),
          padding: EdgeInsets.zero,
          child: _invoicesTable(l),
        );
        final donut = FinanceSurface(
          title: l.salesMix,
          child: FinanceDonutChart(
            slices: _slices(l),
            centerValue: _card('sales'),
            centerLabel: l.soldTotal,
          ),
        );
        if (stacked) {
          return Column(children: [chart, const SizedBox(height: 12), table, const SizedBox(height: 12), donut]);
        }
        return Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(flex: 5, child: chart),
            const SizedBox(width: 12),
            Expanded(flex: 5, child: table),
            const SizedBox(width: 12),
            Expanded(flex: 4, child: donut),
          ],
        );
      },
    );
  }

  List<({String label, double sales, double expenses})> _series() {
    final raw = (_data!.analytics['series'] as List?)?.whereType<Map>().toList() ?? const [];
    return [
      for (final row in raw.take(6))
        (
          label: _monthLabel('${row['month'] ?? ''}'),
          sales: _num('${row['sales'] ?? 0}'),
          expenses: _num('${row['expenses'] ?? 0}'),
        ),
    ];
  }

  List<({String label, double value, Color color})> _slices(AppLocalizations l) {
    final products = (_data!.analytics['products'] as List?)?.whereType<Map>().toList() ?? const [];
    final categories = (_data!.analytics['expenses_by_category'] as List?)?.whereType<Map>().toList() ?? const [];
    final source = products.isNotEmpty ? products : categories;
    const colors = [FinanceTokens.brand, Color(0xFF34D399), Color(0xFF60A5FA), Color(0xFFFBBF24), Color(0xFFF472B6)];
    if (source.isEmpty) {
      return [
        (label: l.sales, value: _num(_card('sales')), color: colors[0]),
        (label: l.expenses, value: _num(_card('expenses')), color: colors[1]),
        (label: l.purchases, value: _num(_card('purchases')), color: colors[2]),
      ].where((row) => row.value > 0).toList();
    }
    return [
      for (var i = 0; i < source.length && i < 5; i++)
        (label: '${source[i]['name'] ?? ''}', value: _num('${source[i]['total'] ?? source[i]['quantity'] ?? 0}'), color: colors[i % colors.length]),
    ];
  }

  Widget _invoicesTable(AppLocalizations l) {
    final seen = <int>{};
    final invoices = [
      ..._data!.overdueInvoices,
      ..._data!.recentInvoices,
    ].where((row) => seen.add(row.id)).toList();
    if (invoices.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(l.empty, textAlign: TextAlign.center, style: TextStyle(color: context.hs.textMuted)),
      );
    }
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: DataTable(
        columns: [
          DataColumn(label: Text(l.invoiceNumber)),
          DataColumn(label: Text(l.date)),
          DataColumn(label: Text(l.customer)),
          DataColumn(label: Text(l.amount)),
          DataColumn(label: Text(l.status)),
        ],
        rows: [
          for (final invoice in invoices.take(6))
            DataRow(
              cells: [
                DataCell(Text(invoice.invoiceNumber ?? '#${invoice.id}', style: const TextStyle(fontWeight: FontWeight.w800))),
                DataCell(Text(invoice.issueDate ?? '')),
                DataCell(Text(invoice.customerName ?? '')),
                DataCell(Text(invoice.total, style: const TextStyle(fontWeight: FontWeight.w800))),
                DataCell(StatusChip(label: _payLabel(invoice.paymentStatus, l), tone: toneFor(invoice.paymentStatus))),
              ],
            ),
        ],
      ),
    );
  }

  String _payLabel(String? status, AppLocalizations l) {
    return switch (status) {
      'paid' => l.paid,
      'unpaid' => l.unpaid,
      'partial' => l.partial,
      'overdue' => l.overdue,
      'cancelled' => l.cancelled,
      _ => status ?? '',
    };
  }

  String _monthLabel(String month) {
    final date = DateTime.tryParse(month.length == 7 ? '$month-01' : month);
    if (date == null) return month;
    return DateFormat.MMMM(Localizations.localeOf(context).toString()).format(date);
  }

  double _num(String raw) => double.tryParse(raw.replaceAll(',', '').replaceAll(' ', '')) ?? 0;
}

class FinanceSearchScreen extends ConsumerStatefulWidget {
  const FinanceSearchScreen({super.key});

  @override
  ConsumerState<FinanceSearchScreen> createState() => _FinanceSearchScreenState();
}

class _FinanceSearchScreenState extends ConsumerState<FinanceSearchScreen> {
  final _q = TextEditingController();
  Timer? _debounce;
  Map<String, dynamic>? _data;
  String? _error;
  bool _loading = false;

  @override
  void dispose() {
    _debounce?.cancel();
    _q.dispose();
    super.dispose();
  }

  Future<void> _search(String value) async {
    if (value.trim().length < 2) {
      setState(() {
        _data = null;
        _error = null;
      });
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ref.read(financeApiProvider).search(value.trim());
      if (!mounted) return;
      setState(() {
        _data = data;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return FinanceScaffold(
      title: l.globalSearch,
      body: Column(
        children: [
          FinanceFilterBar(
            children: [
              SizedBox(
                width: 420,
                child: TextField(
                  controller: _q,
                  autofocus: true,
                  decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: l.searchPlaceholder),
                  onChanged: (value) {
                    _debounce?.cancel();
                    _debounce = Timer(const Duration(milliseconds: 350), () => _search(value));
                  },
                ),
              ),
            ],
          ),
          if (_loading) const LinearProgressIndicator(),
          if (_error != null) Padding(padding: const EdgeInsets.all(16), child: Text(_error!)),
          Expanded(
            child: _data == null
                ? EmptyState(title: l.globalSearch, subtitle: l.searchPlaceholder)
                : ListView(
                    padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
                    children: [
                      for (final entry in _data!.entries)
                        if (entry.value is List && (entry.value as List).isNotEmpty) ...[
                          Padding(
                            padding: const EdgeInsets.only(bottom: 8, top: 8),
                            child: Text(entry.key, style: const TextStyle(fontWeight: FontWeight.w800)),
                          ),
                          for (final row in (entry.value as List).whereType<Map>())
                            Padding(
                              padding: const EdgeInsets.only(bottom: 8),
                              child: Material(
                              color: context.hs.card,
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(FinanceTokens.radiusLg),
                                side: BorderSide(color: context.hs.border),
                              ),
                              child: ListTile(
                                title: Text('${row['title'] ?? ''}'),
                                subtitle: Text('${row['subtitle'] ?? ''}'),
                                onTap: () {
                                  final id = row['id'];
                                  final type = row['type']?.toString();
                                  if (id == null || type == null) return;
                                  final path = switch (type) {
                                    'customer' => '/customers/$id',
                                    'invoice' => '/invoices/$id',
                                    'quote' => '/quotes/$id',
                                    'receipt' => '/receipts/$id',
                                    'payment' => '/payments/$id',
                                    'expense' => '/expenses/$id',
                                    'contract' => '/contracts/$id',
                                    'purchase' => '/purchases/$id',
                                    'supplier' => '/suppliers/$id',
                                    'product' => '/products/$id',
                                    'project' => '/projects/$id',
                                    'purchase_order' => '/purchase-orders/$id',
                                    'lead' => '/leads/$id',
                                    _ => null,
                                  };
                                  if (path != null) context.push(path);
                                },
                              ),
                              ),
                            ),
                        ],
                    ],
                  ),
          ),
        ],
      ),
    );
  }
}
