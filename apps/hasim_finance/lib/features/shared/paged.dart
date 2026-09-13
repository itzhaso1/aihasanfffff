import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/core/api/finance_api.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/layout/finance_chrome.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';
import 'package:hasim_finance/core/widgets/widgets.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class PagedListScreen<T> extends ConsumerStatefulWidget {
  const PagedListScreen({
    super.key,
    required this.title,
    required this.loader,
    required this.itemBuilder,
    this.onCreate,
    this.allowed = true,
    this.filterBar,
    this.filterKey,
    this.filtersActive = false,
    this.summaryBuilder,
    this.emptyTitle,
    this.emptySubtitle,
    this.emptyIcon = Icons.inbox_outlined,
    this.emptyActionLabel,
  });

  final String title;
  final Future<PagedResult<T>> Function(FinanceApi api, String search, int page) loader;
  final Widget Function(BuildContext context, T item) itemBuilder;
  final VoidCallback? onCreate;
  final bool allowed;

  /// Extra filter controls rendered inside the same card as the search box.
  final Widget? filterBar;

  /// Opaque string describing the current filter values. When it changes the
  /// list reloads from page 1 while keeping the search text and scroll state
  /// (unlike swapping the widget [key], which threw the whole state away).
  final String? filterKey;

  /// Whether any filter other than the search box is active; drives the
  /// empty-state copy ("no results for the current filters" vs "nothing yet").
  final bool filtersActive;
  final Widget Function(BuildContext context, Map<String, dynamic>? meta)? summaryBuilder;
  final String? emptyTitle;
  final String? emptySubtitle;
  final IconData emptyIcon;

  /// Label for the empty-state CTA; shown only when [onCreate] is set.
  final String? emptyActionLabel;

  @override
  ConsumerState<PagedListScreen<T>> createState() => _PagedListScreenState<T>();
}

class _PagedListScreenState<T> extends ConsumerState<PagedListScreen<T>> {
  final _search = TextEditingController();
  Timer? _debounce;
  bool _loading = true;
  String? _error;
  List<T> _items = [];
  int _page = 1;
  int _lastPage = 1;
  int _requestId = 0;
  Map<String, dynamic>? _meta;

  @override
  void initState() {
    super.initState();
    _load(reset: true);
  }

  @override
  void didUpdateWidget(covariant PagedListScreen<T> oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.filterKey != oldWidget.filterKey) {
      _load(reset: true);
    }
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  Future<void> _load({bool reset = false}) async {
    final requestId = ++_requestId;
    if (reset) {
      _page = 1;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final page = await widget.loader(ref.read(financeApiProvider), _search.text.trim(), _page);
      if (!mounted || requestId != _requestId) return;
      setState(() {
        _items = reset ? page.items : [..._items, ...page.items];
        _page = page.page;
        _lastPage = page.lastPage;
        _meta = page.meta;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted || requestId != _requestId) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (e) {
      if (!mounted || requestId != _requestId) return;
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  void _loadMore() {
    if (_loading || _page >= _lastPage) return;
    _page += 1;
    _load();
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(authControllerProvider.select((s) => s.workspace?.id), (prev, next) {
      if (prev != next) {
        _load(reset: true);
      }
    });
    final l = AppLocalizations.of(context);
    return PermissionGate(
      allowed: widget.allowed,
      child: FinanceScaffold(
        title: widget.title,
        actions: [
          IconButton(onPressed: () => _load(reset: true), tooltip: l.refresh, icon: const Icon(Icons.refresh_rounded)),
        ],
        floatingActionButton: widget.onCreate == null
            ? null
            : FloatingActionButton(onPressed: widget.onCreate, child: const Icon(Icons.add)),
        body: _buildBody(context, l),
      ),
    );
  }

  Widget _buildBody(BuildContext context, AppLocalizations l) {
    final searching = _search.text.trim().isNotEmpty;
    final showState = _error != null || _items.isEmpty;
    final header = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        FinanceFilterBar(
          below: widget.filterBar,
          children: [
            SizedBox(
              width: MediaQuery.sizeOf(context).width < FinanceFilterField.stackBreakpoint ? double.infinity : 320,
              child: TextField(
                key: const Key('paged-search'),
                controller: _search,
                textInputAction: TextInputAction.search,
                decoration: FinanceFilterField.decoration(
                  hintText: l.search,
                  prefixIcon: const Icon(Icons.search, size: 18),
                  suffixIcon: searching
                      ? IconButton(
                          tooltip: MaterialLocalizations.of(context).deleteButtonTooltip,
                          icon: const Icon(Icons.close, size: 16),
                          padding: EdgeInsets.zero,
                          constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                          onPressed: () {
                            _search.clear();
                            _debounce?.cancel();
                            _load(reset: true);
                          },
                        )
                      : null,
                ),
                onChanged: (value) {
                  _debounce?.cancel();
                  _debounce = Timer(const Duration(milliseconds: 350), () => _load(reset: true));
                  // Re-render immediately so the clear icon tracks the text.
                  setState(() {});
                },
                onSubmitted: (_) {
                  _debounce?.cancel();
                  _load(reset: true);
                },
              ),
            ),
          ],
        ),
        if (widget.summaryBuilder != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
            child: widget.summaryBuilder!(context, _meta),
          ),
        if (_loading && _items.isNotEmpty) const LinearProgressIndicator(minHeight: 2),
      ],
    );
    // A single scroll view keeps tall filter panels from squeezing the list
    // (or overflowing the viewport) on short screens.
    return CustomScrollView(
      slivers: [
        SliverToBoxAdapter(child: header),
        if (showState)
          SliverFillRemaining(
            hasScrollBody: false,
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 24),
              child: AsyncBody(
                loading: _loading && _items.isEmpty,
                error: _error,
                isEmpty: _items.isEmpty,
                emptyIcon: widget.emptyIcon,
                emptyTitle: (searching || widget.filtersActive) ? l.noResults : (widget.emptyTitle ?? l.empty),
                emptySubtitle: (searching || widget.filtersActive) ? l.noResultsForFilters : widget.emptySubtitle,
                emptyActionLabel: (searching || widget.filtersActive) ? null : widget.emptyActionLabel,
                onEmptyAction: (searching || widget.filtersActive) ? null : widget.onCreate,
                onRetry: () => _load(reset: true),
                child: const SizedBox.shrink(),
              ),
            ),
          )
        else
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
            sliver: SliverList.separated(
              itemCount: _items.length + (_page < _lastPage ? 1 : 0),
              separatorBuilder: (_, _) => const SizedBox(height: 8),
              itemBuilder: (context, i) {
                if (i >= _items.length) {
                  return Center(
                    child: TextButton(
                      onPressed: _loading ? null : _loadMore,
                      child: Text(l.loadMore),
                    ),
                  );
                }
                return Material(
                  color: Colors.transparent,
                  child: widget.itemBuilder(context, _items[i]),
                );
              },
            ),
          ),
      ],
    );
  }
}

class DetailScaffold extends StatelessWidget {
  const DetailScaffold({
    super.key,
    required this.title,
    required this.loading,
    required this.onRetry,
    required this.child,
    this.error,
    this.actions = const [],
  });

  final String title;
  final bool loading;
  final String? error;
  final VoidCallback onRetry;
  final Widget child;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    return FinanceScaffold(
      title: title,
      showBack: true,
      actions: actions,
      body: AsyncBody(loading: loading, error: error, onRetry: onRetry, child: child),
    );
  }
}

Future<void> confirmAndRun(BuildContext context, Future<void> Function() action, {String? message}) async {
  final l = AppLocalizations.of(context);
  final ok = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Text(l.confirm),
      content: Text(message ?? l.confirmDestructive),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(l.cancel)),
        FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(l.confirm)),
      ],
    ),
  );
  if (ok == true) await action();
}

void showSnack(BuildContext context, String message) {
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
}

void showApiError(BuildContext context, Object error) {
  final message = error is ApiException ? error.message : error.toString();
  showSnack(context, message);
}

class DocumentHeader extends StatelessWidget {
  const DocumentHeader({
    super.key,
    required this.number,
    this.documentStatus,
    this.outcome,
    this.deliveryStatus,
    this.paymentStatus,
    this.customer,
  });

  final String number;
  final String? documentStatus;
  final String? outcome;
  final String? deliveryStatus;
  final String? paymentStatus;
  final String? customer;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return Container(
      decoration: FinanceTokens.card(context),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(number, style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
            if (customer != null) Text(customer!),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                if (documentStatus != null) StatusChip(label: '${l.documentStatus}: $documentStatus', tone: toneFor(documentStatus)),
                if (outcome != null) StatusChip(label: '${l.outcome}: $outcome', tone: toneFor(outcome)),
                if (deliveryStatus != null) StatusChip(label: '${l.deliveryStatus}: $deliveryStatus', tone: toneFor(deliveryStatus)),
                if (paymentStatus != null) StatusChip(label: '${l.paymentStatus}: $paymentStatus', tone: toneFor(paymentStatus)),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class TotalsCard extends StatelessWidget {
  const TotalsCard({
    super.key,
    required this.subtotal,
    required this.tax,
    required this.total,
    this.discount,
    this.taxable,
    this.paid,
    this.due,
    this.credited,
    this.debited,
    this.currency = 'ر.س',
  });

  final String subtotal;
  final String tax;
  final String total;
  final String? discount;
  final String? taxable;
  final String? paid;
  final String? due;
  final String? credited;
  final String? debited;
  final String currency;

  bool _has(String? value) => value != null && value.isNotEmpty && value != '0.00' && value != '0';

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    Widget row(String k, String v) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(children: [Expanded(child: Text(k)), Text('$v $currency', style: const TextStyle(fontWeight: FontWeight.w700))]),
        );
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            row(l.subtotal, subtotal),
            if (_has(discount)) row(l.discount, discount!),
            if (_has(taxable)) row(l.taxableAmount, taxable!),
            row(l.tax, tax),
            row(l.total, total),
            if (paid != null) row(l.paid, paid!),
            if (due != null) row(l.due, due!),
            if (_has(credited)) row(l.amountCredited, credited!),
            if (_has(debited)) row(l.amountDebited, debited!),
          ],
        ),
      ),
    );
  }
}

class InfoRow extends StatelessWidget {
  const InfoRow({super.key, required this.label, this.value});

  final String label;
  final String? value;

  @override
  Widget build(BuildContext context) {
    final text = value?.trim() ?? '';
    if (text.isEmpty) return const SizedBox.shrink();
    return ListTile(
      dense: true,
      title: Text(label),
      subtitle: Text(text),
    );
  }
}

class LineTable extends StatelessWidget {
  const LineTable({super.key, required this.lines});
  final List<LineItem> lines;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final wide = MediaQuery.sizeOf(context).width >= 800;
    if (!wide) {
      return Column(
        children: [
          for (final line in lines)
            Card(
              child: ListTile(
                title: Text(line.productName ?? line.description ?? ''),
                subtitle: Text(
                  [
                    '${l.quantity}: ${line.quantity}',
                    if ((line.unit ?? '').isNotEmpty) '${l.unit}: ${line.unit}',
                    '${l.price}: ${line.unitPrice}',
                    if ((line.discount ?? '0.00') != '0.00') '${l.discount}: ${line.discount}',
                    if ((line.taxRate ?? '0.00') != '0.00') '${l.taxRate}: ${line.taxRate}',
                  ].join(' · '),
                ),
                trailing: Text(line.total ?? ''),
              ),
            ),
        ],
      );
    }
    return Card(
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: DataTable(
          columns: [
            DataColumn(label: Text(l.description)),
            DataColumn(label: Text(l.unit)),
            DataColumn(label: Text(l.quantity)),
            DataColumn(label: Text(l.price)),
            DataColumn(label: Text(l.discount)),
            DataColumn(label: Text(l.taxRate)),
            DataColumn(label: Text(l.tax)),
            DataColumn(label: Text(l.total)),
          ],
          rows: [
            for (final line in lines)
              DataRow(cells: [
                DataCell(Text(line.productName ?? line.description ?? '')),
                DataCell(Text(line.unit ?? '')),
                DataCell(Text(line.quantity ?? '')),
                DataCell(Text(line.unitPrice ?? '')),
                DataCell(Text(line.discount ?? '')),
                DataCell(Text(line.taxRate ?? '')),
                DataCell(Text(line.taxAmount ?? '')),
                DataCell(Text(line.total ?? '')),
              ]),
          ],
        ),
      ),
    );
  }
}

class DeliveryTimeline extends StatelessWidget {
  const DeliveryTimeline({super.key, required this.deliveries});
  final List<DeliveryRecord> deliveries;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    if (deliveries.isEmpty) return const SizedBox.shrink();
    return Card(
      child: Column(
        children: [
          ListTile(title: Text(l.deliveryStatus)),
          for (final d in deliveries)
            ListTile(
              leading: StatusChip(label: d.status ?? '', tone: toneFor(d.status)),
              title: Text(d.recipient ?? ''),
              subtitle: Text('${d.channel ?? ''} · ${d.sentAt ?? ''}'),
            ),
        ],
      ),
    );
  }
}

Future<void> promptEmailAndSend(BuildContext context, Future<void> Function(String email) send) async {
  final l = AppLocalizations.of(context);
  final controller = TextEditingController();
  final ok = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Text(l.send),
      content: TextField(controller: controller, decoration: InputDecoration(labelText: l.email)),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(l.cancel)),
        FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(l.send)),
      ],
    ),
  );
  if (ok == true && controller.text.trim().isNotEmpty) {
    await send(controller.text.trim());
  }
}

void goDetail(BuildContext context, String path) => context.push(path);

class JsonView extends StatelessWidget {
  const JsonView(this.data, {super.key});

  final Object? data;

  @override
  Widget build(BuildContext context) {
    return _node(context, data);
  }

  Widget _node(BuildContext context, Object? value, {String? label}) {
    if (value is Map) {
      return ExpansionTile(
        title: Text(label ?? 'object', style: const TextStyle(fontWeight: FontWeight.w700)),
        children: [
          for (final entry in value.entries)
            _node(context, entry.value, label: entry.key.toString()),
        ],
      );
    }
    if (value is List) {
      return ExpansionTile(
        title: Text('${label ?? 'list'} (${value.length})'),
        children: [
          for (var i = 0; i < value.length; i++) _node(context, value[i], label: '#$i'),
        ],
      );
    }
    return ListTile(
      dense: true,
      title: Text(label ?? ''),
      trailing: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 240),
        child: Text('${value ?? ''}', textAlign: TextAlign.end),
      ),
    );
  }
}
