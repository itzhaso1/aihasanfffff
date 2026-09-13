import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class FinanceReportView extends StatelessWidget {
  const FinanceReportView(this.data, {super.key});

  final Map<String, dynamic> data;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final report = data['report']?.toString();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (data['from'] != null || data['to'] != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text('${l.from}: ${data['from'] ?? ''}  ${l.to}: ${data['to'] ?? ''}'),
          ),
        if (data['profit_and_loss'] is Map) _profitLoss(context, Map<String, dynamic>.from(data['profit_and_loss'] as Map)),
        if (data['trial_balance'] is Map) _trial(context, Map<String, dynamic>.from(data['trial_balance'] as Map)),
        if (data['balance_sheet'] is Map) _balance(context, Map<String, dynamic>.from(data['balance_sheet'] as Map)),
        if (data['general_ledger'] is Map) _ledger(context, Map<String, dynamic>.from(data['general_ledger'] as Map)),
        if (data['aging'] is Map) _aging(context, Map<String, dynamic>.from(data['aging'] as Map)),
        if (data['cash_flow'] is Map) _cash(context, Map<String, dynamic>.from(data['cash_flow'] as Map)),
        if (data['inventory_valuation'] is Map)
          _inventory(context, Map<String, dynamic>.from(data['inventory_valuation'] as Map)),
        if (!_known(report, data))
          Text(l.empty),
      ],
    );
  }

  bool _known(String? report, Map<String, dynamic> data) {
    return data['profit_and_loss'] is Map ||
        data['trial_balance'] is Map ||
        data['balance_sheet'] is Map ||
        data['general_ledger'] is Map ||
        data['aging'] is Map ||
        data['cash_flow'] is Map ||
        data['inventory_valuation'] is Map;
  }

  Widget _kpis(BuildContext context, List<(String, String)> cards) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final card in cards)
          SizedBox(
            width: 180,
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(card.$1, style: Theme.of(context).textTheme.bodySmall),
                    const SizedBox(height: 8),
                    Text(card.$2, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                  ],
                ),
              ),
            ),
          ),
      ],
    );
  }

  Widget _table(BuildContext context, List<String> headers, List<List<String>> rows, {void Function(int index)? onRowTap}) {
    return Card(
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: DataTable(
          columns: [for (final h in headers) DataColumn(label: Text(h))],
          rows: [
            for (var i = 0; i < rows.length; i++)
              DataRow(
                onSelectChanged: onRowTap == null ? null : (_) => onRowTap(i),
                cells: [for (final cell in rows[i]) DataCell(Text(cell))],
              ),
          ],
        ),
      ),
    );
  }

  Widget _profitLoss(BuildContext context, Map<String, dynamic> block) {
    final l = AppLocalizations.of(context);
    final rows = (block['rows'] as List? ?? []).whereType<Map>();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _kpis(context, [
          (l.revenue, '${block['revenue'] ?? '0.00'}'),
          (l.cogs, '${block['cogs'] ?? '0.00'}'),
          (l.grossProfit, '${block['gross_profit'] ?? '0.00'}'),
          (l.netProfit, '${block['net_profit'] ?? '0.00'}'),
        ]),
        const SizedBox(height: 8),
        _table(
          context,
          [l.description, l.status, l.closingBalance],
          [
            for (final row in rows)
              [
                '${row['code'] ?? ''} ${row['name'] ?? ''}',
                '${row['type'] ?? ''}',
                '${row['balance'] ?? row['credit'] ?? row['debit'] ?? ''}',
              ],
          ],
        ),
      ],
    );
  }

  Widget _trial(BuildContext context, Map<String, dynamic> block) {
    final l = AppLocalizations.of(context);
    final rows = (block['rows'] as List? ?? []).whereType<Map>();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _kpis(context, [
          (l.statementDebit, '${block['total_debit'] ?? '0.00'}'),
          (l.statementCredit, '${block['total_credit'] ?? '0.00'}'),
        ]),
        const SizedBox(height: 8),
        _table(
          context,
          [l.description, l.statementDebit, l.statementCredit],
          [
            for (final row in rows)
              ['${row['code'] ?? ''} ${row['name'] ?? ''}', '${row['debit'] ?? ''}', '${row['credit'] ?? ''}'],
          ],
        ),
      ],
    );
  }

  Widget _balance(BuildContext context, Map<String, dynamic> block) {
    final l = AppLocalizations.of(context);
    final rows = (block['rows'] as List? ?? []).whereType<Map>();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _kpis(context, [
          (l.assets, '${block['assets'] ?? '0.00'}'),
          (l.liabilities, '${block['liabilities'] ?? '0.00'}'),
          (l.equity, '${block['equity'] ?? '0.00'}'),
          (l.netProfit, '${block['current_year_earnings'] ?? '0.00'}'),
        ]),
        const SizedBox(height: 8),
        _table(
          context,
          [l.description, l.status, l.closingBalance],
          [
            for (final row in rows)
              ['${row['code'] ?? ''} ${row['name'] ?? ''}', '${row['type'] ?? ''}', '${row['balance'] ?? ''}'],
          ],
        ),
      ],
    );
  }

  Widget _ledger(BuildContext context, Map<String, dynamic> block) {
    final l = AppLocalizations.of(context);
    final rows = (block['lines'] as List? ?? block['rows'] as List? ?? []).whereType<Map>();
    return _table(
      context,
      [l.date, l.description, l.statementDebit, l.statementCredit, l.runningBalance],
      [
        for (final row in rows)
          [
            '${row['date'] ?? ''}',
            '${row['account_code'] ?? ''} ${row['account_name'] ?? ''} ${row['description'] ?? ''}',
            '${row['debit'] ?? ''}',
            '${row['credit'] ?? ''}',
            '${row['balance'] ?? ''}',
          ],
      ],
    );
  }

  Widget _aging(BuildContext context, Map<String, dynamic> block) {
    final l = AppLocalizations.of(context);
    final buckets = block['buckets'] is Map ? Map<String, dynamic>.from(block['buckets'] as Map) : <String, dynamic>{};
    final rows = (block['rows'] as List? ?? []).whereType<Map>().toList();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _kpis(context, [
          for (final entry in buckets.entries) (entry.key, '${entry.value}'),
          (l.total, '${block['total'] ?? '0.00'}'),
        ]),
        const SizedBox(height: 8),
        _table(
          context,
          [l.invoices, l.dueDate, l.overdue, l.due, l.category],
          [
            for (final row in rows)
              [
                '${row['invoice_number'] ?? ''}',
                '${row['due_date'] ?? ''}',
                '${row['days_overdue'] ?? ''}',
                '${row['amount_due'] ?? ''}',
                '${row['bucket'] ?? ''}',
              ],
          ],
          onRowTap: (index) {
            final id = rows[index]['invoice_id'];
            if (id != null) context.push('/invoices/$id');
          },
        ),
      ],
    );
  }

  Widget _cash(BuildContext context, Map<String, dynamic> block) {
    final l = AppLocalizations.of(context);
    return _kpis(context, [
      (l.openingCash, '${block['opening_cash'] ?? '0.00'}'),
      (l.netChange, '${block['net_change'] ?? '0.00'}'),
      (l.closingCash, '${block['closing_cash'] ?? '0.00'}'),
    ]);
  }

  Widget _inventory(BuildContext context, Map<String, dynamic> block) {
    final l = AppLocalizations.of(context);
    final rows = (block['rows'] as List? ?? []).whereType<Map>();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _kpis(context, [(l.total, '${block['total'] ?? '0.00'}')]),
        const SizedBox(height: 8),
        _table(
          context,
          [l.description, l.quantity, l.price, l.total],
          [
            for (final row in rows)
              [
                '${row['sku'] ?? ''} ${row['name'] ?? ''}',
                '${row['stock'] ?? ''}',
                '${row['cost'] ?? ''}',
                '${row['value'] ?? ''}',
              ],
          ],
        ),
      ],
    );
  }
}
