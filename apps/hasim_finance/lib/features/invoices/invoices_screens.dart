import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/permissions/finance_permissions.dart';
import 'package:hasim_finance/core/layout/finance_layout.dart';
import 'package:hasim_finance/core/widgets/date_field.dart';
import 'package:hasim_finance/core/providers/catalog_provider.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';
import 'package:hasim_finance/core/utils/files.dart';
import 'package:hasim_finance/core/widgets/widgets.dart';
import 'package:hasim_finance/features/invoices/invoice_detail_widgets.dart';
import 'package:hasim_finance/features/shared/customer_select.dart';
import 'package:hasim_finance/features/shared/document_lines_editor.dart';
import 'package:hasim_finance/features/shared/paged.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class InvoicesScreen extends ConsumerStatefulWidget {
  const InvoicesScreen({super.key});

  @override
  ConsumerState<InvoicesScreen> createState() => _InvoicesScreenState();
}

class _InvoicesScreenState extends ConsumerState<InvoicesScreen> {
  String? _lifecycle;
  String? _invoiceStatus;
  String? _paymentStatus;
  int? _customerId;
  final _from = TextEditingController();
  final _to = TextEditingController();
  final _currency = TextEditingController();
  int? _projectId;
  int? _contractId;
  String? _paymentMethod;
  String _sort = 'id';
  String _direction = 'desc';
  bool? _advancedOpen;
  int _applyTick = 0;

  @override
  void dispose() {
    _from.dispose();
    _to.dispose();
    _currency.dispose();
    super.dispose();
  }

  String get _filterKey => [
        _lifecycle,
        _invoiceStatus,
        _paymentStatus,
        _customerId,
        _from.text,
        _to.text,
        _currency.text.trim(),
        _projectId,
        _contractId,
        _paymentMethod,
        _sort,
        _direction,
        _applyTick,
      ].join('|');

  int get _activeFilterCount => [
        _invoiceStatus != null,
        _paymentStatus != null,
        _customerId != null,
        _from.text.isNotEmpty,
        _to.text.isNotEmpty,
        _currency.text.trim().isNotEmpty,
        _projectId != null,
        _contractId != null,
        _paymentMethod != null,
      ].where((active) => active).length;

  bool get _filtersActive => _lifecycle != null || _activeFilterCount > 0;

  void _resetFilters() {
    setState(() {
      _lifecycle = null;
      _invoiceStatus = null;
      _paymentStatus = null;
      _customerId = null;
      _from.clear();
      _to.clear();
      _currency.clear();
      _projectId = null;
      _contractId = null;
      _paymentMethod = null;
      _sort = 'id';
      _direction = 'desc';
    });
  }

  Widget _buildFilterPanel(BuildContext context, AppLocalizations l, FinanceCatalog catalog) {
    final wide = MediaQuery.sizeOf(context).width >= 900;
    final open = _advancedOpen ?? wide;
    final activeCount = _activeFilterCount;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            for (final option in <(String?, String)>[
              (null, l.filterAll),
              ('draft', l.lifecycleDraft),
              ('sent', l.lifecycleSent),
              ('partial', l.partial),
              ('paid', l.paid),
              ('overdue', l.overdue),
              ('cancelled', l.cancelled),
            ])
              ChoiceChip(
                label: Text(option.$2),
                selected: _lifecycle == option.$1,
                onSelected: (_) => setState(() => _lifecycle = option.$1),
              ),
          ],
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            TextButton.icon(
              key: const Key('invoices-advanced-toggle'),
              onPressed: () => setState(() => _advancedOpen = !open),
              icon: Icon(open ? Icons.expand_less_rounded : Icons.tune_rounded, size: 18),
              label: Text(activeCount > 0 ? '${l.advancedFilters} ($activeCount)' : l.advancedFilters),
            ),
            const Spacer(),
            if (_filtersActive)
              TextButton(
                key: const Key('invoices-reset-filters'),
                onPressed: _resetFilters,
                child: Text(l.resetFilters),
              ),
          ],
        ),
        if (open) ...[
          const SizedBox(height: 4),
          Wrap(
            spacing: FinanceFilterBar.gap,
            runSpacing: FinanceFilterBar.gap,
            crossAxisAlignment: WrapCrossAlignment.end,
            children: [
              FinanceFilterField(
                label: l.customer,
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
                label: l.invoiceStatusFilter,
                width: 170,
                child: FinanceFilterDropdown<String>(
                  value: _invoiceStatus,
                  items: [
                    DropdownMenuItem(value: null, child: Text(l.filterAll)),
                    DropdownMenuItem(value: 'draft', child: Text(l.draft)),
                    DropdownMenuItem(value: 'issued', child: Text(l.issue)),
                    DropdownMenuItem(value: 'cancelled', child: Text(l.cancelled)),
                  ],
                  onChanged: (value) => setState(() => _invoiceStatus = value),
                ),
              ),
              FinanceFilterField(
                label: l.paymentStatus,
                width: 170,
                child: FinanceFilterDropdown<String>(
                  value: _paymentStatus,
                  items: [
                    DropdownMenuItem(value: null, child: Text(l.filterAll)),
                    DropdownMenuItem(value: 'unpaid', child: Text(l.unpaid)),
                    DropdownMenuItem(value: 'partial', child: Text(l.partial)),
                    DropdownMenuItem(value: 'paid', child: Text(l.paid)),
                    DropdownMenuItem(value: 'overdue', child: Text(l.overdue)),
                  ],
                  onChanged: (value) => setState(() => _paymentStatus = value),
                ),
              ),
              FinanceFilterField(
                label: l.from,
                width: 170,
                child: DateField(controller: _from, compact: true, onChanged: (_) => setState(() {})),
              ),
              FinanceFilterField(
                label: l.to,
                width: 170,
                child: DateField(controller: _to, compact: true, onChanged: (_) => setState(() {})),
              ),
              FinanceFilterField(
                label: l.currency,
                width: 140,
                child: TextField(
                  controller: _currency,
                  textCapitalization: TextCapitalization.characters,
                  textInputAction: TextInputAction.search,
                  decoration: FinanceFilterField.decoration(hintText: 'SAR'),
                  onSubmitted: (_) => setState(() {}),
                ),
              ),
              FinanceFilterField(
                label: l.contract,
                width: 190,
                child: OptionPicker(
                  label: l.contract,
                  floatingLabel: false,
                  options: [for (final row in catalog.contracts) NamedOption(id: row.id, name: row.name)],
                  value: _contractId,
                  onChanged: (id) => setState(() => _contractId = id),
                ),
              ),
              FinanceFilterField(
                label: l.project,
                width: 190,
                child: OptionPicker(
                  label: l.project,
                  floatingLabel: false,
                  options: [for (final row in catalog.projects) NamedOption(id: row.id, name: row.name)],
                  value: _projectId,
                  onChanged: (id) => setState(() => _projectId = id),
                ),
              ),
              FinanceFilterField(
                label: l.paymentMethod,
                width: 170,
                child: FinanceFilterDropdown<String>(
                  value: _paymentMethod,
                  items: [
                    DropdownMenuItem(value: null, child: Text(l.filterAll)),
                    DropdownMenuItem(value: 'cash', child: Text(l.methodCash)),
                    DropdownMenuItem(value: 'bank_transfer', child: Text(l.methodBank)),
                    DropdownMenuItem(value: 'card', child: Text(l.methodCard)),
                    DropdownMenuItem(value: 'other', child: Text(l.methodOther)),
                  ],
                  onChanged: (value) => setState(() => _paymentMethod = value),
                ),
              ),
              FinanceFilterField(
                label: l.sortBy,
                width: 170,
                child: FinanceFilterDropdown<String>(
                  value: _sort,
                  items: [
                    DropdownMenuItem(value: 'id', child: Text(l.sortNewest)),
                    DropdownMenuItem(value: 'invoice_number', child: Text(l.invoiceNumber)),
                    DropdownMenuItem(value: 'issue_date', child: Text(l.issueDate)),
                    DropdownMenuItem(value: 'due_date', child: Text(l.dueDate)),
                    DropdownMenuItem(value: 'total', child: Text(l.total)),
                    DropdownMenuItem(value: 'amount_due', child: Text(l.due)),
                  ],
                  onChanged: (value) => setState(() => _sort = value ?? 'id'),
                ),
              ),
              FinanceFilterField(
                label: l.sortDirection,
                width: 140,
                child: FinanceFilterDropdown<String>(
                  value: _direction,
                  items: [
                    DropdownMenuItem(value: 'desc', child: Text(l.sortDescending)),
                    DropdownMenuItem(value: 'asc', child: Text(l.sortAscending)),
                  ],
                  onChanged: (value) => setState(() => _direction = value ?? 'desc'),
                ),
              ),
              FilledButton.icon(
                key: const Key('invoices-apply-filters'),
                onPressed: () => setState(() => _applyTick++),
                icon: const Icon(Icons.filter_alt_rounded, size: 16),
                label: Text(l.applyFilters),
              ),
            ],
          ),
        ],
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    final catalog = ref.watch(financeCatalogProvider).valueOrNull ?? const FinanceCatalog();
    return PagedListScreen<InvoiceRecord>(
      title: l.invoices,
      allowed: auth.permissions.invoicesView,
      onCreate: auth.permissions.invoicesCreate ? () => context.push('/invoices/new') : null,
      filterKey: _filterKey,
      filtersActive: _filtersActive,
      emptyTitle: l.noInvoicesYet,
      emptySubtitle: l.noInvoicesSubtitle,
      emptyIcon: Icons.receipt_long_outlined,
      emptyActionLabel: l.newInvoice,
      filterBar: _buildFilterPanel(context, l, catalog),
      loader: (api, search, page) => api.invoices(
        search: search,
        page: page,
        lifecycle: _lifecycle,
        invoiceStatus: _invoiceStatus,
        paymentStatus: _paymentStatus,
        customerId: _customerId,
        from: _from.text.trim(),
        to: _to.text.trim(),
        currency: _currency.text.trim(),
        projectId: _projectId,
        contractId: _contractId,
        paymentMethod: _paymentMethod,
        sort: _sort,
        direction: _direction,
      ),
      summaryBuilder: (context, meta) => _InvoicePipelineSummary(meta: meta),
      itemBuilder: (context, invoice) => InkWell(
        onTap: () => context.push('/invoices/${invoice.id}'),
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: FinanceTokens.card(context),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Wrap(
                      spacing: 8,
                      runSpacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text(invoice.invoiceNumber ?? '#${invoice.id}', style: const TextStyle(fontWeight: FontWeight.w800)),
                        StatusChip(label: documentStatusLabel(invoice.documentStatus, l), tone: toneFor(invoice.documentStatus)),
                        StatusChip(label: paymentStatusLabel(invoice.paymentStatus, l), tone: toneFor(invoice.paymentStatus)),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Text(
                      [
                        invoice.customerName ?? '',
                        invoice.issueDate ?? '',
                        invoice.currency,
                      ].where((v) => v.isNotEmpty).join(' · '),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(color: context.hs.textMuted, fontSize: 12),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(invoice.total, style: const TextStyle(fontWeight: FontWeight.w800)),
                  Text('${l.due} ${invoice.amountDue}', style: TextStyle(color: context.hs.textMuted, fontSize: 11)),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class InvoiceDetailScreen extends ConsumerStatefulWidget {
  const InvoiceDetailScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<InvoiceDetailScreen> createState() => _InvoiceDetailScreenState();
}

class _InvoiceDetailScreenState extends ConsumerState<InvoiceDetailScreen> {
  InvoiceRecord? _data;
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
      final data = await ref.read(financeApiProvider).invoice(widget.id);
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

  Future<void> _act(String action, {Map<String, dynamic>? body}) async {
    try {
      await ref.read(financeApiProvider).invoiceAction(widget.id, action, body: body);
      await _load();
      if (mounted) showSnack(context, AppLocalizations.of(context).success);
    } catch (e) {
      if (mounted) showApiError(context, e);
    }
  }

  Future<void> _checkout() async {
    final l = AppLocalizations.of(context);
    try {
      final info = await ref.read(financeApiProvider).checkout(widget.id);
      await _load();
      if (!mounted) return;
      if (!info.hasUrl) {
        showSnack(context, info.message?.isNotEmpty == true ? info.message! : l.checkoutUnavailable);
        return;
      }
      await showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(l.checkout),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(l.paymentLinkHint),
              const SizedBox(height: 8),
              SelectableText(info.checkoutUrl!),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () async {
                await copyText(info.checkoutUrl!);
                if (ctx.mounted) Navigator.pop(ctx);
                if (mounted) showSnack(context, l.copied);
              },
              child: Text(l.copy),
            ),
            FilledButton(
              onPressed: () async {
                await openExternalUrl(info.checkoutUrl!);
                if (ctx.mounted) Navigator.pop(ctx);
                await _load();
              },
              child: Text(l.open),
            ),
          ],
        ),
      );
      await _load();
    } catch (e) {
      if (mounted) showApiError(context, e);
    }
  }

  Future<void> _manualPayment() async {
    final l = AppLocalizations.of(context);
    final amount = TextEditingController(text: _data?.amountDue ?? '');
    final reference = TextEditingController();
    String method = 'cash';
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(l.recordPayment),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(controller: amount, decoration: InputDecoration(labelText: l.amount)),
            TextField(controller: reference, decoration: InputDecoration(labelText: l.reference)),
            DropdownButtonFormField(
              isExpanded: true,
              // ignore: deprecated_member_use
              value: method,
              items: [
                DropdownMenuItem(value: 'cash', child: Text(l.methodCash)),
                DropdownMenuItem(value: 'bank_transfer', child: Text(l.methodBank)),
                DropdownMenuItem(value: 'card', child: Text(l.methodCard)),
                DropdownMenuItem(value: 'other', child: Text(l.methodOther)),
              ],
              onChanged: (v) => method = v ?? 'cash',
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(l.cancel)),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(l.save)),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await ref.read(financeApiProvider).recordPayment(widget.id, {
        'amount': amount.text.trim(),
        'method': method,
        'reference': reference.text.trim(),
        'payment_date': DateTime.now().toIso8601String().substring(0, 10),
      });
      await _load();
      if (mounted) showSnack(context, l.success);
    } catch (e) {
      if (mounted) showApiError(context, e);
    }
  }

  Future<void> _openPdf() async {
    final invoice = _data;
    if (invoice == null) return;
    try {
      final bytes = await ref.read(financeApiProvider).pdf('sales-invoices/${invoice.id}/pdf');
      await saveAndOpenBytes(bytes, 'invoice-${invoice.invoiceNumber}.pdf');
    } catch (e) {
      if (mounted) showApiError(context, e);
    }
  }

  Future<void> _openXml() async {
    final invoice = _data;
    if (invoice == null) return;
    try {
      final payload = await ref.read(financeApiProvider).invoiceXml(invoice.id);
      final xml = payload['xml']?.toString() ?? '';
      await saveAndOpenBytes(utf8.encode(xml), 'invoice-${invoice.invoiceNumber}.xml');
    } catch (e) {
      if (mounted) showApiError(context, e);
    }
  }

  Future<void> _openQr() async {
    final invoice = _data;
    if (invoice == null) return;
    try {
      final payload = await ref.read(financeApiProvider).invoiceQr(invoice.id);
      if (!mounted) return;
      final tags = payload['tags'] is Map ? Map<String, dynamic>.from(payload['tags'] as Map) : const <String, dynamic>{};
      await showDialog<void>(
        context: context,
        builder: (ctx) {
          final l = AppLocalizations.of(ctx);
          return AlertDialog(
            title: Text(l.viewQr),
            content: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(l.zatcaFoundation, style: Theme.of(ctx).textTheme.bodySmall),
                  const SizedBox(height: 12),
                  Text('${tags['seller_name'] ?? ''}'),
                  Text('${tags['seller_vat'] ?? ''}'),
                  Text('${tags['timestamp'] ?? ''}'),
                  Text('${tags['total_with_vat'] ?? ''}'),
                  const SizedBox(height: 8),
                  SelectableText(payload['qr_base64']?.toString() ?? '', style: const TextStyle(fontSize: 11)),
                ],
              ),
            ),
            actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: Text(l.goBack))],
          );
        },
      );
    } catch (e) {
      if (mounted) showApiError(context, e);
    }
  }

  Future<void> _pickAttachments() async {
    final invoice = _data;
    if (invoice == null) return;
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
        if (part == null) continue;
        form.files.add(MapEntry('attachments[]', part));
      }
      if (form.files.isEmpty) return;
      await ref.read(financeApiProvider).uploadInvoiceAttachments(invoice.id, form);
      await _load();
    } catch (e) {
      if (mounted) showApiError(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final p = ref.watch(authControllerProvider).permissions;
    final invoice = _data;
    return Scaffold(
      backgroundColor: context.hs.canvas,
      body: AsyncBody(
        loading: _loading,
        error: _error,
        onRetry: _load,
        child: invoice == null
            ? const SizedBox.shrink()
            : FinancePage(
                padding: const EdgeInsets.fromLTRB(20, 10, 20, 24),
                child: ListView(
                  children: [
                    InvoiceHeader(
                      invoice: invoice,
                      actions: _toolbar(context, l, p, invoice),
                    ),
                    const SizedBox(height: 14),
                    InvoiceTwoColumn(
                      leading: CustomerInfoCard(
                        invoice: invoice,
                        onOpenCustomer: invoice.customerId == null ? null : () => context.push('/customers/${invoice.customerId}'),
                      ),
                      trailing: InvoiceSummaryCard(invoice: invoice),
                    ),
                    const SizedBox(height: 12),
                    InvoiceItemsTable(invoice: invoice),
                    if (invoice.deliveries.isNotEmpty) ...[
                      const SizedBox(height: 12),
                      DeliveryTimeline(deliveries: invoice.deliveries),
                    ],
                    const SizedBox(height: 12),
                    _lowerGrid(context, l, p, invoice),
                    if (invoice.creditNotes.isNotEmpty || invoice.receipts.isNotEmpty) ...[
                      const SizedBox(height: 12),
                      _relatedDocuments(context, l, invoice),
                    ],
                    const SizedBox(height: 12),
                    AuditLogCard(invoice: invoice),
                    if (invoice.journalEntries.isNotEmpty) ...[
                      const SizedBox(height: 12),
                      JournalEntriesCard(invoice: invoice),
                    ],
                  ],
                ),
              ),
      ),
    );
  }

  Widget _lowerGrid(BuildContext context, AppLocalizations _, FinancePermissions p, InvoiceRecord invoice) {
    final attachments = AttachmentsCard(
      invoice: invoice,
      onUpload: invoice.documentStatus == 'draft' && p.can('invoices.edit') ? _pickAttachments : null,
      onDownload: (row) async {
        try {
          final id = int.parse('${row['id']}');
          final bytes = await ref.read(financeApiProvider).downloadInvoiceAttachment(invoice.id, id);
          await saveAndOpenBytes(bytes, '${row['file_name'] ?? 'attachment-$id'}');
        } catch (e) {
          if (context.mounted) showApiError(context, e);
        }
      },
      onDelete: invoice.documentStatus == 'draft' && p.can('invoices.edit')
          ? (row) async {
              try {
                await ref.read(financeApiProvider).deleteInvoiceAttachment(invoice.id, int.parse('${row['id']}'));
                await _load();
              } catch (e) {
                if (context.mounted) showApiError(context, e);
              }
            }
          : null,
    );
    final notes = NotesTermsCard(invoice: invoice);
    final payment = PaymentStatusCard(
      invoice: invoice,
      onRecordPayment: invoice.documentStatus == 'issued' && invoice.paymentStatus != 'paid' && p.paymentsManage ? _manualPayment : null,
      onOpenPayment: p.paymentsView ? (payment) => context.push('/payments/${payment.id}') : null,
      onReversePayment: p.can('invoices.reverse_payment')
          ? (payment) => confirmAndRun(context, () async {
                await ref.read(financeApiProvider).reverseInvoicePayment(invoice.id, payment.id);
                await _load();
              })
          : null,
    );
    final wide = MediaQuery.sizeOf(context).width >= 1100;
    if (!wide) {
      return Column(
        children: [
          InvoiceTotalsCard(invoice: invoice),
          const SizedBox(height: 12),
          payment,
          const SizedBox(height: 12),
          notes,
          const SizedBox(height: 12),
          attachments,
        ],
      );
    }
    return Column(
      children: [
        InvoiceTwoColumn(
          leading: InvoiceTotalsCard(invoice: invoice),
          trailing: payment,
        ),
        const SizedBox(height: 12),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(child: attachments),
            const SizedBox(width: 12),
            Expanded(child: notes),
          ],
        ),
      ],
    );
  }

  Widget _relatedDocuments(BuildContext context, AppLocalizations l, InvoiceRecord invoice) {
    return InvoiceSectionCard(
      title: l.relatedDocuments,
      icon: Icons.account_tree_outlined,
      child: Column(
        children: [
          for (final note in invoice.creditNotes)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text(note.noteNumber ?? '#${note.id}'),
              subtitle: Text('${note.type} · ${note.status}'),
              trailing: Text(note.total, style: const TextStyle(fontWeight: FontWeight.w800)),
              onTap: () => context.push('/notes/${note.id}'),
            ),
          for (final receipt in invoice.receipts)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text(receipt.receiptNumber ?? '#${receipt.id}'),
              trailing: Text(receipt.amount, style: const TextStyle(fontWeight: FontWeight.w800)),
              onTap: () => context.push('/receipts/${receipt.id}'),
            ),
        ],
      ),
    );
  }

  Widget _toolbar(BuildContext context, AppLocalizations l, FinancePermissions p, InvoiceRecord invoice) {
    final moreItems = <PopupMenuEntry<String>>[
      if (invoice.documentStatus == 'draft' && p.can('invoices.edit')) PopupMenuItem(value: 'edit', child: Text(l.edit)),
      if (invoice.documentStatus == 'issued' && p.can('invoices.remind')) PopupMenuItem(value: 'remind', child: Text(l.remind)),
      if (invoice.documentStatus == 'issued' && invoice.paymentStatus != 'paid' && p.paymentsManage)
        PopupMenuItem(value: 'checkout', child: Text(l.createCheckout)),
      if (invoice.checkout?.hasUrl == true) PopupMenuItem(value: 'copy', child: Text(l.copy)),
      if (invoice.checkout?.hasUrl == true) PopupMenuItem(value: 'open', child: Text(l.open)),
      if (invoice.documentStatus == 'issued' && p.notesCreate) PopupMenuItem(value: 'credit', child: Text(l.creditNoteFromInvoice)),
      if (invoice.documentStatus != 'cancelled' && p.can('invoices.cancel')) PopupMenuItem(value: 'cancel', child: Text(l.cancel)),
      if (invoice.documentStatus == 'draft' && p.invoicesDelete) PopupMenuItem(value: 'delete', child: Text(l.deleteDraft)),
    ];
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      alignment: WrapAlignment.end,
      children: [
        InvoiceActionButton(
          label: l.goBack,
          icon: Icons.arrow_forward_rounded,
          onPressed: () {
            if (Navigator.of(context).canPop()) {
              Navigator.of(context).maybePop();
            } else {
              context.go('/invoices');
            }
          },
        ),
        if (invoice.documentStatus == 'draft' && p.can('invoices.issue'))
          InvoiceActionButton(label: l.issue, icon: Icons.check_circle_outline, filled: true, onPressed: () => confirmAndRun(context, () => _act('issue'))),
        if (invoice.documentStatus == 'issued' && p.can('invoices.send'))
          InvoiceActionButton(label: l.send, icon: Icons.send_outlined, filled: true, onPressed: () => promptEmailAndSend(context, (email) => _act('send', body: {'email': email}))),
        InvoiceActionButton(label: l.downloadPdf, icon: Icons.download_outlined, onPressed: _openPdf),
        if (invoice.zatcaXmlAvailable)
          InvoiceActionButton(label: l.downloadXml, icon: Icons.code_outlined, onPressed: _openXml),
        if (invoice.zatcaQrAvailable)
          InvoiceActionButton(label: l.viewQr, icon: Icons.qr_code_2_outlined, onPressed: _openQr),
        InvoiceActionButton(label: l.printDocument, icon: Icons.print_outlined, onPressed: _openPdf),
        if (invoice.documentStatus == 'issued' && invoice.paymentStatus != 'paid' && p.paymentsManage)
          InvoiceActionButton(label: l.recordPayment, icon: Icons.payments_outlined, onPressed: _manualPayment),
        if (invoice.documentStatus == 'issued' && invoice.paymentStatus != 'paid' && p.paymentsManage)
          InvoiceActionButton(label: l.createCheckout, icon: Icons.link_outlined, onPressed: _checkout),
        if (moreItems.isNotEmpty)
          PopupMenuButton<String>(
            tooltip: l.more,
            onSelected: (value) async {
              switch (value) {
                case 'edit':
                  context.push('/invoices/${invoice.id}/edit');
                case 'remind':
                  await promptEmailAndSend(context, (email) => _act('remind', body: {'email': email}));
                case 'checkout':
                  await _checkout();
                case 'copy':
                  await copyText(invoice.checkout!.checkoutUrl!);
                  if (context.mounted) showSnack(context, l.copied);
                case 'open':
                  await openExternalUrl(invoice.checkout!.checkoutUrl!);
                  await _load();
                case 'credit':
                  context.push('/notes/new?invoice_id=${invoice.id}');
                case 'cancel':
                  await confirmAndRun(context, () => _act('cancel'));
                case 'delete':
                  await confirmAndRun(context, () async {
                    await ref.read(financeApiProvider).deleteInvoice(invoice.id);
                    if (context.mounted) context.go('/invoices');
                  });
              }
            },
            itemBuilder: (_) => moreItems,
            child: IgnorePointer(
              child: InvoiceActionButton(label: l.more, icon: Icons.more_horiz, onPressed: () {}),
            ),
          ),
      ],
    );
  }
}

class InvoiceFormScreen extends ConsumerStatefulWidget {
  const InvoiceFormScreen({super.key, this.id});
  final int? id;

  @override
  ConsumerState<InvoiceFormScreen> createState() => _InvoiceFormScreenState();
}

class _InvoiceFormScreenState extends ConsumerState<InvoiceFormScreen> {
  int? _customerId;
  int? _contractId;
  int? _projectId;
  bool _walkIn = false;
  String _subtype = 'standard';
  String _zatca = 'not_required';
  String _taxProfile = 'standard';
  String _taxMode = 'exclusive';
  final _walkInName = TextEditingController();
  final _issueDate = TextEditingController(text: isoDate());
  final _dueDate = TextEditingController(text: isoDate(DateTime.now().add(const Duration(days: 14))));
  final _currency = TextEditingController(text: 'SAR');
  final _paymentTerms = TextEditingController();
  final _taxRate = TextEditingController(text: '15');
  final _notes = TextEditingController();
  final _invoiceNumber = TextEditingController();
  final _supplyDate = TextEditingController();
  final List<LineDraft> _lines = [LineDraft(description: 'خدمة فوترة', unitPrice: '100')];
  final List<PlatformFile> _files = [];
  bool _issueNow = false;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    if (widget.id != null) {
      ref.read(financeApiProvider).invoice(widget.id!).then((invoice) {
        if (!mounted) return;
        _customerId = invoice.customerId;
        _walkIn = invoice.customerId == null;
        _walkInName.text = invoice.customerName ?? '';
        _notes.text = invoice.notes ?? '';
        _paymentTerms.text = invoice.paymentTerms ?? '';
        _currency.text = invoice.currency;
        _taxRate.text = invoice.taxRate ?? '15';
        _taxProfile = invoice.taxProfileType ?? 'standard';
        _taxMode = invoice.taxPriceMode ?? 'exclusive';
        _subtype = invoice.zatcaSubtype ?? 'standard';
        _zatca = invoice.zatcaRequirement ?? 'not_required';
        _contractId = invoice.contractId;
        _projectId = invoice.projectId;
        _invoiceNumber.text = invoice.invoiceNumber ?? '';
        if (invoice.issueDate != null) _issueDate.text = invoice.issueDate!.substring(0, 10);
        if (invoice.dueDate != null) _dueDate.text = invoice.dueDate!.substring(0, 10);
        if (invoice.supplyDate != null && invoice.supplyDate!.length >= 10) {
          _supplyDate.text = invoice.supplyDate!.substring(0, 10);
        }
        if (invoice.lines.isNotEmpty) {
          for (final line in _lines) {
            line.dispose();
          }
          _lines
            ..clear()
            ..addAll(invoice.lines.map(LineDraft.fromItem));
        }
        setState(() {});
      }).catchError((Object error) {
        if (mounted) showApiError(context, error);
      });
    }
  }

  @override
  void dispose() {
    _walkInName.dispose();
    _issueDate.dispose();
    _dueDate.dispose();
    _currency.dispose();
    _paymentTerms.dispose();
    _taxRate.dispose();
    _notes.dispose();
    _invoiceNumber.dispose();
    _supplyDate.dispose();
    for (final line in _lines) {
      line.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (!_walkIn && _customerId == null) return;
    if (_walkIn && _walkInName.text.trim().isEmpty) return;
    setState(() => _busy = true);
    try {
      final body = <String, dynamic>{
        if (!_walkIn) 'customer_id': _customerId,
        if (_walkIn) 'customer_name': _walkInName.text.trim(),
        'issue_date': _issueDate.text.trim(),
        'due_date': _dueDate.text.trim(),
        if (_supplyDate.text.trim().isNotEmpty) 'supply_date': _supplyDate.text.trim(),
        'currency': _currency.text.trim(),
        'payment_terms': _paymentTerms.text.trim(),
        'notes': _notes.text.trim(),
        'invoice_status': _issueNow && widget.id == null ? 'issued' : 'draft',
        'tax_profile_type': _taxProfile,
        'tax_rate': _taxRate.text.trim(),
        'tax_price_mode': _taxMode,
        'tax_document_subtype': _subtype,
        'zatca_requirement': _zatca,
        if (_invoiceNumber.text.trim().isNotEmpty) 'invoice_number': _invoiceNumber.text.trim(),
        if (_contractId != null) 'contract_id': _contractId,
        if (_projectId != null) 'project_id': _projectId,
        'items': _lines.map((line) => line.toPayload()).toList(),
      };
      late final InvoiceRecord saved;
      if (widget.id == null && _files.isNotEmpty) {
        final form = FormData.fromMap({
          ...body,
          'items_json': jsonEncode(body['items']),
        }..remove('items'));
        for (final file in _files) {
          final part = await multipartFromPicked(file);
          if (part != null) form.files.add(MapEntry('attachments[]', part));
        }
        saved = await ref.read(financeApiProvider).saveInvoice({}, form: form);
      } else {
        saved = await ref.read(financeApiProvider).saveInvoice(body, id: widget.id);
        if (_files.isNotEmpty) {
          final form = FormData();
          for (final file in _files) {
            final part = await multipartFromPicked(file);
            if (part != null) form.files.add(MapEntry('attachments[]', part));
          }
          if (form.files.isNotEmpty) {
            await ref.read(financeApiProvider).uploadInvoiceAttachments(saved.id, form);
          }
        }
      }
      if (!mounted) return;
      context.go('/invoices/${saved.id}');
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
      title: l.invoices,
      showBack: true,
      body: FinancePage(
        child: ListView(
        padding: EdgeInsets.zero,
        children: [
          FormSection(
            title: l.headerSection,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(l.walkInCustomer),
                  value: _walkIn,
                  onChanged: (v) => setState(() => _walkIn = v),
                ),
                if (_walkIn)
                  TextField(controller: _walkInName, decoration: InputDecoration(labelText: l.walkInName))
                else
                  CustomerSelectField(selectedId: _customerId, onSelected: (id) => setState(() => _customerId = id)),
                FormGrid(children: [
                  if (catalog.allowManualInvoiceNumbers || _invoiceNumber.text.isNotEmpty)
                    TextField(controller: _invoiceNumber, decoration: InputDecoration(labelText: l.invoiceNumber)),
                  DropdownButtonFormField(
                    isExpanded: true,
                    // ignore: deprecated_member_use
                    value: _subtype,
                    decoration: InputDecoration(labelText: l.taxDocumentSubtype),
                    items: [
                      DropdownMenuItem(value: 'standard', child: Text(l.standardTax)),
                      DropdownMenuItem(value: 'simplified', child: Text(l.simplifiedTax)),
                    ],
                    onChanged: (v) => setState(() => _subtype = v ?? 'standard'),
                  ),
                  DropdownButtonFormField(
                    isExpanded: true,
                    // ignore: deprecated_member_use
                    value: _zatca,
                    decoration: InputDecoration(labelText: l.zatcaRequirement),
                    items: [
                      DropdownMenuItem(value: 'not_required', child: Text(l.notRequired)),
                      DropdownMenuItem(value: 'required', child: Text(l.requiredLater)),
                    ],
                    onChanged: (v) => setState(() => _zatca = v ?? 'not_required'),
                  ),
                  OptionPicker(
                    label: l.contract,
                    options: [for (final row in catalog.contracts) NamedOption(id: row.id, name: row.name)],
                    value: _contractId,
                    onChanged: (id) => setState(() => _contractId = id),
                  ),
                  OptionPicker(
                    label: l.project,
                    options: [for (final row in catalog.projects) NamedOption(id: row.id, name: row.name)],
                    value: _projectId,
                    onChanged: (id) => setState(() => _projectId = id),
                  ),
                ]),
              ],
            ),
          ),
          FormSection(
            title: l.datesSection,
            child: FormGrid(children: [
              TextField(controller: _issueDate, decoration: InputDecoration(labelText: l.issueDate)),
              TextField(controller: _dueDate, decoration: InputDecoration(labelText: l.dueDate)),
              TextField(controller: _supplyDate, decoration: InputDecoration(labelText: l.supplyDate)),
              TextField(controller: _currency, decoration: InputDecoration(labelText: l.currency)),
              TextField(controller: _paymentTerms, decoration: InputDecoration(labelText: l.paymentTerms)),
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
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                TextField(controller: _notes, decoration: InputDecoration(labelText: l.notesField), maxLines: 3),
                if (widget.id == null)
                  SwitchListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(l.issueImmediately),
                    value: _issueNow,
                    onChanged: (v) => setState(() => _issueNow = v),
                  ),
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

class _InvoicePipelineSummary extends StatelessWidget {
  const _InvoicePipelineSummary({this.meta});

  final Map<String, dynamic>? meta;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final pipeline = meta?['pipeline'];
    final totals = meta?['totals'];
    if (pipeline is! Map && totals is! Map) {
      return const SizedBox.shrink();
    }
    final counts = pipeline is Map ? Map<String, dynamic>.from(pipeline) : const <String, dynamic>{};
    final sums = totals is Map ? Map<String, dynamic>.from(totals) : const <String, dynamic>{};
    final chips = <(String, String)>[
      (l.filterAll, '${counts['all'] ?? ''}'),
      (l.draft, '${counts['draft'] ?? ''}'),
      (l.lifecycleSent, '${counts['sent'] ?? ''}'),
      (l.partial, '${counts['partial'] ?? ''}'),
      (l.paid, '${counts['paid'] ?? ''}'),
      (l.overdue, '${counts['overdue'] ?? ''}'),
      (l.cancelled, '${counts['cancelled'] ?? ''}'),
    ];
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: FinanceTokens.card(context),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 6,
            children: [
              for (final chip in chips)
                if (chip.$2.isNotEmpty)
                  Text('${chip.$1}: ${chip.$2}', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: context.hs.textMuted)),
            ],
          ),
          if (sums.isNotEmpty) ...[
            const SizedBox(height: 8),
            Wrap(
              spacing: 16,
              children: [
                if (sums['total'] != null) Text('${l.total}: ${sums['total']}', style: const TextStyle(fontWeight: FontWeight.w800)),
                if (sums['due'] != null) Text('${l.due}: ${sums['due']}', style: const TextStyle(fontWeight: FontWeight.w800)),
                if (sums['overdue'] != null) Text('${l.overdue}: ${sums['overdue']}', style: const TextStyle(fontWeight: FontWeight.w800)),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
