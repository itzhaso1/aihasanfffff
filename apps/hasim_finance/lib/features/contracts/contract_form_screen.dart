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
import 'package:hasim_finance/features/shared/paged.dart';
import 'package:hasim_finance/core/widgets/date_field.dart';
import 'package:hasim_finance/features/contracts/contract_form_widgets.dart';
import 'package:hasim_finance/features/shared/contract_lines_editor.dart';
import 'package:hasim_finance/features/shared/customer_select.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class ContractFormScreen extends ConsumerStatefulWidget {
  const ContractFormScreen({super.key, this.id});
  final int? id;

  @override
  ConsumerState<ContractFormScreen> createState() => _ContractFormScreenState();
}

class _ContractFormScreenState extends ConsumerState<ContractFormScreen> {
  final _title = TextEditingController();
  final _description = TextEditingController();
  int? _customerId;
  int? _projectId;
  final _value = TextEditingController(text: '0');
  final _currency = TextEditingController(text: 'SAR');
  final _paymentTerms = TextEditingController();
  final _start = TextEditingController(text: isoDate());
  final _end = TextEditingController();
  final _notes = TextEditingController();
  final _terms = TextEditingController();
  final List<ContractLineDraft> _lines = [ContractLineDraft()];
  final List<PlatformFile> _pendingFiles = [];
  bool _busy = false;
  bool _loading = false;
  String? _customerError;

  bool get _hasLineItems => _lines.any((line) => line.title.text.trim().isNotEmpty);

  @override
  void initState() {
    super.initState();
    if (widget.id != null) {
      setState(() => _loading = true);
      ref.read(financeApiProvider).contract(widget.id!).then((c) {
        _title.text = c.title ?? '';
        _description.text = c.description ?? '';
        _customerId = c.customerId;
        _projectId = c.projectId;
        _value.text = c.value;
        _currency.text = c.currency;
        _paymentTerms.text = c.paymentTerms ?? '';
        _start.text = c.startDate ?? isoDate();
        _end.text = c.endDate ?? '';
        _notes.text = c.notes ?? '';
        _terms.text = c.terms ?? '';
        if (c.items.isNotEmpty) {
          for (final line in _lines) {
            line.dispose();
          }
          _lines
            ..clear()
            ..addAll(c.items.map(ContractLineDraft.fromItem));
        }
        if (mounted) setState(() => _loading = false);
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
    _title.dispose();
    _description.dispose();
    _value.dispose();
    _currency.dispose();
    _paymentTerms.dispose();
    _start.dispose();
    _end.dispose();
    _notes.dispose();
    _terms.dispose();
    for (final line in _lines) {
      line.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (_customerId == null) {
      setState(() => _customerError = 'يرجى اختيار العميل');
      return;
    }
    if (_title.text.trim().isEmpty) {
      showSnack(context, 'يرجى إدخال اسم العقد');
      return;
    }

    setState(() {
      _busy = true;
      _customerError = null;
    });

    try {
      final itemPayload = _lines
          .where((line) => line.title.text.trim().isNotEmpty)
          .map((line) => line.toPayload())
          .toList();
      final saved = await ref.read(financeApiProvider).saveContract({
        'title': _title.text.trim(),
        if (_description.text.trim().isNotEmpty) 'description': _description.text.trim(),
        'customer_id': _customerId,
        if (_projectId != null) 'project_id': _projectId,
        if (itemPayload.isEmpty) 'value': _value.text.trim(),
        'currency': _currency.text.trim(),
        if (_paymentTerms.text.trim().isNotEmpty) 'payment_terms': _paymentTerms.text.trim(),
        'start_date': _start.text.trim(),
        'end_date': _end.text.trim(),
        'notes': _notes.text.trim(),
        'terms': _terms.text.trim(),
        if (itemPayload.isNotEmpty) 'items': itemPayload,
      }, id: widget.id);

      if (_pendingFiles.isNotEmpty) {
        final form = FormData();
        for (final file in _pendingFiles) {
          final part = await multipartFromPicked(file);
          if (part == null) continue;
          form.files.add(MapEntry('attachments[]', part));
        }
        if (form.files.isNotEmpty) {
          await ref.read(financeApiProvider).uploadContractAttachments(saved.id, form);
        }
      }

      if (mounted) context.go('/contracts/${saved.id}');
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
    context.go('/contracts');
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final catalog = ref.watch(financeCatalogProvider).valueOrNull ?? const FinanceCatalog();
    final isEditing = widget.id != null;
    final compactActions = MediaQuery.sizeOf(context).width < 900;

    return FinanceScaffold(
      title: l.contracts,
      subtitle: isEditing ? 'تعديل تفاصيل العقد الحالي' : 'إنشاء عقد جديد وإدارة التفاصيل الخاصة به',
      showBack: true,
      actions: compactActions
          ? const []
          : [
              TextButton(onPressed: _busy ? null : _cancel, child: Text(l.cancel)),
              OutlinedButton.icon(
                onPressed: _busy || _customerId == null ? null : _save,
                icon: const Icon(Icons.save_outlined, size: 18),
                label: Text(l.draft),
              ),
              FilledButton.icon(
                onPressed: _busy || _customerId == null ? null : _save,
                icon: _busy
                    ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : const Icon(Icons.check_circle_outline, size: 18),
                label: Text(l.save),
                style: FilledButton.styleFrom(backgroundColor: FinanceTokens.brand, foregroundColor: Colors.white),
              ),
            ],
      body: ColoredBox(
        color: context.hs.canvas,
        child: FinancePage(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : ListView(
                  padding: EdgeInsets.zero,
                  children: [
                    const Padding(
                      padding: EdgeInsets.only(bottom: 12),
                      child: ContractFormBreadcrumb(segments: ['الرئيسية', 'العقود']),
                    ),
                    if (compactActions)
                      ContractFormActionBar(
                        busy: _busy,
                        canSave: _customerId != null,
                        onSave: _save,
                        onSaveDraft: _save,
                        onCancel: _cancel,
                      ),
                    ContractFormSectionCard(
                      title: 'البيانات الأساسية',
                      subtitle: 'أدخل المعلومات الأساسية للعقد',
                      icon: Icons.article_outlined,
                      child: ContractFormGrid(
                        children: [
                          ContractLabeledField(
                            label: l.fieldName,
                            required: true,
                            fullWidth: true,
                            child: TextField(
                              controller: _title,
                              decoration: contractFieldDecoration(hintText: 'أدخل الاسم', prefixIcon: Icons.title),
                            ),
                          ),
                          ContractLabeledField(
                            label: l.description,
                            fullWidth: true,
                            child: TextField(
                              controller: _description,
                              maxLines: 3,
                              decoration: contractFieldDecoration(hintText: 'أدخل الوصف', prefixIcon: Icons.notes),
                            ),
                          ),
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
                          ContractLabeledField(
                            label: l.project,
                            child: DropdownButtonFormField<int?>(
                              // ignore: deprecated_member_use
                              value: _projectId != null && catalog.projects.any((row) => row.id == _projectId)
                                  ? _projectId
                                  : null,
                              isExpanded: true,
                              decoration: contractFieldDecoration(hintText: 'اختر المشروع', prefixIcon: Icons.work_outline),
                              items: [
                                const DropdownMenuItem<int?>(value: null, child: Text('بدون مشروع')),
                                for (final row in catalog.projects)
                                  DropdownMenuItem<int?>(value: row.id, child: Text(row.name)),
                              ],
                              onChanged: (id) => setState(() => _projectId = id),
                            ),
                          ),
                          ContractLabeledField(
                            label: l.amount,
                            required: true,
                            helperText: _hasLineItems ? 'يُحسب تلقائياً من البنود' : null,
                            child: TextField(
                              controller: _value,
                              readOnly: _hasLineItems,
                              keyboardType: const TextInputType.numberWithOptions(decimal: true),
                              decoration: contractFieldDecoration(hintText: '0.00', prefixIcon: Icons.payments_outlined),
                            ),
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
                          ContractLabeledField(
                            label: l.from,
                            required: true,
                            child: DateField(controller: _start, allowClear: false),
                          ),
                          ContractLabeledField(
                            label: l.to,
                            required: true,
                            child: DateField(controller: _end),
                          ),
                        ],
                      ),
                    ),
                    ContractFormSectionCard(
                      title: l.lines,
                      subtitle: 'أضف بنود العقد وتفاصيل الخدمات أو المنتجات',
                      icon: Icons.inventory_2_outlined,
                      child: ContractLinesEditor(
                        lines: _lines,
                        onChanged: () => setState(() {}),
                      ),
                    ),
                    ContractFormSectionCard(
                      title: 'الشروط والملاحظات',
                      subtitle: 'شروط العقد والملاحظات الداخلية',
                      icon: Icons.gavel_outlined,
                      child: ContractFormGrid(
                        minWidth: 280,
                        children: [
                          ContractLabeledField(
                            label: l.terms,
                            fullWidth: true,
                            child: TextField(
                              controller: _terms,
                              maxLines: 4,
                              decoration: contractFieldDecoration(hintText: 'اكتب شروط العقد...'),
                            ),
                          ),
                          ContractLabeledField(
                            label: l.notesField,
                            fullWidth: true,
                            child: TextField(
                              controller: _notes,
                              maxLines: 4,
                              decoration: contractFieldDecoration(hintText: 'ملاحظات داخلية...'),
                            ),
                          ),
                        ],
                      ),
                    ),
                    ContractFormSectionCard(
                      title: l.attachment,
                      subtitle: 'ارفق مستندات العقد (PDF، صور، Word)',
                      icon: Icons.attach_file_outlined,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          for (final file in _pendingFiles)
                            Container(
                              margin: const EdgeInsets.only(bottom: 8),
                              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                              decoration: BoxDecoration(
                                color: context.hs.canvasAlt,
                                borderRadius: BorderRadius.circular(FinanceTokens.radius),
                                border: Border.all(color: context.hs.border),
                              ),
                              child: Row(
                                children: [
                                  Icon(Icons.insert_drive_file_outlined, size: 18, color: context.hs.textMuted),
                                  const SizedBox(width: 8),
                                  Expanded(child: Text(file.name, maxLines: 1, overflow: TextOverflow.ellipsis)),
                                  IconButton(
                                    tooltip: l.delete,
                                    onPressed: () => setState(() => _pendingFiles.remove(file)),
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
                                      allowedExtensions: const ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'],
                                    );
                                    if (result == null || result.files.isEmpty) return;
                                    setState(() => _pendingFiles.addAll(result.files));
                                  },
                            icon: const Icon(Icons.upload_file_outlined),
                            label: Text(l.attachment),
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
