import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/hasim_buttons.dart';
import 'package:hasim/core/widgets/hasim_card.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/presentation/catalog_inventory_l10n.dart';
import 'package:hasim/features/catalog/presentation/widgets/catalog_form_fields.dart';
import 'package:hasim/l10n/app_localizations.dart';

class CatalogVariantsEditor extends StatelessWidget {
  const CatalogVariantsEditor({
    super.key,
    required this.variants,
    required this.onChanged,
    this.defaultInventoryPolicy,
    this.defaultCurrency = 'SAR',
    this.currencies = const [
      'SAR',
      'AED',
      'KWD',
      'BHD',
      'OMR',
      'QAR',
      'USD',
      'EUR',
    ],
    this.showTitle = true,
  });

  final List<ChatCatalogVariantModel> variants;
  final ValueChanged<List<ChatCatalogVariantModel>> onChanged;
  final String? defaultInventoryPolicy;
  final String defaultCurrency;
  final List<String> currencies;
  final bool showTitle;

  void _addVariant() {
    onChanged([
      ...variants,
      ChatCatalogVariantModel(
        id: 0,
        name: '',
        currency: defaultCurrency,
        inventoryPolicy: defaultInventoryPolicy ?? 'finite_stock',
        isActive: true,
        isVisible: true,
      ),
    ]);
  }

  void _update(int index, ChatCatalogVariantModel next) {
    final copy = [...variants];
    copy[index] = next;
    onChanged(copy);
  }

  void _remove(int index) {
    final copy = [...variants]..removeAt(index);
    onChanged(copy);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final compact = HasimLayout.isCompact(context);

    return CatalogFormSection(
      title: showTitle ? l10n.catalogVariantsTitle : '',
      icon: Icons.layers_outlined,
      trailing: HasimBadge(label: '${variants.length}'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: _AddVariantButton(onPressed: _addVariant),
          ),
          SizedBox(height: compact ? HasimTokens.spaceSm : HasimTokens.spaceMd),
          if (variants.isEmpty)
            compact
                ? _CompactVariantsEmptyState(onAdd: _addVariant)
                : HasimCard(
                    padding: const EdgeInsets.symmetric(
                      horizontal: HasimTokens.spaceSm,
                      vertical: HasimTokens.spaceXs,
                    ),
                    backgroundColor: HasimTokens.cardBg(context),
                    borderColor: HasimTokens.lineColor(context),
                    child: EmptyState(
                      title: 'لا توجد نسخ لهذا المنتج',
                      subtitle: l10n.catalogVariantsEmpty,
                      icon: Icons.layers_outlined,
                      actionLabel: l10n.catalogVariantAdd,
                      onAction: _addVariant,
                      actionIcon: Icons.add_rounded,
                    ),
                  )
          else
            for (var i = 0; i < variants.length; i++) ...[
              if (i > 0)
                SizedBox(
                  height: compact ? HasimTokens.spaceSm : HasimTokens.spaceMd,
                ),
              _VariantCard(
                key: ValueKey('variant-$i-${variants[i].id}'),
                index: i,
                variant: variants[i],
                currencies: currencies,
                onChanged: (v) => _update(i, v),
                onRemove: () => _remove(i),
              ),
            ],
        ],
      ),
    );
  }
}

class _CompactVariantsEmptyState extends StatelessWidget {
  const _CompactVariantsEmptyState({required this.onAdd});

  final VoidCallback onAdd;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return HasimCard(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
      backgroundColor: HasimTokens.cardBg(context),
      borderColor: HasimTokens.lineColor(context),
      child: Row(
        textDirection: TextDirection.rtl,
        children: [
          Container(
            width: 36,
            height: 36,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: HasimTokens.mintFill(context),
              borderRadius: BorderRadius.circular(11),
            ),
            child: Icon(
              Icons.layers_outlined,
              size: 19,
              color: HasimTokens.chatGreen,
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  'لا توجد نسخ لهذا المنتج',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.labelLarge?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: HasimTokens.inkColor(context),
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  l10n.catalogVariantsEmpty,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: HasimTokens.mutedColor(context),
                    fontSize: 11,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 6),
          IconButton(
            onPressed: onAdd,
            tooltip: l10n.catalogVariantAdd,
            style: IconButton.styleFrom(
              backgroundColor: HasimTokens.mintFill(context),
              foregroundColor: HasimTokens.chatGreen,
              visualDensity: VisualDensity.compact,
            ),
            icon: const Icon(Icons.add_rounded, size: 19),
          ),
        ],
      ),
    );
  }
}

class _AddVariantButton extends StatelessWidget {
  const _AddVariantButton({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return Tooltip(
      message: l10n.catalogVariantAdd,
      child: Material(
        color: HasimTokens.mintFill(context),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
          side: BorderSide(color: AppTheme.brand.withValues(alpha: 0.22)),
        ),
        child: InkWell(
          key: const Key('catalog-variant-add'),
          onTap: onPressed,
          borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
          child: Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: HasimTokens.spaceSm,
              vertical: 8,
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  Icons.add_rounded,
                  size: 18,
                  color: HasimTokens.mutedColor(context),
                ),
                const SizedBox(width: 6),
                Text(
                  l10n.catalogVariantAdd,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.labelLarge?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: AppTheme.brand,
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

class _VariantCard extends StatefulWidget {
  const _VariantCard({
    super.key,
    required this.index,
    required this.variant,
    required this.currencies,
    required this.onChanged,
    required this.onRemove,
  });

  final int index;
  final ChatCatalogVariantModel variant;
  final List<String> currencies;
  final ValueChanged<ChatCatalogVariantModel> onChanged;
  final VoidCallback onRemove;

  @override
  State<_VariantCard> createState() => _VariantCardState();
}

class _VariantCardState extends State<_VariantCard> {
  late TextEditingController _name;
  late TextEditingController _sku;
  late TextEditingController _price;
  late TextEditingController _stock;

  @override
  void initState() {
    super.initState();
    _syncControllers();
  }

  @override
  void didUpdateWidget(covariant _VariantCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.variant.id != widget.variant.id ||
        oldWidget.index != widget.index) {
      _name.dispose();
      _sku.dispose();
      _price.dispose();
      _stock.dispose();
      _syncControllers();
    }
  }

  void _syncControllers() {
    _name = TextEditingController(text: widget.variant.name);
    _sku = TextEditingController(text: widget.variant.sku ?? '');
    _price = TextEditingController(
      text: widget.variant.price == null
          ? ''
          : formatCatalogMoney(widget.variant.price!),
    );
    _stock = TextEditingController(
      text: widget.variant.stock == null ? '' : widget.variant.stock.toString(),
    );
  }

  @override
  void dispose() {
    _name.dispose();
    _sku.dispose();
    _price.dispose();
    _stock.dispose();
    super.dispose();
  }

  void _emit({String? inventoryPolicy, String? currency, bool? isActive}) {
    widget.onChanged(
      widget.variant.copyWith(
        name: _name.text.trim(),
        sku: _sku.text.trim(),
        price: parseOptionalCatalogMoney(_price.text),
        stock: int.tryParse(_stock.text.trim()),
        inventoryPolicy: selectableInventoryPolicy(
          inventoryPolicy ?? widget.variant.inventoryPolicy,
          fallback: 'finite_stock',
        ),
        currency: currency ?? widget.variant.currency,
        isActive: isActive ?? widget.variant.isActive,
        isVisible: widget.variant.isVisible,
      ),
    );
  }

  Widget _field({
    required String label,
    required IconData icon,
    required Widget child,
  }) {
    return CatalogFieldCard(
      dense: true,
      label: label,
      icon: icon,
      child: child,
    );
  }

  Widget _input(
    TextEditingController controller, {
    Key? key,
    String? hint,
    bool number = false,
    bool decimal = false,
  }) {
    return TextFormField(
      key: key,
      controller: controller,
      onChanged: (_) => _emit(),
      keyboardType: number
          ? TextInputType.numberWithOptions(decimal: decimal)
          : TextInputType.text,
      style: const TextStyle(
        fontSize: 13,
        fontWeight: FontWeight.w600,
        height: 1.1,
      ),
      decoration: catalogInputDecoration(context, hint: hint),
    );
  }

  List<Widget> _grid(List<Widget> items, int columns) {
    final gap = HasimLayout.isCompact(context)
        ? HasimTokens.spaceXs / 1.5
        : HasimTokens.spaceXs;
    final rows = <Widget>[];
    for (var i = 0; i < items.length; i += columns) {
      final slice = items.sublist(
        i,
        i + columns > items.length ? items.length : i + columns,
      );
      rows.add(
        Padding(
          padding: EdgeInsets.only(
            bottom: i + columns < items.length ? HasimTokens.spaceXs : 0,
          ),
          child: Row(
            children: [
              for (var c = 0; c < columns; c++) ...[
                if (c > 0) SizedBox(width: gap),
                Expanded(
                  child: c < slice.length ? slice[c] : const SizedBox.shrink(),
                ),
              ],
            ],
          ),
        ),
      );
    }
    return rows;
  }

  Widget _section({
    required String title,
    required IconData icon,
    required List<Widget> children,
  }) {
    final compact = HasimLayout.isCompact(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Icon(icon, size: 16, color: HasimTokens.mutedColor(context)),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: HasimTokens.inkColor(context),
                ),
              ),
            ),
          ],
        ),
        SizedBox(height: compact ? HasimTokens.spaceXs : HasimTokens.spaceSm),
        ...children,
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final compact = HasimLayout.isCompact(context);
    final policy = selectableInventoryPolicy(
      widget.variant.inventoryPolicy,
      fallback: 'finite_stock',
    );
    final currency = widget.variant.currency ?? 'SAR';
    final name = widget.variant.name.trim();
    final numberLabel = l10n.catalogVariantDefaultName(widget.index + 1);

    return HasimCard(
      padding: EdgeInsets.fromLTRB(
        compact ? HasimTokens.spaceSm : HasimTokens.spaceMd,
        compact ? HasimTokens.spaceXs : HasimTokens.spaceSm,
        compact ? HasimTokens.spaceSm : HasimTokens.spaceMd,
        compact ? HasimTokens.spaceSm : HasimTokens.spaceMd,
      ),
      backgroundColor: HasimTokens.cardBg(context),
      borderColor: HasimTokens.lineColor(context),
      showShadow: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _VariantHeader(
            index: widget.index,
            numberLabel: numberLabel,
            name: name,
            isActive: widget.variant.isActive,
            onRemove: widget.onRemove,
          ),
          SizedBox(height: compact ? HasimTokens.spaceSm : HasimTokens.spaceMd),
          LayoutBuilder(
            builder: (context, constraints) {
              final twoCol = compact
                  ? constraints.maxWidth >= 380
                  : HasimLayout.pairFormFields(constraints.maxWidth);
              final identityColumns = compact ? 1 : (twoCol ? 2 : 1);
              final pairColumns = twoCol ? 2 : 1;
              final sectionGap = compact
                  ? HasimTokens.spaceSm
                  : HasimTokens.spaceMd;

              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _section(
                    title: 'الهوية',
                    icon: Icons.badge_outlined,
                    children: _grid([
                      _field(
                        label: l10n.catalogVariantName,
                        icon: Icons.sell_outlined,
                        child: _input(
                          _name,
                          key: Key('catalog-variant-name-${widget.index}'),
                          hint: 'مثال: مقاس كبير - أحمر',
                        ),
                      ),
                      _field(
                        label: '${l10n.catalogSku} النسخة',
                        icon: Icons.tag,
                        child: _input(_sku, hint: 'SKU-VAR-1'),
                      ),
                    ], identityColumns),
                  ),
                  SizedBox(height: sectionGap),
                  _section(
                    title: l10n.catalogPrice,
                    icon: Icons.payments_outlined,
                    children: [
                      ..._grid([
                        _field(
                          label: l10n.catalogPrice,
                          icon: Icons.payments_outlined,
                          child: _input(
                            _price,
                            hint: '0.00',
                            number: true,
                            decimal: true,
                          ),
                        ),
                        CatalogSelectField<String>(
                          dense: true,
                          label: 'العملة',
                          icon: Icons.payments_outlined,
                          value: widget.currencies.contains(currency)
                              ? currency
                              : widget.currencies.first,
                          onChanged: (value) => _emit(currency: value),
                          items: [
                            for (final code in widget.currencies)
                              DropdownMenuItem(
                                value: code,
                                child: Text(
                                  code,
                                  textDirection: TextDirection.ltr,
                                ),
                              ),
                          ],
                        ),
                      ], pairColumns),
                    ],
                  ),
                  SizedBox(height: sectionGap),
                  _section(
                    title: 'المخزون',
                    icon: Icons.inventory_2_outlined,
                    children: [
                      ..._grid([
                        _field(
                          label: 'المخزون',
                          icon: Icons.inventory_outlined,
                          child: _input(_stock, hint: '0', number: true),
                        ),
                        CatalogSelectField<String>(
                          dense: true,
                          label: l10n.catalogInventoryPolicy,
                          icon: Icons.inventory_2_outlined,
                          value: policy,
                          onChanged: (value) => _emit(
                            inventoryPolicy: selectableInventoryPolicy(
                              value,
                              fallback: 'finite_stock',
                            ),
                          ),
                          items: [
                            for (final p in chatCatalogInventoryPolicies)
                              DropdownMenuItem(
                                value: p,
                                child: Text(l10n.inventoryPolicyLabel(p)),
                              ),
                          ],
                        ),
                      ], pairColumns),
                      if (l10n.inventoryPolicyDescription(policy) != null)
                        Padding(
                          padding: EdgeInsets.only(top: compact ? 4 : 6),
                          child: Text(
                            l10n.inventoryPolicyDescription(policy)!,
                            textAlign: TextAlign.start,
                            style: Theme.of(context).textTheme.bodySmall
                                ?.copyWith(
                                  color: HasimTokens.mutedColor(context),
                                  height: 1.45,
                                ),
                          ),
                        ),
                    ],
                  ),
                  SizedBox(height: sectionGap),
                  _section(
                    title: 'الحالة',
                    icon: Icons.toggle_on_outlined,
                    children: [
                      _StatusToggle(
                        label: 'نشط',
                        value: widget.variant.isActive,
                        activeText: 'متاحة للبيع',
                        inactiveText: 'غير متاحة',
                        icon: Icons.bolt_outlined,
                        onChanged: (value) => _emit(isActive: value),
                      ),
                    ],
                  ),
                ],
              );
            },
          ),
        ],
      ),
    );
  }
}

class _VariantHeader extends StatelessWidget {
  const _VariantHeader({
    required this.index,
    required this.numberLabel,
    required this.name,
    required this.isActive,
    required this.onRemove,
  });

  final int index;
  final String numberLabel;
  final String name;
  final bool isActive;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final muted = HasimTokens.mutedColor(context);

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 36,
          height: 36,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: HasimTokens.mintFill(context),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Text(
            '${index + 1}',
            style: Theme.of(context).textTheme.labelLarge?.copyWith(
              fontWeight: FontWeight.w800,
              color: AppTheme.brand,
            ),
          ),
        ),
        const SizedBox(width: HasimTokens.spaceSm),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                numberLabel,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.labelMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: muted,
                ),
              ),
              Text(
                name.isEmpty ? l10n.catalogVariantName : name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: name.isEmpty ? muted : HasimTokens.inkColor(context),
                ),
              ),
              const SizedBox(height: 6),
              _MiniStatusChip(
                label: isActive ? 'نشط' : 'غير نشط',
                active: isActive,
              ),
            ],
          ),
        ),
        FittedBox(
          fit: BoxFit.scaleDown,
          child: HasimTextLinkButton(
            key: Key('catalog-variant-remove-$index'),
            label: l10n.catalogVariantRemove,
            icon: Icons.delete_outline_rounded,
            color: HasimTokens.danger,
            onPressed: onRemove,
          ),
        ),
      ],
    );
  }
}

class _MiniStatusChip extends StatelessWidget {
  const _MiniStatusChip({required this.label, required this.active});

  final String label;
  final bool active;

  @override
  Widget build(BuildContext context) {
    final color = active ? AppTheme.brand : HasimTokens.mutedColor(context);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: active
            ? HasimTokens.mintFill(context)
            : HasimTokens.lineColor(context).withValues(alpha: 0.45),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w800,
          color: color,
        ),
      ),
    );
  }
}

class _StatusToggle extends StatelessWidget {
  const _StatusToggle({
    required this.label,
    required this.value,
    required this.activeText,
    required this.inactiveText,
    required this.icon,
    required this.onChanged,
  });

  final String label;
  final bool value;
  final String activeText;
  final String inactiveText;
  final IconData icon;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: '$label: ${value ? activeText : inactiveText}',
      toggled: value,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: value
              ? HasimTokens.mintFill(context)
              : HasimTokens.cardBg(context),
          borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
          border: Border.all(
            color: value
                ? AppTheme.brand.withValues(alpha: 0.28)
                : HasimTokens.lineColor(context),
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(10, 8, 8, 8),
          child: Row(
            children: [
              Container(
                width: 28,
                height: 28,
                decoration: BoxDecoration(
                  color: HasimTokens.cardBg(context),
                  shape: BoxShape.circle,
                ),
                child: Icon(
                  icon,
                  size: 16,
                  color: HasimTokens.mutedColor(context),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.labelLarge?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: HasimTokens.inkColor(context),
                      ),
                    ),
                    Text(
                      value ? activeText : inactiveText,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: HasimTokens.mutedColor(context),
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
              Switch(
                value: value,
                activeThumbColor: Colors.white,
                activeTrackColor: AppTheme.brand,
                materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                onChanged: onChanged,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
