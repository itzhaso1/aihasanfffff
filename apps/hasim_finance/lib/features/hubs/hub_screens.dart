import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/layout/finance_layout.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/utils/files.dart';
import 'package:hasim_finance/core/widgets/date_field.dart';
import 'package:hasim_finance/core/widgets/widgets.dart';
import 'package:hasim_finance/features/shared/paged.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class SalesHubScreen extends ConsumerStatefulWidget {
  const SalesHubScreen({super.key});
  @override
  ConsumerState<SalesHubScreen> createState() => _SalesHubScreenState();
}

class _SalesHubScreenState extends ConsumerState<SalesHubScreen> {
  Map<String, dynamic>? _data;
  bool _loading = true;
  String? _error;
  final _from = TextEditingController();
  final _to = TextEditingController();

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
      final data = await ref.read(financeApiProvider).salesHub(from: _from.text.trim(), to: _to.text.trim());
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
    final summary = Map<String, dynamic>.from(_data?['summary'] as Map? ?? {});
    final invoices = (_data?['invoices'] as List? ?? []).whereType<Map>();
    final payments = (_data?['recent_payments'] as List? ?? []).whereType<Map>();
    return PermissionGate(
      allowed: ref.watch(authControllerProvider).permissions.financeView,
      child: FinanceScaffold(
        title: l.salesHub,
        showBack: true,
        actions: [IconButton(onPressed: _load, tooltip: l.refresh, icon: const Icon(Icons.refresh))],
        body: AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              FormSection(
                title: l.decisionPeriod,
                child: FormGrid(children: [
                  DateField(controller: _from, label: l.from),
                  DateField(controller: _to, label: l.to),
                  Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: FilledButton(onPressed: _load, child: Text(l.applyFilters)),
                  ),
                ]),
              ),
              MetricGrid(metrics: [
                (l.sales, '${summary['total_sales'] ?? '0.00'}'),
                (l.paid, '${summary['total_paid'] ?? '0.00'}'),
                (l.due, '${summary['total_due'] ?? '0.00'}'),
                (l.overdueInvoices, '${summary['overdue_count'] ?? 0}'),
              ]),
              const SizedBox(height: 16),
              Text(l.recentInvoices, style: Theme.of(context).textTheme.titleMedium),
              for (final row in invoices)
                ListTile(
                  title: Text('${row['invoice_number'] ?? row['id']}'),
                  subtitle: Text('${row['customer_name'] ?? ''} · ${row['payment_status'] ?? ''}'),
                  trailing: Text('${row['total'] ?? ''}'),
                  onTap: () => context.push('/invoices/${row['id']}'),
                ),
              Text(l.recentPayments, style: Theme.of(context).textTheme.titleMedium),
              for (final row in payments)
                ListTile(
                  title: Text('${row['amount'] ?? ''}'),
                  subtitle: Text('${row['customer_name'] ?? ''} · ${row['method'] ?? ''}'),
                  onTap: row['id'] == null ? null : () => context.push('/payments/${row['id']}'),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class BillingHubScreen extends ConsumerStatefulWidget {
  const BillingHubScreen({super.key});
  @override
  ConsumerState<BillingHubScreen> createState() => _MapHubState();
}

class VatHubScreen extends ConsumerStatefulWidget {
  const VatHubScreen({super.key});
  @override
  ConsumerState<VatHubScreen> createState() => _VatHubScreenState();
}

class _VatHubScreenState extends ConsumerState<VatHubScreen> {
  Map<String, dynamic>? _data;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await ref.read(financeApiProvider).vatHub();
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
    final rates = (_data?['rates'] as List? ?? []).whereType<Map>();
    return PermissionGate(
      allowed: ref.watch(authControllerProvider).permissions.accountingView ||
          ref.watch(authControllerProvider).permissions.financeView,
      child: FinanceScaffold(
        title: l.vatPage,
        showBack: true,
        actions: [IconButton(onPressed: _load, tooltip: l.refresh, icon: const Icon(Icons.refresh))],
        body: AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              MetricGrid(metrics: [
                (l.outputVat, '${_data?['output'] ?? '0.00'}'),
                (l.inputVat, '${_data?['input'] ?? '0.00'}'),
                (l.netVat, '${_data?['net'] ?? '0.00'}'),
              ]),
              const SizedBox(height: 16),
              Text(l.taxRate, style: Theme.of(context).textTheme.titleMedium),
              for (final rate in rates)
                ListTile(
                  title: Text('${rate['name'] ?? ''}'),
                  subtitle: Text('${rate['code'] ?? ''} · ${rate['type'] ?? ''}'),
                  trailing: Text('${rate['rate'] ?? ''}'),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _MapHubState extends ConsumerState<BillingHubScreen> {
  Map<String, dynamic>? _data;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await ref.read(financeApiProvider).billingHub();
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
    final data = _data ?? {};
    return PermissionGate(
      allowed: ref.watch(authControllerProvider).permissions.invoicesView,
      child: FinanceScaffold(
        title: l.billingHub,
        showBack: true,
        actions: [IconButton(onPressed: _load, tooltip: l.refresh, icon: const Icon(Icons.refresh))],
        body: AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              MetricGrid(metrics: [
                (l.invoices, '${data['total_invoices'] ?? 0}'),
                (l.revenue, '${data['total_revenue'] ?? '0.00'}'),
                (l.outstanding, '${data['outstanding_amount'] ?? '0.00'}'),
                (l.overdue, '${data['overdue_amount'] ?? '0.00'}'),
                (l.paid, '${data['payments_received'] ?? '0.00'}'),
                (l.credit, '${data['credits_issued'] ?? '0.00'}'),
              ]),
              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final key in ['draft', 'issued', 'paid', 'partial', 'overdue', 'unpaid', 'cancelled', 'due_today', 'upcoming_due'])
                    Chip(label: Text('$key: ${data[key] ?? 0}')),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class AccountingHubScreen extends ConsumerStatefulWidget {
  const AccountingHubScreen({super.key});
  @override
  ConsumerState<AccountingHubScreen> createState() => _AccountingHubScreenState();
}

class _AccountingHubScreenState extends ConsumerState<AccountingHubScreen> {
  Map<String, dynamic>? _data;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await ref.read(financeApiProvider).accountingHub();
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
    final accounts = (_data?['accounts'] as List? ?? []).whereType<Map>();
    final trial = (_data?['trial_balance'] as List? ?? []).whereType<Map>();
    final entries = (_data?['entries'] as List? ?? []).whereType<Map>();
    final totals = Map<String, dynamic>.from(_data?['trial_totals'] as Map? ?? {});
    return PermissionGate(
      allowed: ref.watch(authControllerProvider).permissions.accountingView ||
          ref.watch(authControllerProvider).permissions.financeView,
      child: FinanceScaffold(
        title: l.accountingHub,
        showBack: true,
        actions: [IconButton(onPressed: _load, tooltip: l.refresh, icon: const Icon(Icons.refresh))],
        body: AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: FinancePage(
            child: ListView(
            padding: EdgeInsets.zero,
            children: [
              MetricGrid(metrics: [
                (l.statementDebit, '${totals['debit'] ?? '0.00'}'),
                (l.statementCredit, '${totals['credit'] ?? '0.00'}'),
              ]),
              const SizedBox(height: 12),
              Text(l.trialBalance, style: Theme.of(context).textTheme.titleMedium),
              for (final row in trial.take(40))
                ListTile(
                  dense: true,
                  title: Text('${row['code'] ?? ''} · ${row['name'] ?? ''}'),
                  subtitle: Text('${row['type'] ?? ''}'),
                  trailing: Text('${row['debit_total'] ?? '0.00'} / ${row['credit_total'] ?? '0.00'}'),
                ),
              Text(l.generalLedger, style: Theme.of(context).textTheme.titleMedium),
              for (final row in accounts.take(30))
                ListTile(
                  dense: true,
                  title: Text('${row['code'] ?? ''} · ${row['name'] ?? ''}'),
                  trailing: Text('${row['balance'] ?? row['debit_total'] ?? ''}'),
                ),
              Text(l.journalEntries, style: Theme.of(context).textTheme.titleMedium),
              for (final row in entries.take(20))
                ListTile(
                  dense: true,
                  isThreeLine: true,
                  title: Text('${row['entry_number'] ?? row['id'] ?? ''}'),
                  subtitle: Text(
                    '${row['entry_date'] ?? ''} · ${row['status'] ?? ''}\n${row['description'] ?? ''}',
                  ),
                ),
              Text(l.monthlyCashFlow, style: Theme.of(context).textTheme.titleMedium),
              for (final row in (_data?['monthly_cash_flow'] as List? ?? []).whereType<Map>())
                ListTile(
                  dense: true,
                  title: Text('${row['month'] ?? ''}'),
                  trailing: Text('${row['inflow'] ?? '0.00'}'),
                ),
            ],
          ),
          ),
        ),
      ),
    );
  }
}

class AlertsScreen extends ConsumerStatefulWidget {
  const AlertsScreen({super.key});
  @override
  ConsumerState<AlertsScreen> createState() => _AlertsScreenState();
}

class _AlertsScreenState extends ConsumerState<AlertsScreen> {
  List<Map<String, dynamic>> _items = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final items = await ref.read(financeApiProvider).alerts();
      if (!mounted) return;
      setState(() {
        _items = items;
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
    return PermissionGate(
      allowed: ref.watch(authControllerProvider).permissions.financeView,
      child: FinanceScaffold(
        title: l.alerts,
        showBack: true,
        actions: [IconButton(onPressed: _load, tooltip: l.refresh, icon: const Icon(Icons.refresh))],
        body: AsyncBody(
          loading: _loading,
          error: _error,
          isEmpty: _items.isEmpty,
          emptyTitle: l.empty,
          onRetry: _load,
          child: ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: _items.length,
            separatorBuilder: (_, _) => const SizedBox(height: 8),
            itemBuilder: (context, i) {
              final row = _items[i];
              return Card(
                child: ListTile(
                  title: Text('${row['title'] ?? row['key'] ?? ''}'),
                  subtitle: Text('${row['reason'] ?? row['action'] ?? ''}'),
                  trailing: StatusChip(label: '${row['severity'] ?? ''}'),
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}

class CopilotScreen extends ConsumerStatefulWidget {
  const CopilotScreen({super.key});
  @override
  ConsumerState<CopilotScreen> createState() => _CopilotScreenState();
}

class _CopilotScreenState extends ConsumerState<CopilotScreen> {
  final _question = TextEditingController();
  Map<String, dynamic>? _answer;
  bool _busy = false;

  @override
  void dispose() {
    _question.dispose();
    super.dispose();
  }

  Future<void> _ask() async {
    if (_question.text.trim().isEmpty) return;
    setState(() => _busy = true);
    try {
      final answer = await ref.read(financeApiProvider).askCopilot(_question.text.trim());
      if (!mounted) return;
      setState(() => _answer = answer);
    } catch (e) {
      if (mounted) showApiError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return PermissionGate(
      allowed: ref.watch(authControllerProvider).permissions.financeView,
      child: FinanceScaffold(
        title: l.copilot,
        showBack: true,
        body: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(l.copilotHint),
            const SizedBox(height: 12),
            TextField(controller: _question, maxLines: 3, decoration: InputDecoration(labelText: l.askQuestion)),
            const SizedBox(height: 8),
            FilledButton(onPressed: _busy ? null : _ask, child: Text(l.askCopilot)),
            if (_answer != null) ...[
              const SizedBox(height: 16),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text('${_answer!['answer'] ?? ''}'),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class BanksScreen extends ConsumerWidget {
  const BanksScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    return PagedListScreen<TreasuryAccountRecord>(
      title: l.banks,
      allowed: ref.watch(authControllerProvider).permissions.financeView,
      loader: (api, search, page) => api.banks(page: page),
      itemBuilder: (context, account) => Card(
        child: ListTile(
          title: Text(account.name),
          subtitle: Text('${account.type ?? ''} · ${account.bankName ?? ''} · ${account.iban ?? ''}'),
          trailing: Text(account.currentBalance),
        ),
      ),
    );
  }
}

class TreasuryScreen extends ConsumerStatefulWidget {
  const TreasuryScreen({super.key});
  @override
  ConsumerState<TreasuryScreen> createState() => _TreasuryScreenState();
}

class _TreasuryScreenState extends ConsumerState<TreasuryScreen> {
  Map<String, dynamic>? _data;
  bool _loading = true;
  String? _error;
  int? _from;
  int? _to;
  final _amount = TextEditingController();
  final _date = TextEditingController(text: isoDate());
  final _reference = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _amount.dispose();
    _date.dispose();
    _reference.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final data = await ref.read(financeApiProvider).treasury();
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
    final accounts = (_data?['accounts'] as List? ?? [])
        .whereType<Map>()
        .map((row) => TreasuryAccountRecord.fromJson(Map<String, dynamic>.from(row)))
        .toList();
    final transfers = (_data?['transfers'] as List? ?? [])
        .whereType<Map>()
        .map((row) => TreasuryTransferRecord.fromJson(Map<String, dynamic>.from(row)))
        .toList();
    return PermissionGate(
      allowed: ref.watch(authControllerProvider).permissions.financeView,
      child: FinanceScaffold(
        title: l.treasury,
        showBack: true,
        actions: [IconButton(onPressed: _load, tooltip: l.refresh, icon: const Icon(Icons.refresh))],
        body: AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              for (final account in accounts)
                ListTile(
                  title: Text(account.name),
                  subtitle: Text('${account.type ?? ''} · ${account.iban ?? ''}'),
                  trailing: Text(account.currentBalance),
                ),
              if (ref.watch(authControllerProvider).permissions.accountingManage) ...[
                FormSection(
                  title: l.transfer,
                  child: FormGrid(children: [
                    OptionPicker(
                      label: l.fromAccount,
                      options: [for (final a in accounts) NamedOption(id: a.id, name: a.name)],
                      value: _from,
                      onChanged: (id) => setState(() => _from = id),
                    ),
                    OptionPicker(
                      label: l.toAccount,
                      options: [for (final a in accounts) NamedOption(id: a.id, name: a.name)],
                      value: _to,
                      onChanged: (id) => setState(() => _to = id),
                    ),
                    TextField(controller: _amount, decoration: InputDecoration(labelText: l.amount)),
                    TextField(controller: _date, decoration: InputDecoration(labelText: l.date)),
                    TextField(controller: _reference, decoration: InputDecoration(labelText: l.reference)),
                  ]),
                ),
                FilledButton(
                  onPressed: _from == null || _to == null
                      ? null
                      : () async {
                          try {
                            await ref.read(financeApiProvider).treasuryTransfer({
                              'from_treasury_account_id': _from,
                              'to_treasury_account_id': _to,
                              'amount': _amount.text.trim(),
                              'transfer_date': _date.text.trim(),
                              'reference': _reference.text.trim(),
                            });
                            await _load();
                            if (context.mounted) showSnack(context, l.success);
                          } catch (e) {
                            if (context.mounted) showFormError(context, e);
                          }
                        },
                  child: Text(l.transfer),
                ),
              ],
              const SizedBox(height: 12),
              for (final transfer in transfers)
                ListTile(
                  title: Text('${transfer.fromAccountName} → ${transfer.toAccountName}'),
                  subtitle: Text('${transfer.transferDate ?? ''} · ${transfer.reference ?? ''}'),
                  trailing: Text(transfer.amount),
                ),
              const SizedBox(height: 12),
              Text(l.bankStatements, style: Theme.of(context).textTheme.titleMedium),
              if (ref.watch(authControllerProvider).permissions.accountingManage)
                FormSection(
                  title: l.addStatement,
                  child: _CreateStatementForm(accounts: accounts, onCreated: (id) {
                    if (context.mounted) context.push('/treasury/statements/$id');
                  }),
                ),
              for (final row in (_data?['statements'] as List? ?? []).whereType<Map>())
                ListTile(
                  title: Text('${row['treasury_account_name'] ?? l.bankStatements}'),
                  subtitle: Text('${row['statement_date'] ?? ''} · ${row['status'] ?? ''}'),
                  trailing: Text('${row['closing_balance'] ?? ''}'),
                  onTap: row['id'] == null ? null : () => context.push('/treasury/statements/${row['id']}'),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class ExportsScreen extends ConsumerStatefulWidget {
  const ExportsScreen({super.key});
  @override
  ConsumerState<ExportsScreen> createState() => _ExportsScreenState();
}

class _ExportsScreenState extends ConsumerState<ExportsScreen> {
  List<Map<String, dynamic>> _items = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final items = await ref.read(financeApiProvider).exportIndex();
      if (!mounted) return;
      setState(() {
        _items = items;
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
    final perms = ref.watch(authControllerProvider).permissions;
    return PermissionGate(
      allowed: perms.financeView,
      child: FinanceScaffold(
        title: l.exports,
        showBack: true,
        body: AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              for (final row in _items)
                Card(
                  child: ListTile(
                    title: Text('${row['label'] ?? row['dataset']}'),
                    subtitle: Text('${row['hint'] ?? ''}'),
                    trailing: FilledButton.tonal(
                      onPressed: () async {
                        try {
                          final dataset = '${row['dataset']}';
                          final bytes = await ref.read(financeApiProvider).exportDataset(dataset);
                          await saveAndOpenBytes(bytes, '$dataset.csv');
                          if (context.mounted) showSnack(context, l.success);
                        } catch (e) {
                          if (context.mounted) showApiError(context, e);
                        }
                      },
                      child: Text(l.csv),
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

class FiscalYearsScreen extends ConsumerStatefulWidget {
  const FiscalYearsScreen({super.key});

  @override
  ConsumerState<FiscalYearsScreen> createState() => _FiscalYearsScreenState();
}

class _FiscalYearsScreenState extends ConsumerState<FiscalYearsScreen> {
  int _tick = 0;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final p = ref.watch(authControllerProvider).permissions;
    return PagedListScreen<FiscalYearRecord>(
      key: ValueKey(_tick),
      title: l.fiscalYears,
      allowed: p.fiscalYearsView || p.financeView,
      onCreate: p.fiscalYearsManage
          ? () async {
              final name = TextEditingController(text: DateTime.now().year.toString());
              final start = TextEditingController(text: '${DateTime.now().year}-01-01');
              final end = TextEditingController(text: '${DateTime.now().year}-12-31');
              final ok = await showDialog<bool>(
                context: context,
                builder: (ctx) => AlertDialog(
                  title: Text(l.create),
                  content: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      TextField(controller: name, decoration: InputDecoration(labelText: l.fieldName)),
                      TextField(controller: start, decoration: InputDecoration(labelText: l.from)),
                      TextField(controller: end, decoration: InputDecoration(labelText: l.to)),
                    ],
                  ),
                  actions: [
                    TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(l.cancel)),
                    FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(l.save)),
                  ],
                ),
              );
              if (ok != true) return;
              await ref.read(financeApiProvider).saveFiscalYear({
                'name': name.text.trim(),
                'start_date': start.text.trim(),
                'end_date': end.text.trim(),
              });
              if (mounted) setState(() => _tick++);
            }
          : null,
      loader: (api, search, page) => api.fiscalYears(page: page),
      itemBuilder: (context, year) => Card(
        child: ListTile(
          title: Text(year.name),
          subtitle: Text('${year.startDate ?? ''} → ${year.endDate ?? ''} · ${year.status}'),
          onTap: () => context.push('/fiscal-years/${year.id}'),
        ),
      ),
    );
  }
}

class FiscalYearDetailScreen extends ConsumerStatefulWidget {
  const FiscalYearDetailScreen({super.key, required this.id});
  final int id;
  @override
  ConsumerState<FiscalYearDetailScreen> createState() => _FiscalYearDetailScreenState();
}

class _FiscalYearDetailScreenState extends ConsumerState<FiscalYearDetailScreen> {
  FiscalYearRecord? _data;
  bool _loading = true;
  String? _error;

  Future<void> _load() async {
    try {
      final data = await ref.read(financeApiProvider).fiscalYear(widget.id);
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
  void initState() {
    super.initState();
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final year = _data;
    final manage = ref.watch(authControllerProvider).permissions.fiscalYearsManage;
    return DetailScaffold(
      title: year?.name ?? l.fiscalYears,
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: year == null
          ? const SizedBox.shrink()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                InfoRow(label: l.status, value: year.status),
                InfoRow(label: l.from, value: year.startDate),
                InfoRow(label: l.to, value: year.endDate),
                for (final period in year.periods)
                  ListTile(
                    title: Text(period.name ?? '#${period.id}'),
                    subtitle: Text('${period.startDate} → ${period.endDate} · ${period.status ?? ''}'),
                    trailing: manage
                        ? TextButton(
                            onPressed: () async {
                              final next = period.status == 'closed' ? 'open' : 'closed';
                              await ref.read(financeApiProvider).setPeriodStatus(period.id, next);
                              await _load();
                            },
                            child: Text(period.status == 'closed' ? l.openPeriod : l.closePeriod),
                          )
                        : StatusChip(label: period.status ?? ''),
                  ),
                Wrap(spacing: 8, children: [
                  if (manage)
                    FilledButton(
                      onPressed: () async {
                        await ref.read(financeApiProvider).fiscalYearAction(year.id, 'generate-monthly-periods');
                        await _load();
                      },
                      child: Text(l.generatePeriods),
                    ),
                  if (manage && year.status != 'closed')
                    OutlinedButton(
                      onPressed: () async {
                        await ref.read(financeApiProvider).fiscalYearAction(year.id, 'close');
                        await _load();
                      },
                      child: Text(l.closeYear),
                    ),
                  if (manage && year.status == 'closed')
                    FilledButton.tonal(
                      onPressed: () async {
                        await ref.read(financeApiProvider).fiscalYearAction(year.id, 'open');
                        await _load();
                      },
                      child: Text(l.openYear),
                    ),
                ]),
              ],
            ),
    );
  }
}

class _CreateStatementForm extends ConsumerStatefulWidget {
  const _CreateStatementForm({required this.accounts, required this.onCreated});

  final List<TreasuryAccountRecord> accounts;
  final ValueChanged<int> onCreated;

  @override
  ConsumerState<_CreateStatementForm> createState() => _CreateStatementFormState();
}

class _CreateStatementFormState extends ConsumerState<_CreateStatementForm> {
  int? _accountId;
  final _date = TextEditingController(text: isoDate());
  final _opening = TextEditingController(text: '0');
  final _closing = TextEditingController(text: '0');
  final _notes = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _date.dispose();
    _opening.dispose();
    _closing.dispose();
    _notes.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        FormGrid(children: [
          OptionPicker(
            label: l.banks,
            options: [for (final a in widget.accounts) NamedOption(id: a.id, name: a.name)],
            value: _accountId,
            onChanged: (id) => setState(() => _accountId = id),
          ),
          TextField(controller: _date, decoration: InputDecoration(labelText: l.statementDate)),
          TextField(controller: _opening, decoration: InputDecoration(labelText: l.openingBalance)),
          TextField(controller: _closing, decoration: InputDecoration(labelText: l.closingBalance)),
          TextField(controller: _notes, decoration: InputDecoration(labelText: l.notesField)),
        ]),
        const SizedBox(height: 8),
        FilledButton(
          onPressed: _busy || _accountId == null
              ? null
              : () async {
                  setState(() => _busy = true);
                  try {
                    final created = await ref.read(financeApiProvider).createBankStatement({
                      'treasury_account_id': _accountId,
                      'statement_date': _date.text.trim(),
                      'opening_balance': _opening.text.trim(),
                      'closing_balance': _closing.text.trim(),
                      'notes': _notes.text.trim(),
                    });
                    final id = int.parse('${created['id']}');
                    widget.onCreated(id);
                  } catch (e) {
                    if (context.mounted) showFormError(context, e);
                  } finally {
                    if (mounted) setState(() => _busy = false);
                  }
                },
          child: Text(l.addStatement),
        ),
      ],
    );
  }
}

class BankStatementScreen extends ConsumerStatefulWidget {
  const BankStatementScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<BankStatementScreen> createState() => _BankStatementScreenState();
}

class _BankStatementScreenState extends ConsumerState<BankStatementScreen> {
  Map<String, dynamic>? _data;
  bool _loading = true;
  String? _error;
  bool _busy = false;
  final _amount = TextEditingController();
  final _date = TextEditingController(text: isoDate());
  final _description = TextEditingController();
  final _reference = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _amount.dispose();
    _date.dispose();
    _description.dispose();
    _reference.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ref.read(financeApiProvider).bankStatement(widget.id);
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

  Future<void> _run(Future<Map<String, dynamic>> Function() action) async {
    setState(() => _busy = true);
    try {
      final data = await action();
      if (!mounted) return;
      setState(() => _data = data);
    } catch (e) {
      if (mounted) showApiError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final manage = ref.watch(authControllerProvider).permissions.accountingManage;
    final lines = (_data?['lines'] as List? ?? []).whereType<Map>().toList();
    final open = _data?['status'] != 'reconciled';
    return PermissionGate(
      allowed: ref.watch(authControllerProvider).permissions.financeView,
      child: FinanceScaffold(
        title: l.bankStatements,
        showBack: true,
        actions: [IconButton(onPressed: _load, tooltip: l.refresh, icon: const Icon(Icons.refresh))],
        body: AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: FinancePage(
            child: ListView(
              padding: EdgeInsets.zero,
              children: [
                StatusChip(label: '${_data?['status'] ?? ''}'),
                Text('${_data?['treasury_account_name'] ?? ''} · ${_data?['statement_date'] ?? ''}'),
                MetricGrid(metrics: [
                  (l.openingBalance, '${_data?['opening_balance'] ?? '0.00'}'),
                  (l.closingBalance, '${_data?['closing_balance'] ?? '0.00'}'),
                ]),
                if (manage && open) ...[
                  FormSection(
                    title: l.addStatementLines,
                    child: FormGrid(children: [
                      TextField(controller: _date, decoration: InputDecoration(labelText: l.date)),
                      TextField(controller: _amount, decoration: InputDecoration(labelText: l.amount)),
                      TextField(controller: _description, decoration: InputDecoration(labelText: l.notesField)),
                      TextField(controller: _reference, decoration: InputDecoration(labelText: l.reference)),
                    ]),
                  ),
                  Wrap(spacing: 8, runSpacing: 8, children: [
                    FilledButton(
                      onPressed: _busy
                          ? null
                          : () async {
                              if (_amount.text.trim().isEmpty) return;
                              await _run(() => ref.read(financeApiProvider).addBankStatementLines(widget.id, [
                                    {
                                      'posted_date': _date.text.trim(),
                                      'amount': _amount.text.trim(),
                                      'description': _description.text.trim(),
                                      'reference': _reference.text.trim(),
                                    },
                                  ]));
                            },
                      child: Text(l.addStatementLines),
                    ),
                    FilledButton.tonal(
                      onPressed: _busy ? null : () => _run(() => ref.read(financeApiProvider).suggestBankStatementMatches(widget.id)),
                      child: Text(l.suggestMatches),
                    ),
                    FilledButton(
                      onPressed: _busy
                          ? null
                          : () => confirmAndRun(context, () async {
                                await _run(() => ref.read(financeApiProvider).completeBankStatement(widget.id));
                              }),
                      child: Text(l.completeReconciliation),
                    ),
                  ]),
                ],
                const SizedBox(height: 12),
                for (final line in lines)
                  Card(
                    child: ListTile(
                      title: Text('${line['description'] ?? l.bankStatements} · ${line['amount'] ?? ''}'),
                      subtitle: Text(
                        '${line['posted_date'] ?? ''} · ${line['status'] ?? ''}'
                        '${line['suggestion_reason'] != null ? '\n${line['suggestion_reason']} (${line['suggestion_confidence'] ?? ''}%)' : ''}',
                      ),
                      isThreeLine: line['suggestion_reason'] != null,
                      trailing: manage && open && (line['status'] == 'unmatched' || line['status'] == 'suggested')
                          ? Wrap(children: [
                              if (line['suggested_type'] != null && line['suggested_id'] != null)
                                TextButton(
                                  onPressed: _busy
                                      ? null
                                      : () => _run(
                                            () => ref.read(financeApiProvider).matchBankStatementLine(
                                                  widget.id,
                                                  int.parse('${line['id']}'),
                                                  matchedType: '${line['suggested_type']}',
                                                  matchedId: int.parse('${line['suggested_id']}'),
                                                ),
                                          ),
                                  child: Text(l.acceptSuggestion),
                                ),
                              TextButton(
                                onPressed: _busy
                                    ? null
                                    : () => _run(
                                          () => ref.read(financeApiProvider).ignoreBankStatementLine(
                                                widget.id,
                                                int.parse('${line['id']}'),
                                              ),
                                        ),
                                child: Text(l.ignoreLine),
                              ),
                            ])
                          : StatusChip(label: '${line['status'] ?? ''}'),
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

