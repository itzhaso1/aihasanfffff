import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/layout/finance_layout.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/providers/catalog_provider.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';
import 'package:hasim_finance/core/utils/files.dart';
import 'package:hasim_finance/core/widgets/date_field.dart';
import 'package:hasim_finance/features/contracts/contract_form_widgets.dart';
import 'package:hasim_finance/features/shared/customer_select.dart';
import 'package:hasim_finance/features/shared/document_lines_editor.dart';
import 'package:hasim_finance/features/shared/paged.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class InvoiceFormScreen extends ConsumerStatefulWidget {
  const InvoiceFormScreen({super.key, this.id, this.contractId});
  final int? id;
  final int? contractId;

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
  bool _loading = false;
  String? _customerError;
  String? _walkInError;

  Future<void> _applyContractPrefill(int contractId) async {
    try {
      final contract = await ref.read(financeApiProvider).contract(contractId);
      if (!mounted) return;
      _contractId = contract.id;
      _customerId = contract.customerId;
      _projectId = contract.projectId;
      if (contract.paymentTerms != null && contract.paymentTerms!.isNotEmpty) {
        _paymentTerms.text = contract.paymentTerms!;
      }
      _currency.text = contract.currency;
      if (contract.items.isNotEmpty) {
        for (final line in _lines) {
          line.dispose();
        }
        _lines
          ..clear()
          ..addAll(contract.items.map((item) => LineDraft(
                description: item.title ?? item.description ?? '',
                quantity: item.quantity ?? '1',
                unitPrice: item.unitPrice ?? '0',
                unit: item.unit ?? '',
                taxRate: item.taxRate ?? '15',
                discount: item.discount ?? '0',
              )));
      }
      setState(() {});
    } catch (error) {
      if (mounted) showApiError(context, error);
    }
  }

  @override
  void initState() {
    super.initState();
    if (widget.contractId != null && widget.id == null) {
      _contractId = widget.contractId;
      _applyContractPrefill(widget.contractId!);
    }
    if (widget.id != null) {
      setState(() => _loading = true);
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
        setState(() => _loading = false);
      }).catchError((Object error) {
        if (mounted) {
          setState(() => _loading = false);
          showApiError(context, error);
        }
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

  bool get _canSave => _walkIn || _customerId != null;

  Future<void> _save({required bool issue}) async {
    if (!_walkIn && _customerId == null) {
      setState(() => _customerError = 'يرجى اختيار العميل');
      return;
    }
    if (_walkIn && _walkInName.text.trim().isEmpty) {
      setState(() => _walkInError = 'يرجى إدخال اسم العميل');
      return;
    }

    setState(() {
      _busy = true;
      _customerError = null;
      _walkInError = null;
      _issueNow = issue;
    });

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
        'invoice_status': issue && widget.id == null ? 'issued' : 'draft',
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

  void _cancel() {
    if (Navigator.of(context).canPop()) {
      context.pop();
      return;
    }
    context.go('/invoices');
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final catalog = ref.watch(financeCatalogProvider).valueOrNull ?? const FinanceCatalog();
    final isEditing = widget.id != null;
    final compactActions = MediaQuery.sizeOf(context).width < 900;
    final p = context.hs;

    return FinanceScaffold(
      title: l.invoices,
      subtitle: isEditing ? 'تعديل تفاصيل الفاتورة' : 'إنشاء فاتورة جديدة وإدارة تفاصيلها',
      showBack: true,
      actions: compactActions
          ? const []
          : [
              TextButton(onPressed: _busy ? null : _cancel, child: Text(l.cancel)),
              OutlinedButton.icon(
                onPressed: _busy || !_canSave ? null : () => _save(issue: false),
                icon: const Icon(Icons.save_outlined, size: 18),
                label: Text(l.draft),
              ),
              if (widget.id == null)
                FilledButton.icon(
                  onPressed: _busy || !_canSave ? null : () => _save(issue: true),
                  icon: const Icon(Icons.send_outlined, size: 18),
                  label: Text(l.issueImmediately),
                  style: FilledButton.styleFrom(backgroundColor: FinanceTokens.brand, foregroundColor: Colors.white),
                ),
              FilledButton.icon(
                onPressed: _busy || !_canSave ? null : () => _save(issue: _issueNow),
                icon: _busy
                    ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : const Icon(Icons.check_circle_outline, size: 18),
                label: Text(l.save),
                style: FilledButton.styleFrom(backgroundColor: FinanceTokens.brand, foregroundColor: Colors.white),
              ),
            ],
      body: ColoredBox(
        color: p.canvas,
        child: FinancePage(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : ListView(
                  padding: EdgeInsets.zero,
                  children: [
                    const Padding(
                      padding: EdgeInsets.only(bottom: 12),
                      child: ContractFormBreadcrumb(segments: ['الرئيسية', 'الفواتير']),
                    ),
                    if (compactActions)
                      ContractFormActionBar(
                        busy: _busy,
                        canSave: _canSave,
                        primaryLabel: l.save,
                        draftLabel: l.draft,
                        onSave: () => _save(issue: _issueNow),
                        onSaveDraft: () => _save(issue: false),
                        onCancel: _cancel,
                      ),
                    if (widget.id == null)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 14),
                        child: DecoratedBox(
                          decoration: FinanceTokens.card(context),
                          child: SwitchListTile(
                            contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                            title: Text(l.issueImmediately, style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                            subtitle: Text('عند التفعيل تُصدر الفاتورة مباشرة بدلاً من حفظها كمسودة', style: Theme.of(context).textTheme.bodySmall?.copyWith(color: p.textMuted)),
                            value: _issueNow,
                            activeTrackColor: FinanceTokens.brand.withValues(alpha: 0.35),
                            activeThumbColor: FinanceTokens.brand,
                            onChanged: (v) => setState(() => _issueNow = v),
                          ),
                        ),
                      ),
                    ContractFormSectionCard(
                      title: l.headerSection,
                      subtitle: 'بيانات العميل والعقد والتصنيف الضريبي',
                      icon: Icons.receipt_long_outlined,
                      child: ContractFormGrid(
                        children: [
                          ContractLabeledField(
                            label: l.walkInCustomer,
                            fullWidth: true,
                            child: SwitchListTile(
                              contentPadding: EdgeInsets.zero,
                              title: Text(l.walkInCustomer, style: Theme.of(context).textTheme.bodyMedium),
                              value: _walkIn,
                              activeTrackColor: FinanceTokens.brand.withValues(alpha: 0.35),
                              activeThumbColor: FinanceTokens.brand,
                              onChanged: (v) => setState(() {
                                _walkIn = v;
                                _customerError = null;
                                _walkInError = null;
                              }),
                            ),
                          ),
                          if (_walkIn)
                            ContractLabeledField(
                              label: l.walkInName,
                              required: true,
                              errorText: _walkInError,
                              child: TextField(
                                controller: _walkInName,
                                decoration: contractFieldDecoration(hintText: 'اسم العميل', prefixIcon: Icons.person_outline),
                              ),
                            )
                          else
                            ContractLabeledField(
                              label: l.selectCustomer,
                              required: true,
                              errorText: _customerError,
                              child: CustomerSelectField(
                                selectedId: _customerId,
                                onSelected: (id) => setState(() {
                                  _customerId = id;
                                  _customerError = null;
                                }),
                                compact: true,
                                floatingLabel: false,
                                label: l.selectCustomer,
                              ),
                            ),
                          if (catalog.allowManualInvoiceNumbers || _invoiceNumber.text.isNotEmpty)
                            ContractLabeledField(
                              label: l.invoiceNumber,
                              child: TextField(
                                controller: _invoiceNumber,
                                decoration: contractFieldDecoration(hintText: 'INV-0001', prefixIcon: Icons.tag),
                              ),
                            ),
                          ContractLabeledField(
                            label: l.taxDocumentSubtype,
                            child: DropdownButtonFormField<String>(
                              // ignore: deprecated_member_use
                              value: _subtype,
                              isExpanded: true,
                              decoration: contractFieldDecoration(hintText: l.standardTax, prefixIcon: Icons.description_outlined),
                              items: [
                                DropdownMenuItem(value: 'standard', child: Text(l.standardTax)),
                                DropdownMenuItem(value: 'simplified', child: Text(l.simplifiedTax)),
                              ],
                              onChanged: (v) => setState(() => _subtype = v ?? 'standard'),
                            ),
                          ),
                          ContractLabeledField(
                            label: l.zatcaRequirement,
                            child: DropdownButtonFormField<String>(
                              // ignore: deprecated_member_use
                              value: _zatca,
                              isExpanded: true,
                              decoration: contractFieldDecoration(hintText: l.notRequired, prefixIcon: Icons.verified_outlined),
                              items: [
                                DropdownMenuItem(value: 'not_required', child: Text(l.notRequired)),
                                DropdownMenuItem(value: 'required', child: Text(l.requiredLater)),
                              ],
                              onChanged: (v) => setState(() => _zatca = v ?? 'not_required'),
                            ),
                          ),
                          ContractLabeledField(
                            label: l.contract,
                            child: DropdownButtonFormField<int?>(
                              // ignore: deprecated_member_use
                              value: _contractId != null && catalog.contracts.any((row) => row.id == _contractId)
                                  ? _contractId
                                  : null,
                              isExpanded: true,
                              decoration: contractFieldDecoration(hintText: 'بدون عقد', prefixIcon: Icons.handshake_outlined),
                              items: [
                                const DropdownMenuItem<int?>(value: null, child: Text('—')),
                                for (final row in catalog.contracts)
                                  DropdownMenuItem<int?>(value: row.id, child: Text(row.name)),
                              ],
                              onChanged: (id) {
                                setState(() => _contractId = id);
                                if (id != null && widget.id == null) {
                                  _applyContractPrefill(id);
                                }
                              },
                            ),
                          ),
                          ContractLabeledField(
                            label: l.project,
                            child: DropdownButtonFormField<int?>(
                              // ignore: deprecated_member_use
                              value: _projectId != null && catalog.projects.any((row) => row.id == _projectId)
                                  ? _projectId
                                  : null,
                              isExpanded: true,
                              decoration: contractFieldDecoration(hintText: 'بدون مشروع', prefixIcon: Icons.work_outline),
                              items: [
                                const DropdownMenuItem<int?>(value: null, child: Text('—')),
                                for (final row in catalog.projects)
                                  DropdownMenuItem<int?>(value: row.id, child: Text(row.name)),
                              ],
                              onChanged: (id) => setState(() => _projectId = id),
                            ),
                          ),
                          if (_contractId != null && widget.id == null)
                            ContractLabeledField(
                              label: l.contract,
                              fullWidth: true,
                              child: Container(
                                padding: const EdgeInsets.all(12),
                                decoration: BoxDecoration(
                                  color: FinanceTokens.brandSoft,
                                  borderRadius: BorderRadius.circular(FinanceTokens.radius),
                                  border: Border.all(color: FinanceTokens.brand.withValues(alpha: 0.25)),
                                ),
                                child: Text(
                                  'الفاتورة مرتبطة بالعقد #$_contractId — تم تعبئة العميل والبنود تلقائياً عند الإمكان.',
                                  style: Theme.of(context).textTheme.bodySmall?.copyWith(color: FinanceTokens.brandDark),
                                ),
                              ),
                            ),
                        ],
                      ),
                    ),
                    ContractFormSectionCard(
                      title: l.datesSection,
                      subtitle: 'تواريخ الإصدار والاستحقاق وشروط الدفع',
                      icon: Icons.calendar_month_outlined,
                      child: ContractFormGrid(
                        children: [
                          ContractLabeledField(
                            label: l.issueDate,
                            required: true,
                            child: DateField(controller: _issueDate, allowClear: false),
                          ),
                          ContractLabeledField(
                            label: l.dueDate,
                            required: true,
                            child: DateField(controller: _dueDate, allowClear: false),
                          ),
                          ContractLabeledField(
                            label: l.supplyDate,
                            child: DateField(controller: _supplyDate),
                          ),
                          ContractLabeledField(
                            label: l.currency,
                            child: TextField(
                              controller: _currency,
                              decoration: contractFieldDecoration(hintText: 'SAR', prefixIcon: Icons.currency_exchange),
                            ),
                          ),
                          ContractLabeledField(
                            label: l.paymentTerms,
                            child: TextField(
                              controller: _paymentTerms,
                              decoration: contractFieldDecoration(hintText: 'مثال: 30 يوم', prefixIcon: Icons.receipt_long_outlined),
                            ),
                          ),
                        ],
                      ),
                    ),
                    ContractFormSectionCard(
                      title: l.taxSection,
                      subtitle: 'إعدادات الضريبة الافتراضية للفاتورة',
                      icon: Icons.percent_outlined,
                      child: ContractFormGrid(
                        children: [
                          ContractLabeledField(
                            label: l.taxProfile,
                            child: DropdownButtonFormField<String>(
                              // ignore: deprecated_member_use
                              value: _taxProfile,
                              isExpanded: true,
                              decoration: contractFieldDecoration(prefixIcon: Icons.account_balance_outlined),
                              items: [
                                DropdownMenuItem(value: 'standard', child: Text(l.standardTax)),
                                DropdownMenuItem(value: 'zero_rated', child: Text(l.zeroRated)),
                                DropdownMenuItem(value: 'exempt', child: Text(l.exempt)),
                                DropdownMenuItem(value: 'out_of_scope', child: Text(l.outOfScope)),
                              ],
                              onChanged: (v) => setState(() => _taxProfile = v ?? 'standard'),
                            ),
                          ),
                          ContractLabeledField(
                            label: l.taxPriceMode,
                            child: DropdownButtonFormField<String>(
                              // ignore: deprecated_member_use
                              value: _taxMode,
                              isExpanded: true,
                              decoration: contractFieldDecoration(prefixIcon: Icons.price_change_outlined),
                              items: [
                                DropdownMenuItem(value: 'exclusive', child: Text(l.exclusive)),
                                DropdownMenuItem(value: 'inclusive', child: Text(l.inclusive)),
                              ],
                              onChanged: (v) => setState(() => _taxMode = v ?? 'exclusive'),
                            ),
                          ),
                          ContractLabeledField(
                            label: l.taxRate,
                            child: TextField(
                              controller: _taxRate,
                              keyboardType: const TextInputType.numberWithOptions(decimal: true),
                              decoration: contractFieldDecoration(hintText: '15', prefixIcon: Icons.percent),
                            ),
                          ),
                        ],
                      ),
                    ),
                    ContractFormSectionCard(
                      title: l.itemsSection,
                      subtitle: 'أضف بنود الفاتورة والخدمات أو المنتجات',
                      icon: Icons.inventory_2_outlined,
                      child: DocumentLinesEditor(
                        lines: _lines,
                        products: catalog.products,
                        onChanged: () => setState(() {}),
                      ),
                    ),
                    ContractFormSectionCard(
                      title: l.notesSection,
                      subtitle: 'ملاحظات ومرفقات الفاتورة',
                      icon: Icons.note_alt_outlined,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          ContractLabeledField(
                            label: l.notesField,
                            fullWidth: true,
                            child: TextField(
                              controller: _notes,
                              maxLines: 3,
                              decoration: contractFieldDecoration(hintText: 'ملاحظات تظهر على الفاتورة...'),
                            ),
                          ),
                          const SizedBox(height: 12),
                          for (final file in _files)
                            Container(
                              margin: const EdgeInsets.only(bottom: 8),
                              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                              decoration: BoxDecoration(
                                color: p.canvasAlt,
                                borderRadius: BorderRadius.circular(FinanceTokens.radius),
                                border: Border.all(color: p.border),
                              ),
                              child: Row(
                                children: [
                                  Icon(Icons.insert_drive_file_outlined, size: 18, color: p.textMuted),
                                  const SizedBox(width: 8),
                                  Expanded(child: Text(file.name, maxLines: 1, overflow: TextOverflow.ellipsis)),
                                  IconButton(
                                    tooltip: l.delete,
                                    onPressed: () => setState(() => _files.remove(file)),
                                    icon: const Icon(Icons.close, size: 18),
                                  ),
                                ],
                              ),
                            ),
                          OutlinedButton.icon(
                            onPressed: _busy
                                ? null
                                : () async {
                                    final result = await FilePicker.platform.pickFiles(
                                      allowMultiple: true,
                                      type: FileType.custom,
                                      allowedExtensions: const ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
                                    );
                                    if (result != null && result.files.isNotEmpty) {
                                      setState(() => _files.addAll(result.files));
                                    }
                                  },
                            icon: const Icon(Icons.upload_file_outlined),
                            label: Text(_files.isEmpty ? l.pickAttachments : '${l.attachmentsTitle} (${_files.length})'),
                            style: OutlinedButton.styleFrom(
                              foregroundColor: FinanceTokens.brand,
                              padding: const EdgeInsets.symmetric(vertical: 14),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(FinanceTokens.radius)),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 24),
                  ],
                ),
        ),
      ),
    );
  }
}
