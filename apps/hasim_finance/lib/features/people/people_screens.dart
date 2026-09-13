import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/layout/finance_layout.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';
import 'package:hasim_finance/core/widgets/widgets.dart';
import 'package:hasim_finance/features/shared/paged.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class PeopleScreen extends ConsumerWidget {
  const PeopleScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    return PagedListScreen<FinanceEmployeeRecord>(
      title: l.peopleObligations,
      allowed: auth.permissions.payrollView,
      onCreate: auth.permissions.payrollManage ? () => context.push('/people/new') : null,
      loader: (api, search, page) => api.employees(search: search, page: page),
      itemBuilder: (context, person) => InkWell(
        onTap: () => context.push('/people/${person.id}'),
        borderRadius: BorderRadius.circular(16),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: FinanceTokens.card(context),
          child: LayoutBuilder(
            builder: (context, constraints) {
              final wide = constraints.maxWidth >= 860;
              final identity = Row(
                children: [
                  FinanceIconBadge(icon: Icons.badge_outlined, tone: FinanceIconTone.teal),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(person.fullName, style: const TextStyle(fontWeight: FontWeight.w800)),
                        Text(
                          [person.employeeCode, person.jobTitle].where((v) => (v ?? '').isNotEmpty).join(' · '),
                          style: TextStyle(color: context.hs.textMuted, fontSize: 12),
                        ),
                      ],
                    ),
                  ),
                  if (!wide) StatusChip(label: _statusLabel(person.status, l), tone: toneFor(person.status)),
                ],
              );
              final metrics = Wrap(
                spacing: 8,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  _mini(context, l.totalOwed, person.summary['total_owed'] ?? '0.00'),
                  _mini(context, l.totalPaid, person.summary['total_paid'] ?? '0.00'),
                  _mini(context, l.remainingBalance, person.summary['remaining'] ?? '0.00'),
                  _mini(context, l.advanceRemaining, person.summary['advance_remaining'] ?? '0.00'),
                  if (wide) StatusChip(label: _statusLabel(person.status, l), tone: toneFor(person.status)),
                ],
              );
              if (wide) {
                return Row(
                  children: [
                    Expanded(child: identity),
                    Flexible(child: metrics),
                  ],
                );
              }
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  identity,
                  const SizedBox(height: 10),
                  metrics,
                ],
              );
            },
          ),
        ),
      ),
    );
  }

  Widget _mini(BuildContext context, String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Text(label, style: TextStyle(fontSize: 11, color: context.hs.textMuted, fontWeight: FontWeight.w700)),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w800)),
        ],
      ),
    );
  }
}

class PersonFormScreen extends ConsumerStatefulWidget {
  const PersonFormScreen({super.key, this.id});
  final int? id;

  @override
  ConsumerState<PersonFormScreen> createState() => _PersonFormScreenState();
}

class _PersonFormScreenState extends ConsumerState<PersonFormScreen> {
  final _name = TextEditingController();
  final _code = TextEditingController();
  final _title = TextEditingController();
  final _salary = TextEditingController(text: '0');
  final _hire = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _address = TextEditingController();
  final _emergency = TextEditingController();
  final _notes = TextEditingController();
  String _status = 'active';
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    if (widget.id != null) _hydrate();
  }

  @override
  void dispose() {
    for (final c in [_name, _code, _title, _salary, _hire, _phone, _email, _address, _emergency, _notes]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _hydrate() async {
    setState(() => _loading = true);
    try {
      final person = await ref.read(financeApiProvider).employee(widget.id!);
      _name.text = person.fullName;
      _code.text = person.employeeCode ?? '';
      _title.text = person.jobTitle ?? '';
      _salary.text = person.basicSalary;
      _hire.text = person.hireDate ?? '';
      _phone.text = person.phone ?? '';
      _email.text = person.email ?? '';
      _address.text = person.address ?? '';
      _emergency.text = person.emergencyContact ?? '';
      _notes.text = person.notes ?? '';
      _status = person.status ?? 'active';
      setState(() => _loading = false);
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  Future<void> _save() async {
    setState(() => _loading = true);
    try {
      final saved = await ref.read(financeApiProvider).saveEmployee({
        'full_name': _name.text.trim(),
        'employee_code': _code.text.trim().isEmpty ? null : _code.text.trim(),
        'job_title': _title.text.trim().isEmpty ? null : _title.text.trim(),
        'basic_salary': _salary.text.trim(),
        'hire_date': _hire.text.trim().isEmpty ? null : _hire.text.trim(),
        'status': _status,
        'phone': _phone.text.trim().isEmpty ? null : _phone.text.trim(),
        'email': _email.text.trim().isEmpty ? null : _email.text.trim(),
        'address': _address.text.trim().isEmpty ? null : _address.text.trim(),
        'emergency_contact': _emergency.text.trim().isEmpty ? null : _emergency.text.trim(),
        'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      }, id: widget.id);
      if (!mounted) return;
      context.go('/people/${saved.id}');
    } catch (e) {
      if (!mounted) return;
      showFormError(context, e);
      setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return FinanceScaffold(
      title: widget.id == null ? l.addEmployee : l.edit,
      showBack: true,
      primaryAction: FilledButton(onPressed: _loading ? null : _save, child: Text(l.save)),
      body: AsyncBody(
        loading: _loading && widget.id != null && _name.text.isEmpty,
        error: _error,
        onRetry: _hydrate,
        child: FinancePage(
          child: ListView(
            children: [
              FormSection(
                title: l.companyPeopleHint,
                child: FormGrid(children: [
                  TextField(controller: _name, decoration: InputDecoration(labelText: l.fieldName)),
                  TextField(controller: _code, decoration: InputDecoration(labelText: l.employeeCode)),
                  TextField(controller: _title, decoration: InputDecoration(labelText: l.jobTitle)),
                  TextField(controller: _salary, decoration: InputDecoration(labelText: l.basicSalary), keyboardType: TextInputType.number),
                  TextField(controller: _hire, decoration: InputDecoration(labelText: l.hireDate)),
                  DropdownButtonFormField<String>(
                    isExpanded: true,
                    // ignore: deprecated_member_use
                    value: _status,
                    decoration: InputDecoration(labelText: l.status),
                    items: [
                      DropdownMenuItem(value: 'active', child: Text(l.activeStatus)),
                      DropdownMenuItem(value: 'inactive', child: Text(l.inactiveStatus)),
                      DropdownMenuItem(value: 'suspended', child: Text(l.suspendedStatus)),
                    ],
                    onChanged: (value) => setState(() => _status = value ?? 'active'),
                  ),
                  TextField(controller: _phone, decoration: InputDecoration(labelText: l.phone)),
                  TextField(controller: _email, decoration: InputDecoration(labelText: l.email)),
                  TextField(controller: _address, decoration: InputDecoration(labelText: l.address)),
                  TextField(controller: _emergency, decoration: InputDecoration(labelText: l.emergencyContact)),
                  TextField(controller: _notes, maxLines: 3, decoration: InputDecoration(labelText: l.notesField)),
                ]),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class PersonDetailScreen extends ConsumerStatefulWidget {
  const PersonDetailScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<PersonDetailScreen> createState() => _PersonDetailScreenState();
}

class _PersonDetailScreenState extends ConsumerState<PersonDetailScreen> {
  FinanceEmployeeRecord? _person;
  bool _loading = true;
  String? _error;
  final _periodStart = TextEditingController();
  final _periodEnd = TextEditingController();
  final _basic = TextEditingController();
  final _allow = TextEditingController(text: '0');
  final _deduct = TextEditingController(text: '0');
  final _paidAt = TextEditingController();
  final _notes = TextEditingController();
  String _payStatus = 'pending';

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _periodStart.text = isoDate(DateTime(now.year, now.month, 1));
    _periodEnd.text = isoDate(DateTime(now.year, now.month + 1, 0));
    _load();
  }

  @override
  void dispose() {
    for (final c in [_periodStart, _periodEnd, _basic, _allow, _deduct, _paidAt, _notes]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final person = await ref.read(financeApiProvider).employee(widget.id);
      if (!mounted) return;
      _basic.text = person.basicSalary;
      setState(() {
        _person = person;
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

  Future<void> _saveRecord() async {
    try {
      await ref.read(financeApiProvider).savePayrollRecord(widget.id, {
        'period_start': _periodStart.text.trim(),
        'period_end': _periodEnd.text.trim(),
        'basic_salary': _basic.text.trim(),
        'allowances_total': _allow.text.trim(),
        'deductions_total': _deduct.text.trim(),
        'payment_status': _payStatus,
        if (_paidAt.text.trim().isNotEmpty) 'paid_at': _paidAt.text.trim(),
        if (_notes.text.trim().isNotEmpty) 'notes': _notes.text.trim(),
      });
      if (!mounted) return;
      showSnack(context, AppLocalizations.of(context).success);
      _load();
    } catch (e) {
      if (!mounted) return;
      showFormError(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    final person = _person;
    return FinanceScaffold(
      title: person?.fullName ?? l.peopleObligations,
      subtitle: person == null ? null : '${person.employeeCode ?? ''} · ${person.jobTitle ?? ''}',
      showBack: true,
      actions: [
        if (auth.permissions.payrollManage)
          OutlinedButton(onPressed: () => context.push('/people/${widget.id}/edit'), child: Text(l.edit)),
      ],
      body: AsyncBody(
        loading: _loading,
        error: _error,
        onRetry: _load,
        child: person == null
            ? const SizedBox.shrink()
            : FinancePage(
                child: ListView(
                  children: [
                    Text(l.companyPeopleHint, style: TextStyle(color: context.hs.textMuted, fontSize: 12)),
                    const SizedBox(height: 12),
                    KpiGrid(
                      minWidth: 160,
                      cards: [
                        KpiCard(label: l.basicSalary, value: person.basicSalary, compact: true, icon: Icons.payments_outlined),
                        KpiCard(label: l.totalOwed, value: person.summary['total_owed'] ?? '0.00', compact: true, icon: Icons.account_balance_wallet_outlined, tone: FinanceIconTone.orange),
                        KpiCard(label: l.totalPaid, value: person.summary['total_paid'] ?? '0.00', compact: true, icon: Icons.check_circle_outline, tone: FinanceIconTone.green),
                        KpiCard(label: l.remainingBalance, value: person.summary['remaining'] ?? '0.00', compact: true, icon: Icons.pending_outlined, tone: FinanceIconTone.red),
                        KpiCard(label: l.advanceIssued, value: person.summary['advance_issued'] ?? '0.00', compact: true, icon: Icons.front_hand_outlined, tone: FinanceIconTone.indigo),
                        KpiCard(label: l.advanceSettled, value: person.summary['advance_settled'] ?? '0.00', compact: true, icon: Icons.done_all, tone: FinanceIconTone.teal),
                        KpiCard(label: l.advanceRemaining, value: person.summary['advance_remaining'] ?? '0.00', compact: true, icon: Icons.warning_amber_outlined, tone: FinanceIconTone.amber),
                        KpiCard(label: l.bonuses, value: person.summary['bonuses_total'] ?? '0.00', compact: true, icon: Icons.emoji_events_outlined, tone: FinanceIconTone.orange),
                        KpiCard(label: l.deductions, value: person.summary['deductions_total'] ?? '0.00', compact: true, icon: Icons.remove_circle_outline, tone: FinanceIconTone.red),
                      ],
                    ),
                    const SizedBox(height: 12),
                    if (auth.permissions.payrollManage)
                      FormSection(
                        title: l.addPayrollRecord,
                        child: FormGrid(children: [
                          TextField(controller: _periodStart, decoration: InputDecoration(labelText: l.periodStart)),
                          TextField(controller: _periodEnd, decoration: InputDecoration(labelText: l.periodEnd)),
                          TextField(controller: _basic, decoration: InputDecoration(labelText: l.basicSalary)),
                          TextField(controller: _allow, decoration: InputDecoration(labelText: l.allowancesTotal)),
                          TextField(controller: _deduct, decoration: InputDecoration(labelText: l.deductionsTotal)),
                          DropdownButtonFormField<String>(
                            isExpanded: true,
                            // ignore: deprecated_member_use
                            value: _payStatus,
                            decoration: InputDecoration(labelText: l.paymentStatus),
                            items: [
                              DropdownMenuItem(value: 'draft', child: Text(l.draft)),
                              DropdownMenuItem(value: 'pending', child: Text(l.pending)),
                              DropdownMenuItem(value: 'partial', child: Text(l.partial)),
                              DropdownMenuItem(value: 'paid', child: Text(l.paid)),
                              DropdownMenuItem(value: 'cancelled', child: Text(l.cancelled)),
                            ],
                            onChanged: (value) => setState(() => _payStatus = value ?? 'pending'),
                          ),
                          TextField(controller: _paidAt, decoration: InputDecoration(labelText: l.paymentDate)),
                          TextField(controller: _notes, decoration: InputDecoration(labelText: l.notesField)),
                          FilledButton(onPressed: _saveRecord, child: Text(l.addPayrollRecord)),
                        ]),
                      ),
                    FinanceSurface(
                      title: l.outstandingObligations,
                      padding: EdgeInsets.zero,
                      child: _recordsTable(l, person.payrollRecords),
                    ),
                    const SizedBox(height: 12),
                    FinanceSurface(
                      title: l.salaryAdvances,
                      padding: EdgeInsets.zero,
                      child: _advancesTable(l, person.advances),
                    ),
                    const SizedBox(height: 12),
                    FinanceSurface(
                      title: l.paymentHistory,
                      padding: EdgeInsets.zero,
                      child: _adjustmentsTable(l, person.adjustments),
                    ),
                  ],
                ),
              ),
      ),
    );
  }

  Widget _recordsTable(AppLocalizations l, List<PayrollRecord> rows) {
    if (rows.isEmpty) return Padding(padding: const EdgeInsets.all(20), child: Text(l.empty));
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: DataTable(
        columns: [
          DataColumn(label: Text(l.periodLabel)),
          DataColumn(label: Text(l.basicSalary)),
          DataColumn(label: Text(l.allowancesTotal)),
          DataColumn(label: Text(l.deductionsTotal)),
          DataColumn(label: Text(l.netAmount)),
          DataColumn(label: Text(l.remainingBalance)),
          DataColumn(label: Text(l.status)),
        ],
        rows: [
          for (final row in rows)
            DataRow(cells: [
              DataCell(Text('${row.periodStart ?? ''} → ${row.periodEnd ?? ''}')),
              DataCell(Text(row.basicSalary)),
              DataCell(Text(row.allowancesTotal)),
              DataCell(Text(row.deductionsTotal)),
              DataCell(Text(row.netAmount, style: const TextStyle(fontWeight: FontWeight.w800))),
              DataCell(Text(row.remaining)),
              DataCell(StatusChip(label: row.paymentStatus ?? '', tone: toneFor(row.paymentStatus))),
            ]),
        ],
      ),
    );
  }

  Widget _advancesTable(AppLocalizations l, List<SalaryAdvanceRecord> rows) {
    if (rows.isEmpty) return Padding(padding: const EdgeInsets.all(20), child: Text(l.empty));
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: DataTable(
        columns: [
          DataColumn(label: Text(l.issuedAt)),
          DataColumn(label: Text(l.advanceIssued)),
          DataColumn(label: Text(l.settledAmount)),
          DataColumn(label: Text(l.remainingAmount)),
          DataColumn(label: Text(l.status)),
        ],
        rows: [
          for (final row in rows)
            DataRow(cells: [
              DataCell(Text(row.issuedAt ?? '')),
              DataCell(Text(row.amount)),
              DataCell(Text(row.settledAmount)),
              DataCell(Text(row.remainingAmount, style: const TextStyle(fontWeight: FontWeight.w800))),
              DataCell(StatusChip(label: row.status ?? '', tone: toneFor(row.status))),
            ]),
        ],
      ),
    );
  }

  Widget _adjustmentsTable(AppLocalizations l, List<PayrollAdjustmentRecord> rows) {
    if (rows.isEmpty) return Padding(padding: const EdgeInsets.all(20), child: Text(l.empty));
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: DataTable(
        columns: [
          DataColumn(label: Text(l.date)),
          DataColumn(label: Text(l.fieldName)),
          DataColumn(label: Text(l.amount)),
          DataColumn(label: Text(l.status)),
        ],
        rows: [
          for (final row in rows)
            DataRow(cells: [
              DataCell(Text(row.effectiveDate ?? '')),
              DataCell(Text('${row.title ?? ''} (${row.type ?? ''})')),
              DataCell(Text(row.amount, style: const TextStyle(fontWeight: FontWeight.w800))),
              DataCell(StatusChip(label: row.status ?? '', tone: toneFor(row.status))),
            ]),
        ],
      ),
    );
  }
}

class PayrollOverviewScreen extends ConsumerStatefulWidget {
  const PayrollOverviewScreen({super.key});

  @override
  ConsumerState<PayrollOverviewScreen> createState() => _PayrollOverviewScreenState();
}

class _PayrollOverviewScreenState extends ConsumerState<PayrollOverviewScreen> {
  PayrollOverview? _data;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ref.read(financeApiProvider).payrollOverview();
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
    return PermissionGate(
      allowed: ref.watch(authControllerProvider).permissions.payrollView,
      child: FinanceScaffold(
        title: l.payroll,
        subtitle: l.peopleSubtitle,
        actions: [IconButton(onPressed: _load, tooltip: l.refresh, icon: const Icon(Icons.refresh_rounded))],
        primaryAction: FilledButton(onPressed: () => context.go('/people'), child: Text(l.showAll)),
        body: AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: _data == null
              ? const SizedBox.shrink()
              : FinancePage(
                  child: ListView(
                    children: [
                      KpiGrid(cards: [
                        KpiCard(label: l.companyEmployees, value: _data!.cards['company_employees'] ?? '0', showCurrency: false, compact: true, icon: Icons.badge_outlined),
                        KpiCard(label: l.payrollPaid, value: _data!.cards['payroll_paid_total'] ?? '0.00', compact: true, icon: Icons.payments_outlined),
                        KpiCard(label: l.allowances, value: _data!.cards['allowances_bonuses_total'] ?? '0.00', compact: true, icon: Icons.add_card_outlined, tone: FinanceIconTone.indigo),
                        KpiCard(label: l.openAdvances, value: _data!.cards['open_advances_total'] ?? '0.00', compact: true, icon: Icons.front_hand_outlined, tone: FinanceIconTone.orange),
                        KpiCard(label: l.deductions, value: _data!.cards['deductions_total'] ?? '0.00', compact: true, icon: Icons.remove_circle_outline, tone: FinanceIconTone.red),
                      ]),
                      const SizedBox(height: 16),
                      FinanceSurface(
                        title: l.outstandingObligations,
                        padding: EdgeInsets.zero,
                        child: _data!.latestRecords.isEmpty
                            ? Padding(padding: const EdgeInsets.all(20), child: Text(l.empty))
                            : DataTable(
                                columns: [
                                  DataColumn(label: Text(l.fieldName)),
                                  DataColumn(label: Text(l.periodLabel)),
                                  DataColumn(label: Text(l.netAmount)),
                                  DataColumn(label: Text(l.remainingBalance)),
                                  DataColumn(label: Text(l.status)),
                                ],
                                rows: [
                                  for (final row in _data!.latestRecords)
                                    DataRow(
                                      cells: [
                                        DataCell(Text(row.employeeName ?? '')),
                                        DataCell(Text('${row.periodStart ?? ''} → ${row.periodEnd ?? ''}')),
                                        DataCell(Text(row.netAmount, style: const TextStyle(fontWeight: FontWeight.w800))),
                                        DataCell(Text(row.remaining)),
                                        DataCell(StatusChip(label: row.paymentStatus ?? '', tone: toneFor(row.paymentStatus))),
                                      ],
                                    ),
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

class SalaryAdvancesScreen extends ConsumerStatefulWidget {
  const SalaryAdvancesScreen({super.key});

  @override
  ConsumerState<SalaryAdvancesScreen> createState() => _SalaryAdvancesScreenState();
}

class _SalaryAdvancesScreenState extends ConsumerState<SalaryAdvancesScreen> {
  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    return PagedListScreen<SalaryAdvanceRecord>(
      title: l.salaryAdvances,
      allowed: auth.permissions.salaryAdvancesView,
      onCreate: auth.permissions.salaryAdvancesManage ? () => _issue(context) : null,
      loader: (api, search, page) => api.salaryAdvances(search: search, page: page),
      itemBuilder: (context, row) => Material(
        color: context.hs.card,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(FinanceTokens.radiusLg),
          side: BorderSide(color: context.hs.border),
        ),
        child: ListTile(
          title: Text(row.employeeName ?? l.salaryAdvances, style: const TextStyle(fontWeight: FontWeight.w800)),
          subtitle: Text('${row.type ?? ''} · ${row.issuedAt ?? ''} · ${l.remainingAmount} ${row.remainingAmount}'),
          trailing: Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
            Text(row.amount, style: const TextStyle(fontWeight: FontWeight.w800)),
            StatusChip(label: row.status ?? '', tone: toneFor(row.status)),
            if (auth.permissions.salaryAdvancesManage && row.status == 'open')
              FilledButton(onPressed: () => _repay(context, row), child: Text(l.repay)),
          ]),
        ),
      ),
    );
  }

  Future<void> _issue(BuildContext context) async {
    final l = AppLocalizations.of(context);
    final people = await ref.read(financeApiProvider).employees(perPage: 100);
    if (!context.mounted) return;
    final amount = TextEditingController();
    final date = TextEditingController(text: isoDate());
    final notes = TextEditingController();
    int? employeeId;
    var type = 'salary_advance';
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(l.issueAdvance),
        content: SizedBox(
          width: 420,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              DropdownButtonFormField<int>(
                isExpanded: true,
                decoration: InputDecoration(labelText: l.selectEmployee),
                items: [
                  for (final person in people.items)
                    DropdownMenuItem(value: person.id, child: Text(person.fullName)),
                ],
                onChanged: (value) => employeeId = value,
              ),
              const SizedBox(height: 8),
              TextField(controller: amount, decoration: InputDecoration(labelText: l.amount), keyboardType: TextInputType.number),
              const SizedBox(height: 8),
              TextField(controller: date, decoration: InputDecoration(labelText: l.issuedAt)),
              const SizedBox(height: 8),
              DropdownButtonFormField<String>(
                isExpanded: true,
                // ignore: deprecated_member_use
                value: type,
                decoration: InputDecoration(labelText: l.status),
                items: [
                  DropdownMenuItem(value: 'salary_advance', child: Text(l.salaryAdvanceType)),
                  DropdownMenuItem(value: 'employee_loan', child: Text(l.employeeLoan)),
                ],
                onChanged: (value) => type = value ?? 'salary_advance',
              ),
              const SizedBox(height: 8),
              TextField(controller: notes, decoration: InputDecoration(labelText: l.notesField)),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(l.cancel)),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(l.save)),
        ],
      ),
    );
    if (ok == true && employeeId != null) {
      try {
        await ref.read(financeApiProvider).issueSalaryAdvance({
          'finance_employee_id': employeeId,
          'amount': amount.text.trim(),
          'issued_at': date.text.trim(),
          'type': type,
          'payment_method': 'cash',
          if (notes.text.trim().isNotEmpty) 'notes': notes.text.trim(),
        });
        if (context.mounted) showSnack(context, l.success);
        if (context.mounted) setState(() {});
      } catch (e) {
        if (context.mounted) showFormError(context, e);
      }
    }
  }

  Future<void> _repay(BuildContext context, SalaryAdvanceRecord row) async {
    final l = AppLocalizations.of(context);
    final amount = TextEditingController();
    final date = TextEditingController(text: isoDate());
    var method = 'cash';
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(l.settleAdvance),
        content: SizedBox(
          width: 380,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(controller: amount, decoration: InputDecoration(labelText: l.amount), keyboardType: TextInputType.number),
              const SizedBox(height: 8),
              TextField(controller: date, decoration: InputDecoration(labelText: l.paymentDate)),
              const SizedBox(height: 8),
              DropdownButtonFormField<String>(
                isExpanded: true,
                // ignore: deprecated_member_use
                value: method,
                items: [
                  DropdownMenuItem(value: 'cash', child: Text(l.methodCash)),
                  DropdownMenuItem(value: 'bank_transfer', child: Text(l.methodBank)),
                  DropdownMenuItem(value: 'card', child: Text(l.methodCard)),
                  DropdownMenuItem(value: 'payroll_deduction', child: Text(l.methodPayrollDeduction)),
                  DropdownMenuItem(value: 'other', child: Text(l.methodOther)),
                ],
                onChanged: (value) => method = value ?? 'cash',
              ),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(l.cancel)),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(l.save)),
        ],
      ),
    );
    if (ok == true) {
      try {
        await ref.read(financeApiProvider).repaySalaryAdvance(row.id, {
          'amount': amount.text.trim(),
          'payment_date': date.text.trim(),
          'method': method,
        });
        if (context.mounted) showSnack(context, l.success);
        if (context.mounted) setState(() {});
      } catch (e) {
        if (context.mounted) showFormError(context, e);
      }
    }
  }
}

class PayrollAdjustmentsScreen extends ConsumerWidget {
  const PayrollAdjustmentsScreen({super.key, required this.type});
  final String type;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    final title = switch (type) {
      'bonus' => l.bonuses,
      'deduction' => l.deductions,
      _ => l.allowances,
    };
    return PagedListScreen<PayrollAdjustmentRecord>(
      title: title,
      allowed: auth.permissions.adjustmentsView,
      onCreate: auth.permissions.adjustmentsManage ? () => _create(context, ref, l) : null,
      loader: (api, search, page) => api.payrollAdjustments(search: search, type: type, page: page),
      itemBuilder: (context, row) => Material(
        color: context.hs.card,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(FinanceTokens.radiusLg),
          side: BorderSide(color: context.hs.border),
        ),
        child: ListTile(
          title: Text('${row.title ?? ''} · ${row.employeeName ?? ''}', style: const TextStyle(fontWeight: FontWeight.w800)),
          subtitle: Text('${row.effectiveDate ?? ''} · ${row.amount}'),
          trailing: Wrap(spacing: 6, children: [
            StatusChip(label: row.status ?? '', tone: toneFor(row.status)),
            if (auth.permissions.adjustmentsManage && row.status == 'draft')
              TextButton(
                onPressed: () => _act(context, ref, row.id, 'approve', l),
                child: Text(l.approve),
              ),
            if (auth.permissions.adjustmentsManage && (row.status == 'draft' || row.status == 'approved'))
              TextButton(
                onPressed: () => _act(context, ref, row.id, 'post', l),
                child: Text(l.postAdjustment),
              ),
            if (auth.permissions.adjustmentsManage && row.status != 'posted' && row.status != 'cancelled')
              TextButton(
                onPressed: () => _act(context, ref, row.id, 'cancel', l),
                child: Text(l.cancelAdjustment),
              ),
          ]),
        ),
      ),
    );
  }

  Future<void> _act(BuildContext context, WidgetRef ref, int id, String action, AppLocalizations l) async {
    try {
      await ref.read(financeApiProvider).payrollAdjustmentAction(id, action);
      if (context.mounted) showSnack(context, l.success);
    } catch (e) {
      if (context.mounted) showFormError(context, e);
    }
  }

  Future<void> _create(BuildContext context, WidgetRef ref, AppLocalizations l) async {
    final people = await ref.read(financeApiProvider).employees(perPage: 100);
    if (!context.mounted) return;
    final title = TextEditingController();
    final amount = TextEditingController();
    final date = TextEditingController(text: isoDate());
    int? employeeId;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(l.addAdjustment),
        content: SizedBox(
          width: 420,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              DropdownButtonFormField<int>(
                isExpanded: true,
                decoration: InputDecoration(labelText: l.selectEmployee),
                items: [
                  for (final person in people.items) DropdownMenuItem(value: person.id, child: Text(person.fullName)),
                ],
                onChanged: (value) => employeeId = value,
              ),
              const SizedBox(height: 8),
              TextField(controller: title, decoration: InputDecoration(labelText: l.fieldName)),
              const SizedBox(height: 8),
              TextField(controller: amount, decoration: InputDecoration(labelText: l.amount), keyboardType: TextInputType.number),
              const SizedBox(height: 8),
              TextField(controller: date, decoration: InputDecoration(labelText: l.effectiveDate)),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(l.cancel)),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(l.save)),
        ],
      ),
    );
    if (ok == true && employeeId != null) {
      try {
        await ref.read(financeApiProvider).savePayrollAdjustment({
          'type': type,
          'finance_employee_id': employeeId,
          'title': title.text.trim(),
          'amount': amount.text.trim(),
          'effective_date': date.text.trim(),
          'status': 'draft',
        });
        if (context.mounted) showSnack(context, l.success);
      } catch (e) {
        if (context.mounted) showFormError(context, e);
      }
    }
  }
}

String _statusLabel(String? status, AppLocalizations l) {
  return switch (status) {
    'active' => l.activeStatus,
    'inactive' => l.inactiveStatus,
    'suspended' => l.suspendedStatus,
    _ => status ?? '',
  };
}
