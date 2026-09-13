import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/layout/finance_layout.dart';
import 'package:hasim_finance/core/providers/catalog_provider.dart';
import 'package:hasim_finance/core/utils/files.dart';
import 'package:hasim_finance/features/shared/customer_select.dart';
import 'package:hasim_finance/features/shared/document_lines_editor.dart';
import 'package:hasim_finance/features/shared/paged.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class QuotesScreen extends ConsumerStatefulWidget {
  const QuotesScreen({super.key});

  @override
  ConsumerState<QuotesScreen> createState() => _QuotesScreenState();
}

class _QuotesScreenState extends ConsumerState<QuotesScreen> {
  String? _status;
  String? _outcome;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    return PagedListScreen<QuoteRecord>(
      filterKey: '$_status-$_outcome',
      filtersActive: _status != null || _outcome != null,
      title: l.quotes,
      allowed: auth.permissions.quotesView,
      onCreate: auth.permissions.quotesCreate ? () => context.push('/quotes/new') : null,
      filterBar: Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final option in <(String?, String)>[
            (null, l.filterAll),
            ('draft', l.draft),
            ('issued', l.issue),
            ('cancelled', l.cancelled),
          ])
            ChoiceChip(
              label: Text(option.$2),
              selected: _status == option.$1,
              onSelected: (_) => setState(() => _status = option.$1),
            ),
          for (final option in <(String, String)>[
            ('pending', l.pending),
            ('accepted', l.accepted),
            ('rejected', l.rejected),
            ('converted', l.converted),
          ])
            ChoiceChip(
              label: Text(option.$2),
              selected: _outcome == option.$1,
              onSelected: (_) => setState(() => _outcome = _outcome == option.$1 ? null : option.$1),
            ),
        ],
      ),
      loader: (api, search, page) => api.quotes(search: search, page: page, status: _status, outcome: _outcome),
      itemBuilder: (context, quote) => Card(
        child: ListTile(
          title: Text(quote.quoteNumber ?? '#${quote.id}'),
          subtitle: Text('${quote.customerName ?? ''} · ${quote.documentStatus} · ${quote.outcome} · ${quote.deliveryStatus ?? '-'}'),
          trailing: Text(quote.total),
          onTap: () => context.push('/quotes/${quote.id}'),
        ),
      ),
    );
  }
}

class QuoteDetailScreen extends ConsumerStatefulWidget {
  const QuoteDetailScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<QuoteDetailScreen> createState() => _QuoteDetailScreenState();
}

class _QuoteDetailScreenState extends ConsumerState<QuoteDetailScreen> {
  QuoteRecord? _data;
  bool _loading = true;
  String? _error;

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ref.read(financeApiProvider).quote(widget.id);
      if (!mounted) return;
      setState(() {
        _data = data;
        _loading = false;
      });
    } on ApiException catch (e) {
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

  Future<void> _act(String action, {Map<String, dynamic>? body}) async {
    try {
      final updated = await ref.read(financeApiProvider).quoteAction(widget.id, action, body: body);
      await _load();
      if (!mounted) return;
      showSnack(context, AppLocalizations.of(context).success);
      if (action == 'convert' && updated.convertedInvoiceId != null) {
        context.go('/invoices/${updated.convertedInvoiceId}');
      }
    } catch (e) {
      if (mounted) showApiError(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final p = ref.watch(authControllerProvider).permissions;
    final q = _data;
    return DetailScaffold(
      title: q?.quoteNumber ?? l.quotes,
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: q == null
          ? const SizedBox.shrink()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                DocumentHeader(
                  number: q.quoteNumber ?? '#${q.id}',
                  documentStatus: q.documentStatus,
                  outcome: q.outcome,
                  deliveryStatus: q.deliveryStatus,
                  customer: q.customerName,
                ),
                const SizedBox(height: 12),
                TotalsCard(
                  subtotal: q.subtotal,
                  discount: q.discount,
                  taxable: q.taxableAmount,
                  tax: q.taxAmount,
                  total: q.total,
                ),
                InfoRow(label: l.issueDate, value: q.issueDate),
                InfoRow(label: l.expiryDate, value: q.expiryDate),
                InfoRow(label: l.notesField, value: q.notes),
                InfoRow(label: l.terms, value: q.terms),
                InfoRow(label: l.rejectionReason, value: q.rejectionReason),
                const SizedBox(height: 12),
                LineTable(lines: q.lines),
                DeliveryTimeline(deliveries: q.deliveries),
                const SizedBox(height: 12),
                Text(l.attachmentsTitle, style: const TextStyle(fontWeight: FontWeight.w800)),
                if (q.attachments.isEmpty) Text(l.noAttachments),
                for (final row in q.attachments)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text('${row['file_name'] ?? row['id']}'),
                    trailing: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        IconButton(
                          tooltip: l.download,
                          icon: const Icon(Icons.download_outlined),
                          onPressed: () async {
                            try {
                              final id = int.parse('${row['id']}');
                              final bytes = await ref.read(financeApiProvider).downloadQuoteAttachment(q.id, id);
                              await saveAndOpenBytes(bytes, '${row['file_name'] ?? 'quote-$id'}');
                            } catch (e) {
                              if (context.mounted) showApiError(context, e);
                            }
                          },
                        ),
                        if (q.documentStatus != 'cancelled' && p.can('quotes.edit'))
                          IconButton(
                            tooltip: l.delete,
                            icon: const Icon(Icons.delete_outline),
                            onPressed: () => confirmAndRun(context, () async {
                              await ref.read(financeApiProvider).deleteQuoteAttachment(q.id, int.parse('${row['id']}'));
                              await _load();
                            }),
                          ),
                      ],
                    ),
                  ),
                if (q.documentStatus != 'cancelled' && p.can('quotes.edit'))
                  OutlinedButton.icon(
                    onPressed: () async {
                      final result = await FilePicker.platform.pickFiles(
                        allowMultiple: true,
                        type: FileType.custom,
                        allowedExtensions: const ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
                      );
                      if (result == null || result.files.isEmpty) return;
                      try {
                        final form = FormData();
                        for (final file in result.files) {
                          final part = await multipartFromPicked(file);
                          if (part != null) form.files.add(MapEntry('attachments[]', part));
                        }
                        if (form.files.isEmpty) return;
                        await ref.read(financeApiProvider).uploadQuoteAttachments(q.id, form);
                        await _load();
                      } catch (e) {
                        if (context.mounted) showApiError(context, e);
                      }
                    },
                    icon: const Icon(Icons.upload_file_outlined),
                    label: Text(l.pickAttachments),
                  ),
                const SizedBox(height: 16),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  if (q.documentStatus == 'draft' && p.can('quotes.edit'))
                    FilledButton(onPressed: () => context.push('/quotes/${q.id}/edit'), child: Text(l.edit)),
                  if (q.documentStatus == 'draft' && p.can('quotes.issue'))
                    FilledButton(onPressed: () => confirmAndRun(context, () => _act('issue')), child: Text(l.issue)),
                  if (q.documentStatus == 'issued' && p.can('quotes.send'))
                    FilledButton(onPressed: () => promptEmailAndSend(context, (email) => _act('send', body: {'email': email})), child: Text(l.send)),
                  if (q.documentStatus == 'issued' && q.outcome == 'pending' && p.can('quotes.accept'))
                    FilledButton(onPressed: () => _act('accept'), child: Text(l.accept)),
                  if (q.documentStatus == 'issued' && q.outcome == 'pending' && p.can('quotes.reject'))
                    FilledButton(onPressed: () => _act('reject'), child: Text(l.reject)),
                  if (q.outcome == 'accepted' && p.can('quotes.convert'))
                    FilledButton(onPressed: () => _act('convert'), child: Text(l.convert)),
                  if (q.documentStatus != 'cancelled' && p.can('quotes.cancel'))
                    OutlinedButton(onPressed: () => confirmAndRun(context, () => _act('cancel')), child: Text(l.cancel)),
                  if (q.documentStatus == 'draft' && p.quotesDelete)
                    OutlinedButton(
                      onPressed: () => confirmAndRun(context, () async {
                        await ref.read(financeApiProvider).deleteQuote(q.id);
                        if (context.mounted) context.go('/quotes');
                      }),
                      child: Text(l.deleteDraft),
                    ),
                  FilledButton.tonal(
                    onPressed: () async {
                      try {
                        final bytes = await ref.read(financeApiProvider).pdf('quotes/${q.id}/pdf');
                        await saveAndOpenBytes(bytes, 'quote-${q.quoteNumber}.pdf');
                      } catch (e) {
                        if (context.mounted) showApiError(context, e);
                      }
                    },
                    child: Text(l.pdf),
                  ),
                  if (q.convertedInvoiceId != null)
                    TextButton(onPressed: () => context.push('/invoices/${q.convertedInvoiceId}'), child: Text(q.convertedInvoiceNumber ?? l.invoices)),
                ]),
              ],
            ),
    );
  }
}

class QuoteFormScreen extends ConsumerStatefulWidget {
  const QuoteFormScreen({super.key, this.id});
  final int? id;

  @override
  ConsumerState<QuoteFormScreen> createState() => _QuoteFormScreenState();
}

class _QuoteFormScreenState extends ConsumerState<QuoteFormScreen> {
  int? _customerId;
  String _taxProfile = 'standard';
  String _taxMode = 'exclusive';
  final _issueDate = TextEditingController(text: isoDate());
  final _expiryDate = TextEditingController(text: isoDate(DateTime.now().add(const Duration(days: 30))));
  final _currency = TextEditingController(text: 'SAR');
  final _taxRate = TextEditingController(text: '15');
  final _notes = TextEditingController();
  final _terms = TextEditingController();
  final List<LineDraft> _lines = [LineDraft(description: 'خدمة', unitPrice: '100')];
  final List<PlatformFile> _files = [];
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    if (widget.id != null) {
      ref.read(financeApiProvider).quote(widget.id!).then((q) {
        if (!mounted) return;
        _customerId = q.customerId;
        _notes.text = q.notes ?? '';
        _terms.text = q.terms ?? '';
        _currency.text = q.currency;
        _taxRate.text = q.taxRate ?? '15';
        _taxProfile = q.taxProfileType ?? 'standard';
        if (q.issueDate != null) _issueDate.text = q.issueDate!.substring(0, 10);
        if (q.expiryDate != null) _expiryDate.text = q.expiryDate!.substring(0, 10);
        if (q.lines.isNotEmpty) {
          for (final line in _lines) {
            line.dispose();
          }
          _lines
            ..clear()
            ..addAll(q.lines.map(LineDraft.fromItem));
        }
        setState(() {});
      }).catchError((Object error) {
        if (mounted) showApiError(context, error);
      });
    }
  }

  @override
  void dispose() {
    _issueDate.dispose();
    _expiryDate.dispose();
    _currency.dispose();
    _taxRate.dispose();
    _notes.dispose();
    _terms.dispose();
    for (final line in _lines) {
      line.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (_customerId == null) return;
    setState(() => _busy = true);
    try {
      final saved = await ref.read(financeApiProvider).saveQuote({
        'customer_id': _customerId,
        'issue_date': _issueDate.text.trim(),
        'expiry_date': _expiryDate.text.trim(),
        'currency': _currency.text.trim(),
        'notes': _notes.text.trim(),
        'terms': _terms.text.trim(),
        'tax_profile_type': _taxProfile,
        'tax_rate': _taxRate.text.trim(),
        'tax_price_mode': _taxMode,
        'items': _lines.map((line) => line.toPayload()).toList(),
      }, id: widget.id);
      if (_files.isNotEmpty) {
        final form = FormData();
        for (final file in _files) {
          final part = await multipartFromPicked(file);
          if (part != null) form.files.add(MapEntry('attachments[]', part));
        }
        if (form.files.isNotEmpty) {
          await ref.read(financeApiProvider).uploadQuoteAttachments(saved.id, form);
        }
      }
      if (!mounted) return;
      context.go('/quotes/${saved.id}');
    } catch (e) {
      if (mounted) showFormError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final catalog = ref.watch(financeCatalogProvider).valueOrNull ?? const FinanceCatalog();
    return FinanceScaffold(
        title: l.quotes,
        showBack: true,
        body: FinancePage(
        child: ListView(
        padding: EdgeInsets.zero,
        children: [
          FormSection(
            title: l.headerSection,
            child: CustomerSelectField(selectedId: _customerId, onSelected: (id) => setState(() => _customerId = id)),
          ),
          FormSection(
            title: l.datesSection,
            child: FormGrid(children: [
              TextField(controller: _issueDate, decoration: InputDecoration(labelText: l.issueDate)),
              TextField(controller: _expiryDate, decoration: InputDecoration(labelText: l.expiryDate)),
              TextField(controller: _currency, decoration: InputDecoration(labelText: l.currency)),
            ]),
          ),
          FormSection(
            title: l.taxSection,
            child: FormGrid(children: [
              DropdownButtonFormField(
                isExpanded: true,
                // ignore: deprecated_member_use
                value: _taxProfile,
                decoration: InputDecoration(labelText: l.taxProfile),
                items: [
                  DropdownMenuItem(value: 'standard', child: Text(l.standardTax)),
                  DropdownMenuItem(value: 'zero_rated', child: Text(l.zeroRated)),
                  DropdownMenuItem(value: 'exempt', child: Text(l.exempt)),
                  DropdownMenuItem(value: 'out_of_scope', child: Text(l.outOfScope)),
                ],
                onChanged: (v) => setState(() => _taxProfile = v ?? 'standard'),
              ),
              DropdownButtonFormField(
                isExpanded: true,
                // ignore: deprecated_member_use
                value: _taxMode,
                decoration: InputDecoration(labelText: l.taxPriceMode),
                items: [
                  DropdownMenuItem(value: 'exclusive', child: Text(l.exclusive)),
                  DropdownMenuItem(value: 'inclusive', child: Text(l.inclusive)),
                ],
                onChanged: (v) => setState(() => _taxMode = v ?? 'exclusive'),
              ),
              TextField(controller: _taxRate, decoration: InputDecoration(labelText: l.taxRate)),
            ]),
          ),
          FormSection(
            title: l.itemsSection,
            child: DocumentLinesEditor(lines: _lines, products: catalog.products, onChanged: () => setState(() {})),
          ),
          FormSection(
            title: l.notesSection,
            child: Column(
              children: [
                TextField(controller: _notes, decoration: InputDecoration(labelText: l.notesField), maxLines: 3),
                const SizedBox(height: 8),
                TextField(controller: _terms, decoration: InputDecoration(labelText: l.terms), maxLines: 3),
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  onPressed: () async {
                    final result = await FilePicker.platform.pickFiles(
                      allowMultiple: true,
                      type: FileType.custom,
                      allowedExtensions: const ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
                    );
                    if (result != null && result.files.isNotEmpty) {
                      setState(() => _files
                        ..clear()
                        ..addAll(result.files));
                    }
                  },
                  icon: const Icon(Icons.attach_file),
                  label: Text(_files.isEmpty ? l.pickAttachments : '${l.attachmentsTitle} (${_files.length})'),
                ),
              ],
            ),
          ),
          FilledButton(onPressed: _busy ? null : _save, child: Text(l.save)),
        ],
      ),
      ),
    );
  }
}
