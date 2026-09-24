import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_primary_button.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/presentation/catalog_inventory_l10n.dart';
import 'package:hasim/features/catalog/presentation/widgets/catalog_form_fields.dart';
import 'package:hasim/features/catalog/presentation/widgets/catalog_variants_editor.dart';
import 'package:hasim/features/catalog/providers/chat_catalog_categories_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';

class CatalogFormScreen extends ConsumerStatefulWidget {
  const CatalogFormScreen({super.key, this.item});

  final ChatCatalogItemModel? item;

  @override
  ConsumerState<CatalogFormScreen> createState() => _CatalogFormScreenState();
}

class _CatalogFormScreenState extends ConsumerState<CatalogFormScreen> {
  static const _currencies = ['SAR', 'AED', 'KWD', 'BHD', 'OMR', 'QAR', 'USD', 'EUR'];

  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _name;
  late final TextEditingController _description;
  late final TextEditingController _sku;
  late final TextEditingController _price;
  late final TextEditingController _currency;
  late final TextEditingController _stock;
  String _kind = 'product';
  String _tangibility = 'tangible';
  String _inventoryPolicy = 'not_tracked';
  int? _categoryId;
  List<ChatCatalogVariantModel> _variants = const [];
  bool _active = true;
  bool _saving = false;

  bool get _editing => widget.item != null;

  @override
  void initState() {
    super.initState();
    final item = widget.item;
    _name = TextEditingController(text: item?.name ?? '');
    _description = TextEditingController(text: item?.description ?? '');
    _sku = TextEditingController(text: item?.sku ?? '');
    _price = TextEditingController(text: item?.price.toString() ?? '0');
    _currency = TextEditingController(text: item?.currency ?? 'SAR');
    _stock = TextEditingController(text: item?.stock?.toString() ?? '');
    _kind = item?.itemKind ?? 'product';
    _tangibility = item?.tangibility ?? 'tangible';
    _inventoryPolicy = item?.inventoryPolicy ?? 'not_tracked';
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
    _currency.dispose();
    _stock.dispose();
    super.dispose();
  }

  bool get _nameOk => _name.text.trim().isNotEmpty;
  bool get _priceOk => num.tryParse(_price.text.trim()) != null;
  bool get _currencyOk => _currency.text.trim().isNotEmpty;
  int get _readyCount => (_nameOk ? 1 : 0) + (_priceOk ? 1 : 0) + (_currencyOk ? 1 : 0);
  bool get _canSave => !_saving && _nameOk && _priceOk;

  List<String> get _currencyOptions {
    final current = _currency.text.trim().toUpperCase();
    final options = [..._currencies];
    if (current.isNotEmpty && !options.contains(current)) {
      options.insert(0, current);
    }
    return options;
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    final l10n = AppLocalizations.of(context)!;
    try {
      final repo = ref.read(chatCatalogRepositoryProvider);
      final price = num.tryParse(_price.text.trim()) ?? 0;
      final stock = int.tryParse(_stock.text.trim());
      final variants = _variants.isEmpty ? null : _variants;
      if (_editing) {
        await repo.update(
          widget.item!.id,
          name: _name.text.trim(),
          description: _description.text.trim(),
          sku: _sku.text.trim(),
          itemKind: _kind,
          tangibility: _tangibility,
          price: price,
          currency: _currency.text.trim().toUpperCase(),
          stock: stock,
          inventoryPolicy: _inventoryPolicy,
          isActive: _active,
          categoryId: _categoryId,
          clearCategory: _categoryId == null,
          variants: variants,
        );
      } else {
        await repo.create(
          name: _name.text.trim(),
          description: _description.text.trim(),
          sku: _sku.text.trim(),
          itemKind: _kind,
          tangibility: _tangibility,
          price: price,
          currency: _currency.text.trim().toUpperCase(),
          stock: stock,
          inventoryPolicy: _inventoryPolicy,
          isActive: _active,
          categoryId: _categoryId,
          variants: variants,
        );
      }
      if (!mounted) return;
      context.pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(l10n.catalogItemSaveFailed)));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final categories = ref.watch(chatCatalogCategoriesControllerProvider.select((s) => s.items));
    final mint = HasimTokens.pageBg(context);
    final title = _editing ? 'تعديل صنف' : 'إضافة صنف';

    return Scaffold(
      backgroundColor: mint,
      resizeToAvoidBottomInset: true,
      appBar: AppBar(
        automaticallyImplyLeading: false,
        backgroundColor: mint,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        centerTitle: true,
        leading: IconButton(
          tooltip: 'رجوع',
          onPressed: () => context.pop(),
          icon: const Icon(Icons.arrow_forward_rounded),
        ),
        title: Text(
          title,
          style: Theme.of(context).textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w800,
                color: HasimTokens.inkColor(context),
              ),
        ),
      ),
      body: Stack(
        children: [
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            height: 120,
            child: IgnorePointer(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [
                      HasimTokens.mintLight.withValues(alpha: HasimTokens.isDark(context) ? 0.12 : 0.7),
                      mint.withValues(alpha: 0),
                    ],
                  ),
                ),
              ),
            ),
          ),
          Form(
            key: _formKey,
            autovalidateMode: AutovalidateMode.onUserInteraction,
            child: Column(
              children: [
                Expanded(
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                    keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                    children: [
                      CatalogFieldCard(
                        label: 'الاسم',
                        icon: Icons.sell_outlined,
                        child: TextFormField(
                          key: const Key('catalog-name'),
                          controller: _name,
                          textAlign: TextAlign.start,
                          validator: (value) =>
                              (value == null || value.trim().isEmpty) ? 'الاسم مطلوب' : null,
                          style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                                fontWeight: FontWeight.w700,
                                color: HasimTokens.inkColor(context),
                              ),
                          decoration: catalogInputDecoration(context, hint: 'أدخل اسم الصنف...'),
                        ),
                      ),
                      const SizedBox(height: 12),
                      CatalogFieldCard(
                        label: 'الوصف',
                        icon: Icons.description_outlined,
                        minHeight: 120,
                        child: TextFormField(
                          key: const Key('catalog-description'),
                          controller: _description,
                          textAlign: TextAlign.start,
                          minLines: 3,
                          maxLines: 5,
                          style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                                fontWeight: FontWeight.w600,
                                color: HasimTokens.inkColor(context),
                              ),
                          decoration: catalogInputDecoration(context, hint: 'أدخل وصف الصنف...'),
                        ),
                      ),
                      const SizedBox(height: 12),
                      CatalogFieldCard(
                        label: 'المرجع / SKU',
                        icon: Icons.tag,
                        child: TextFormField(
                          key: const Key('catalog-sku'),
                          controller: _sku,
                          textAlign: TextAlign.start,
                          style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                                fontWeight: FontWeight.w700,
                                color: HasimTokens.inkColor(context),
                              ),
                          decoration: catalogInputDecoration(
                            context,
                            hint: 'أدخل المرجع أو رمز الصنف...',
                          ),
                        ),
                      ),
                      const SizedBox(height: 12),
                      CatalogSelectField<String>(
                        label: 'النوع',
                        icon: Icons.inventory_2_outlined,
                        value: _kind,
                        onChanged: (value) => setState(() => _kind = value ?? 'product'),
                        items: const [
                          DropdownMenuItem(value: 'product', child: Text('منتج')),
                          DropdownMenuItem(value: 'service', child: Text('خدمة')),
                        ],
                      ),
                      const SizedBox(height: 12),
                      CatalogSelectField<String>(
                        label: 'الطبيعة',
                        icon: Icons.layers_outlined,
                        value: _tangibility,
                        onChanged: (value) => setState(() => _tangibility = value ?? 'tangible'),
                        items: const [
                          DropdownMenuItem(value: 'tangible', child: Text('ملموس')),
                          DropdownMenuItem(value: 'intangible', child: Text('غير ملموس / رقمي')),
                        ],
                      ),
                      const SizedBox(height: 12),
                      CatalogFieldCard(
                        label: 'السعر',
                        icon: Icons.payments_outlined,
                        child: TextFormField(
                          key: const Key('catalog-price'),
                          controller: _price,
                          textAlign: TextAlign.start,
                          style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                                fontWeight: FontWeight.w700,
                                color: HasimTokens.inkColor(context),
                              ),
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          validator: (value) =>
                              num.tryParse(value ?? '') == null ? 'أدخل سعراً صحيحاً' : null,
                          decoration: catalogInputDecoration(context, hint: '0'),
                        ),
                      ),
                      const SizedBox(height: 12),
                      CatalogSelectField<int?>(
                        label: l10n.catalogItemCategory,
                        icon: Icons.category_outlined,
                        value: _categoryId,
                        onChanged: (value) => setState(() => _categoryId = value),
                        items: [
                          DropdownMenuItem<int?>(value: null, child: Text(l10n.catalogItemNoCategory)),
                          for (final c in categories)
                            DropdownMenuItem<int?>(value: c.id, child: Text(c.name)),
                        ],
                      ),
                      const SizedBox(height: 12),
                      CatalogSelectField<String>(
                        label: l10n.catalogInventoryPolicy,
                        icon: Icons.inventory_2_outlined,
                        value: chatCatalogInventoryPolicies.contains(_inventoryPolicy)
                            ? _inventoryPolicy
                            : 'not_tracked',
                        onChanged: (value) => setState(() => _inventoryPolicy = value ?? 'not_tracked'),
                        items: [
                          for (final p in chatCatalogInventoryPolicies)
                            DropdownMenuItem(
                              value: p,
                              child: Text(l10n.inventoryPolicyLabel(p)),
                            ),
                        ],
                      ),
                      if (_inventoryPolicy == 'finite_stock') ...[
                        const SizedBox(height: 12),
                        CatalogFieldCard(
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
                      const SizedBox(height: 12),
                      CatalogSelectField<String>(
                        label: 'العملة',
                        icon: Icons.monetization_on_outlined,
                        value: _currencyOptions.contains(_currency.text.trim().toUpperCase())
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
                      const SizedBox(height: 12),
                      CatalogVariantsEditor(
                        variants: _variants,
                        defaultInventoryPolicy: _inventoryPolicy,
                        onChanged: (next) => setState(() => _variants = next),
                      ),
                      const SizedBox(height: 16),
                      Row(
                        children: [
                          Text(
                            'نشط',
                            style: Theme.of(context).textTheme.titleSmall?.copyWith(
                                  fontWeight: FontWeight.w800,
                                  color: HasimTokens.inkColor(context),
                                ),
                          ),
                          const SizedBox(width: 10),
                          Switch(
                            key: const Key('catalog-active'),
                            value: _active,
                            activeThumbColor: Colors.white,
                            activeTrackColor: AppTheme.brand,
                            inactiveThumbColor: Colors.white,
                            inactiveTrackColor: const Color(0xFFD0D6D5),
                            onChanged: (value) => setState(() => _active = value),
                          ),
                          const Spacer(),
                          Text(
                            '$_readyCount/3',
                            key: const Key('catalog-progress'),
                            style: Theme.of(context).textTheme.labelLarge?.copyWith(
                                  color: HasimTokens.mutedColor(context),
                                  fontWeight: FontWeight.w700,
                                ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                SafeArea(
                  top: false,
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                    child: HasimPrimaryButton(
                      key: const Key('catalog-save'),
                      label: _saving ? 'جارٍ الحفظ...' : 'حفظ',
                      icon: Icons.save_outlined,
                      expanded: true,
                      enabled: _canSave,
                      busy: _saving,
                      onPressed: _save,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
