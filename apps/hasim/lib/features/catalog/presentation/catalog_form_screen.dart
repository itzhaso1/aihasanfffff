import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/presentation/catalog_inventory_l10n.dart';
import 'package:hasim/features/catalog/presentation/widgets/catalog_form_fields.dart';
import 'package:hasim/features/catalog/presentation/widgets/catalog_variants_editor.dart';
import 'package:hasim/features/catalog/providers/chat_catalog_categories_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';

class CatalogFormScreen extends ConsumerStatefulWidget {
  const CatalogFormScreen({
    super.key,
    this.item,
    this.digital = false,
    this.embedded = false,
    this.onCancel,
    this.onSaved,
    this.onDeleted,
  });

  final ChatCatalogItemModel? item;
  final bool digital; // ignored: products are knowledge items only
  final bool embedded;
  final VoidCallback? onCancel;
  final ValueChanged<ChatCatalogItemModel>? onSaved;
  final ValueChanged<int>? onDeleted;

  @override
  ConsumerState<CatalogFormScreen> createState() => _CatalogFormScreenState();
}

class _CatalogFormScreenState extends ConsumerState<CatalogFormScreen> {
  static const _currencies = [
    'SAR',
    'AED',
    'KWD',
    'BHD',
    'OMR',
    'QAR',
    'USD',
    'EUR',
  ];

  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _name;
  late final TextEditingController _description;
  late final TextEditingController _sku;
  late final TextEditingController _price;
  late final TextEditingController _workerMinimumPrice;
  late final TextEditingController _currency;
  late final TextEditingController _stock;
  String _inventoryPolicy = 'not_tracked';
  int? _categoryId;
  List<ChatCatalogVariantModel> _variants = const [];
  ChatCatalogItemModel? _persistedItem;
  bool _active = true;
  bool _saving = false;
  bool _deleting = false;
  Uint8List? _imageBytes;
  String? _imageName;

  ChatCatalogItemModel? get _currentItem => _persistedItem ?? widget.item;

  bool get _editing => _currentItem != null;

  @override
  void initState() {
    super.initState();
    final item = widget.item;
    _name = TextEditingController(text: item?.name ?? '');
    _description = TextEditingController(text: item?.description ?? '');
    _sku = TextEditingController(text: item?.sku ?? '');
    _price = TextEditingController(
      text: item == null ? '0.00' : formatCatalogMoney(item.price),
    );
    _workerMinimumPrice = TextEditingController(
      text: item?.workerMinimumPrice == null
          ? ''
          : formatCatalogMoney(item!.workerMinimumPrice!),
    );
    _currency = TextEditingController(text: item?.currency ?? 'SAR');
    _stock = TextEditingController(text: item?.stock?.toString() ?? '');
    _persistedItem = item;
    _inventoryPolicy = selectableInventoryPolicy(
      item?.inventoryPolicy,
      fallback: 'not_tracked',
    );
    _categoryId = item?.categoryId;
    _variants = [...(item?.variants ?? const [])];
    _active = item?.isActive ?? true;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(chatCatalogCategoriesControllerProvider.notifier).reload();
    });
    _name.addListener(_onFormChanged);
    _price.addListener(_onFormChanged);
    _currency.addListener(_onFormChanged);
  }

  void _onFormChanged() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    _name.removeListener(_onFormChanged);
    _price.removeListener(_onFormChanged);
    _currency.removeListener(_onFormChanged);
    _name.dispose();
    _description.dispose();
    _sku.dispose();
    _price.dispose();
    _workerMinimumPrice.dispose();
    _currency.dispose();
    _stock.dispose();
    super.dispose();
  }

  bool get _nameOk => _name.text.trim().isNotEmpty;
  bool get _priceOk => parseOptionalCatalogMoney(_price.text) != null;
  bool get _currencyOk => _currency.text.trim().isNotEmpty;
  int get _readyCount =>
      (_nameOk ? 1 : 0) + (_priceOk ? 1 : 0) + (_currencyOk ? 1 : 0);
  bool get _canSave => !_saving && _nameOk && _priceOk;

  List<String> get _currencyOptions {
    final current = _currency.text.trim().toUpperCase();
    final options = [..._currencies];
    if (current.isNotEmpty && !options.contains(current)) {
      options.insert(0, current);
    }
    return options;
  }

  Future<void> _pickImage() async {
    final picked = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      imageQuality: 85,
    );
    if (picked == null) return;
    final bytes = await picked.readAsBytes();
    if (!mounted) return;
    setState(() {
      _imageBytes = bytes;
      _imageName = picked.name;
    });
  }

  void _cancel() {
    final onCancel = widget.onCancel;
    if (onCancel != null) {
      onCancel();
      return;
    }
    if (widget.embedded) {
      FocusScope.of(context).unfocus();
      return;
    }
    if (context.canPop()) context.pop();
  }

  bool get _busy => _saving || _deleting;

  Future<void> _confirmDelete() async {
    final current = _currentItem;
    if (current == null || _busy) {
      return;
    }
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('حذف المنتج'),
        content: Text(
          'سيتم حذف «${current.name}» نهائياً. لا يمكن التراجع عن هذا الإجراء.',
        ),
        actions: [
          TextButton(
            key: const Key('catalog-delete-cancel'),
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            key: const Key('catalog-delete-confirm'),
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('حذف'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) {
      return;
    }
    setState(() => _deleting = true);
    try {
      await ref.read(chatCatalogRepositoryProvider).delete(current.id);
      if (!mounted) {
        return;
      }
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('تم حذف المنتج')));
      widget.onDeleted?.call(current.id);
      if (widget.embedded) {
        return;
      }
      if (context.canPop()) {
        context.pop(true);
      }
    } on ApiException catch (e) {
      if (!mounted) {
        return;
      }
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(e.userMessage)));
    } catch (_) {
      if (!mounted) {
        return;
      }
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('تعذر حذف المنتج.')));
    } finally {
      if (mounted) {
        setState(() => _deleting = false);
      }
    }
  }

  TextStyle _valueStyle(BuildContext context, {bool strong = true}) {
    return Theme.of(context).textTheme.bodyMedium!.copyWith(
      fontWeight: strong ? FontWeight.w600 : FontWeight.w500,
      fontSize: 13,
      height: 1.1,
      color: HasimTokens.inkColor(context),
    );
  }

  Widget _imageSection() {
    const dropHeight = 2 * CatalogFieldCard.denseHeight;
    final dark = HasimTokens.isDark(context);
    final muted = HasimTokens.mutedColor(context);
    return CatalogFormSection(
      title: 'صورة المنتج',
      icon: Icons.image_outlined,
      child: InkWell(
        onTap: _pickImage,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          height: dropHeight,
          width: double.infinity,
          decoration: BoxDecoration(
            color: dark ? HasimTokens.darkPage : const Color(0xFFF7FCFB),
            borderRadius: BorderRadius.circular(12),
            border: Border.all(
              color: dark ? HasimTokens.darkLine : const Color(0xFFD7EFEA),
            ),
          ),
          child: Stack(
            children: [
              Positioned.fill(
                child: _imageBytes == null
                    ? Padding(
                        padding: const EdgeInsets.only(bottom: 14),
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(
                              Icons.cloud_upload_outlined,
                              color: HasimTokens.mutedColor(context),
                              size: 24,
                            ),
                            const SizedBox(height: 3),
                            Text(
                              'اسحب الصورة هنا',
                              style: TextStyle(
                                fontWeight: FontWeight.w700,
                                fontSize: 12,
                                color: HasimTokens.inkColor(context),
                              ),
                            ),
                            Text(
                              'أو اضغط للاختيار',
                              style: TextStyle(color: muted, fontSize: 11),
                            ),
                          ],
                        ),
                      )
                    : ClipRRect(
                        borderRadius: BorderRadius.circular(12),
                        child: Image.memory(
                          _imageBytes!,
                          fit: BoxFit.cover,
                          width: double.infinity,
                          height: dropHeight,
                        ),
                      ),
              ),
              if (_imageBytes != null)
                PositionedDirectional(
                  top: 8,
                  start: 8,
                  child: IconButton.filled(
                    tooltip: 'حذف الصورة',
                    style: IconButton.styleFrom(
                      backgroundColor: Colors.white,
                      foregroundColor: const Color(0xFFEF4444),
                      visualDensity: VisualDensity.compact,
                    ),
                    onPressed: () => setState(() {
                      _imageBytes = null;
                      _imageName = null;
                    }),
                    icon: const Icon(Icons.close_rounded, size: 18),
                  ),
                ),
              PositionedDirectional(
                start: 10,
                end: 10,
                bottom: 8,
                child: Row(
                  children: [
                    Text(
                      'JPG, PNG, WEBP',
                      style: TextStyle(fontSize: 11, color: muted),
                    ),
                    const Spacer(),
                    Text(
                      '${_imageBytes == null ? 0 : 1}/1',
                      style: TextStyle(fontSize: 11, color: muted),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _save() async {
    if (_busy || !_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    final l10n = AppLocalizations.of(context);
    try {
      final repo = ref.read(chatCatalogRepositoryProvider);
      final price = parseOptionalCatalogMoney(_price.text) ?? 0;
      final workerMinimumPrice = parseOptionalCatalogMoney(
        _workerMinimumPrice.text,
      );
      final stock = int.tryParse(_stock.text.trim());
      final current = _currentItem;
      final saved = current != null
          ? await repo.update(
              current.id,
              name: _name.text.trim(),
              description: _description.text.trim(),
              sku: _sku.text.trim(),
              itemKind: 'product',
              tangibility: 'tangible',
              price: price,
              workerMinimumPrice: workerMinimumPrice,
              currency: _currency.text.trim().toUpperCase(),
              stock: stock,
              inventoryPolicy: _inventoryPolicy,
              isActive: _active,
              categoryId: _categoryId,
              clearCategory: _categoryId == null,
              variants: _variants,
            )
          : await repo.create(
              name: _name.text.trim(),
              description: _description.text.trim(),
              sku: _sku.text.trim(),
              itemKind: 'product',
              tangibility: 'tangible',
              price: price,
              workerMinimumPrice: workerMinimumPrice,
              currency: _currency.text.trim().toUpperCase(),
              stock: stock,
              inventoryPolicy: _inventoryPolicy,
              isActive: _active,
              categoryId: _categoryId,
              variants: _variants,
            );
      if (!mounted) return;
      if (_imageBytes != null) {
        await repo.uploadItemMedia(
          saved.id,
          imageBytes: _imageBytes,
          imageName: _imageName,
        );
      }
      if (!mounted) return;
      setState(() {
        _persistedItem = saved;
        _variants = [...saved.variants];
      });
      widget.onSaved?.call(saved);
      if (widget.embedded) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text('تم حفظ المنتج')));
        return;
      }
      context.pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(e.userMessage)));
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(l10n.catalogItemSaveFailed)));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final categories = ref.watch(
      chatCatalogCategoriesControllerProvider.select((s) => s.items),
    );
    final page = HasimTokens.pageBg(context);
    final compact = HasimLayout.isCompact(context);
    final title = _editing ? 'تعديل منتج' : 'إضافة منتج';
    const subtitle = 'أدخل تفاصيل المنتج';
    const headerIcon = Icons.shopping_bag_outlined;

    final basics = CatalogFormSection(
      title: 'معلومات المنتج الأساسية',
      icon: Icons.description_outlined,
      child: Column(
        children: [
          CatalogFieldCard(
            dense: true,
            label: 'الاسم',
            icon: Icons.sell_outlined,
            trailing: Text(
              '${_name.text.characters.length}/100',
              style: TextStyle(
                fontSize: 11,
                color: HasimTokens.mutedColor(context),
              ),
            ),
            child: TextFormField(
              key: const Key('catalog-name'),
              controller: _name,
              maxLength: 100,
              textAlign: TextAlign.start,
              validator: (value) => (value == null || value.trim().isEmpty)
                  ? 'الاسم مطلوب'
                  : null,
              style: _valueStyle(context),
              decoration: catalogInputDecoration(
                context,
                hint: 'أدخل اسم الصنف...',
              ).copyWith(counterText: ''),
            ),
          ),
          const SizedBox(height: 6),
          CatalogFieldCard(
            dense: true,
            label: 'الوصف',
            icon: Icons.notes_outlined,
            child: TextFormField(
              key: const Key('catalog-description'),
              controller: _description,
              textAlign: TextAlign.start,
              minLines: 1,
              maxLines: 1,
              style: _valueStyle(context, strong: false),
              decoration: catalogInputDecoration(
                context,
                hint: 'أدخل وصف الصنف...',
              ),
            ),
          ),
          const SizedBox(height: 6),
          CatalogFieldCard(
            dense: true,
            label: 'المرجع / SKU',
            icon: Icons.tag,
            child: TextFormField(
              key: const Key('catalog-sku'),
              controller: _sku,
              textAlign: TextAlign.start,
              style: _valueStyle(context),
              decoration: catalogInputDecoration(
                context,
                hint: 'أدخل المرجع أو رمز الصنف...',
              ),
            ),
          ),
        ],
      ),
    );

    final details = CatalogFormSection(
      title: 'تفاصيل المنتج',
      icon: Icons.inventory_2_outlined,
      child: Column(
        children: [
          CatalogFieldCard(
            dense: true,
            label: 'السعر',
            icon: Icons.payments_outlined,
            child: TextFormField(
              key: const Key('catalog-price'),
              controller: _price,
              textAlign: TextAlign.start,
              style: _valueStyle(context),
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              validator: (value) => parseOptionalCatalogMoney(value) == null
                  ? 'أدخل سعراً صحيحاً'
                  : null,
              decoration: catalogInputDecoration(context, hint: '0'),
            ),
          ),
          const SizedBox(height: 6),
          CatalogFieldCard(
            dense: true,
            label: 'الحد الأدنى للعامل',
            icon: Icons.arrow_downward_rounded,
            child: TextFormField(
              key: const Key('catalog-worker-min-price'),
              controller: _workerMinimumPrice,
              textAlign: TextAlign.start,
              style: _valueStyle(context),
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              validator: (value) {
                if (value == null || value.trim().isEmpty) return null;
                return parseOptionalCatalogMoney(value) == null
                    ? 'أدخل سعراً صحيحاً'
                    : null;
              },
              decoration: catalogInputDecoration(context, hint: 'غير محدد'),
            ),
          ),
        ],
      ),
    );

    const inventoryPolicies = [
      'not_tracked',
      'finite_stock',
      'unlimited',
      'on_request',
    ];
    final extra = CatalogFormSection(
      title: 'تفاصيل إضافية',
      icon: Icons.tune_outlined,
      child: Column(
        children: [
          CatalogSelectField<String>(
            dense: true,
            label: l10n.catalogInventoryPolicy,
            icon: Icons.inventory_2_outlined,
            value: selectableInventoryPolicy(_inventoryPolicy),
            onChanged: (value) => setState(
              () => _inventoryPolicy = selectableInventoryPolicy(value),
            ),
            items: [
              for (final p in inventoryPolicies)
                DropdownMenuItem(
                  value: p,
                  child: Text(l10n.inventoryPolicyLabel(p)),
                ),
            ],
          ),
          if (l10n.inventoryPolicyDescription(_inventoryPolicy) != null) ...[
            const SizedBox(height: 6),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: Text(
                l10n.inventoryPolicyDescription(_inventoryPolicy)!,
                textAlign: TextAlign.start,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: HasimTokens.mutedColor(context),
                  height: 1.45,
                ),
              ),
            ),
          ],
          if (_inventoryPolicy == 'finite_stock') ...[
            const SizedBox(height: 8),
            CatalogFieldCard(
              dense: true,
              label: l10n.catalogStockQuantity,
              icon: Icons.numbers_outlined,
              child: TextFormField(
                key: const Key('catalog-stock'),
                controller: _stock,
                keyboardType: TextInputType.number,
                decoration: catalogInputDecoration(context, hint: '0'),
              ),
            ),
          ],
          const SizedBox(height: 6),
          CatalogSelectField<int?>(
            dense: true,
            label: l10n.catalogItemCategory,
            icon: Icons.category_outlined,
            value: _categoryId,
            onChanged: (value) => setState(() => _categoryId = value),
            items: [
              DropdownMenuItem<int?>(
                value: null,
                child: Text(l10n.catalogItemNoCategory),
              ),
              for (final c in categories)
                DropdownMenuItem<int?>(value: c.id, child: Text(c.name)),
            ],
          ),
          const SizedBox(height: 6),
          CatalogSelectField<String>(
            dense: true,
            label: 'العملة',
            icon: Icons.monetization_on_outlined,
            value:
                _currencyOptions.contains(_currency.text.trim().toUpperCase())
                ? _currency.text.trim().toUpperCase()
                : _currencyOptions.first,
            onChanged: (value) {
              _currency.text = (value ?? 'SAR').toUpperCase();
              setState(() {});
            },
            items: [
              for (final code in _currencyOptions)
                DropdownMenuItem(
                  value: code,
                  child: Text(code, textDirection: TextDirection.ltr),
                ),
            ],
          ),
        ],
      ),
    );

    final variants = CatalogVariantsEditor(
      variants: _variants,
      defaultInventoryPolicy: _inventoryPolicy,
      defaultCurrency: _currency.text.trim().isEmpty
          ? 'SAR'
          : _currency.text.trim().toUpperCase(),
      currencies: _currencyOptions,
      onChanged: (next) => setState(() => _variants = next),
    );

    final header = Padding(
      padding: const EdgeInsets.fromLTRB(4, 4, 4, 12),
      child: Row(
        children: [
          Container(
            width: 42,
            height: 42,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: HasimTokens.mutedColor(context).withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(headerIcon, color: HasimTokens.mutedColor(context)),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                    color: HasimTokens.inkColor(context),
                  ),
                ),
                Text(
                  subtitle,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 12,
                    color: HasimTokens.mutedColor(context),
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  'مرجع لمعلومات الذكاء الاصطناعي',
                  key: const Key('catalog-product-type-label'),
                  style: TextStyle(
                    color: HasimTokens.chatGreen,
                    fontSize: 12,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );

    return Scaffold(
      backgroundColor: widget.embedded ? Colors.transparent : page,
      resizeToAvoidBottomInset: true,
      appBar: widget.embedded
          ? null
          : AppBar(
              automaticallyImplyLeading: false,
              backgroundColor: page,
              surfaceTintColor: Colors.transparent,
              elevation: 0,
              leading: IconButton(
                tooltip: 'رجوع',
                onPressed: () => context.pop(),
                icon: const Icon(Icons.arrow_forward_rounded),
              ),
            ),
      body: Form(
        key: _formKey,
        autovalidateMode: AutovalidateMode.onUserInteraction,
        child: Column(
          children: [
            Expanded(
              child: LayoutBuilder(
                builder: (context, constraints) {
                  final sideBySide =
                      !HasimLayout.isCompact(context) &&
                      constraints.maxWidth >= HasimLayout.splitMin;
                  final primary = Column(
                    children: [basics, const SizedBox(height: 12), details],
                  );
                  return ListView(
                    padding: const EdgeInsets.fromLTRB(12, 8, 12, 24),
                    keyboardDismissBehavior:
                        ScrollViewKeyboardDismissBehavior.onDrag,
                    children: [
                      if (!compact || !widget.embedded) header,
                      if (sideBySide)
                        Table(
                          defaultVerticalAlignment:
                              TableCellVerticalAlignment.top,
                          columnWidths: const {
                            0: FlexColumnWidth(1),
                            1: FlexColumnWidth(1),
                          },
                          children: [
                            TableRow(
                              children: [
                                Padding(
                                  padding: const EdgeInsetsDirectional.only(
                                    end: 6,
                                    bottom: 12,
                                  ),
                                  child: basics,
                                ),
                                Padding(
                                  padding: const EdgeInsetsDirectional.only(
                                    start: 6,
                                    bottom: 12,
                                  ),
                                  child: _imageSection(),
                                ),
                              ],
                            ),
                            TableRow(
                              children: [
                                Padding(
                                  padding: const EdgeInsetsDirectional.only(
                                    end: 6,
                                    bottom: 12,
                                  ),
                                  child: details,
                                ),
                                Padding(
                                  padding: const EdgeInsetsDirectional.only(
                                    start: 6,
                                    bottom: 12,
                                  ),
                                  child: extra,
                                ),
                              ],
                            ),
                          ],
                        )
                      else ...[
                        _imageSection(),
                        const SizedBox(height: 12),
                        extra,
                        const SizedBox(height: 12),
                        primary,
                      ],
                      const SizedBox(height: 12),
                      variants,
                    ],
                  );
                },
              ),
            ),
            SafeArea(
              top: false,
              child: LayoutBuilder(
                builder: (context, constraints) {
                  final compact =
                      HasimLayout.isCompact(context) ||
                      !HasimLayout.pairFormFields(constraints.maxWidth);
                  final buttonStyle = ButtonStyle(
                    visualDensity: compact
                        ? VisualDensity.compact
                        : VisualDensity.standard,
                    tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    minimumSize: WidgetStatePropertyAll(
                      Size(compact ? 0 : 64, compact ? 40 : 48),
                    ),
                    padding: WidgetStatePropertyAll(
                      EdgeInsets.symmetric(
                        horizontal: compact ? 12 : 16,
                        vertical: compact ? 10 : 12,
                      ),
                    ),
                    textStyle: WidgetStatePropertyAll(
                      TextStyle(
                        fontSize: compact ? 13 : 14,
                        fontWeight: FontWeight.w800,
                        height: 1.1,
                      ),
                    ),
                  );
                  final save = FilledButton(
                    key: const Key('catalog-save'),
                    style: buttonStyle,
                    onPressed: _canSave && !_busy ? _save : null,
                    child: _saving
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: Colors.white,
                            ),
                          )
                        : const Text(
                            'حفظ المنتج',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                  );
                  final cancel = OutlinedButton(
                    style: buttonStyle,
                    onPressed: _busy ? null : _cancel,
                    child: const Text('إلغاء', maxLines: 1),
                  );
                  final status = Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        'نشط',
                        style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w800,
                          color: HasimTokens.inkColor(context),
                        ),
                      ),
                      const SizedBox(width: 4),
                      Switch(
                        key: const Key('catalog-active'),
                        value: _active,
                        activeThumbColor: Colors.white,
                        activeTrackColor: AppTheme.brand,
                        inactiveThumbColor: Colors.white,
                        inactiveTrackColor: HasimTokens.isDark(context)
                            ? const Color(0xFF3A3A3A)
                            : const Color(0xFFD0D6D5),
                        materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                        onChanged: _busy
                            ? null
                            : (value) => setState(() => _active = value),
                      ),
                      const SizedBox(width: 8),
                      Text(
                        '$_readyCount/3',
                        key: const Key('catalog-progress'),
                        style: Theme.of(context).textTheme.labelLarge?.copyWith(
                          color: HasimTokens.mutedColor(context),
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  );
                  final delete = _editing
                      ? TextButton(
                          key: const Key('catalog-delete'),
                          style: buttonStyle,
                          onPressed: _busy ? null : _confirmDelete,
                          child: _deleting
                              ? const SizedBox(
                                  width: 16,
                                  height: 16,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                  ),
                                )
                              : Text(
                                  'حذف المنتج',
                                  style: TextStyle(color: HasimTokens.danger),
                                ),
                        )
                      : null;
                  return Padding(
                    padding: EdgeInsets.fromLTRB(
                      compact ? 10 : 12,
                      compact ? 8 : 8,
                      compact ? 10 : 12,
                      compact ? 10 : 12,
                    ),
                    child: compact
                        ? Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              Row(
                                children: [
                                  Expanded(child: save),
                                  const SizedBox(width: 8),
                                  status,
                                ],
                              ),
                              if (delete != null) ...[
                                const SizedBox(height: 4),
                                Align(
                                  alignment: AlignmentDirectional.centerStart,
                                  child: delete,
                                ),
                              ],
                            ],
                          )
                        : Row(
                            children: [
                              Expanded(child: save),
                              const SizedBox(width: 10),
                              cancel,
                              if (delete != null) ...[
                                const SizedBox(width: 4),
                                delete,
                              ],
                              const SizedBox(width: 12),
                              status,
                            ],
                          ),
                  );
                },
              ),
            ),
          ],
        ),
      ),
    );
  }
}
