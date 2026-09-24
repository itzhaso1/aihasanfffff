import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/presentation/widgets/catalog_form_fields.dart';
import 'package:hasim/features/catalog/presentation/catalog_inventory_l10n.dart';
import 'package:hasim/l10n/app_localizations.dart';

class CatalogVariantsEditor extends StatelessWidget {
  const CatalogVariantsEditor({
    super.key,
    required this.variants,
    required this.onChanged,
    this.defaultInventoryPolicy,
  });

  final List<ChatCatalogVariantModel> variants;
  final ValueChanged<List<ChatCatalogVariantModel>> onChanged;
  final String? defaultInventoryPolicy;

  void _addVariant(AppLocalizations l10n) {
    onChanged([
      ...variants,
      ChatCatalogVariantModel(
        id: 0,
        name: l10n.catalogVariantDefaultName(variants.length + 1),
        inventoryPolicy: defaultInventoryPolicy ?? 'not_tracked',
        isActive: true,
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
    final l10n = AppLocalizations.of(context)!;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Text(
              l10n.catalogVariantsTitle,
              style: Theme.of(context).textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: HasimTokens.inkColor(context),
                  ),
            ),
            const Spacer(),
            TextButton.icon(
              key: const Key('catalog-variant-add'),
              onPressed: () => _addVariant(l10n),
              icon: const Icon(Icons.add_rounded, size: 18),
              label: Text(l10n.catalogVariantAdd),
            ),
          ],
        ),
        if (variants.isEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Text(
              l10n.catalogVariantsEmpty,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: HasimTokens.mutedColor(context),
                  ),
            ),
          ),
        for (var i = 0; i < variants.length; i++) ...[
          const SizedBox(height: 8),
          _VariantCard(
            key: ValueKey('variant-$i-${variants[i].id}'),
            variant: variants[i],
            onChanged: (v) => _update(i, v),
            onRemove: () => _remove(i),
          ),
        ],
      ],
    );
  }
}

class _VariantCard extends StatefulWidget {
  const _VariantCard({
    super.key,
    required this.variant,
    required this.onChanged,
    required this.onRemove,
  });

  final ChatCatalogVariantModel variant;
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
    if (oldWidget.variant.id != widget.variant.id) {
      _syncControllers();
    }
  }

  void _syncControllers() {
    _name = TextEditingController(text: widget.variant.name);
    _sku = TextEditingController(text: widget.variant.sku ?? '');
    _price = TextEditingController(
      text: widget.variant.price == null ? '' : widget.variant.price.toString(),
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

  void _emit() {
    widget.onChanged(
      widget.variant.copyWith(
        name: _name.text.trim(),
        sku: _sku.text.trim().isEmpty ? null : _sku.text.trim(),
        price: num.tryParse(_price.text.trim()),
        stock: int.tryParse(_stock.text.trim()),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final policy = widget.variant.inventoryPolicy ?? 'not_tracked';

    return CatalogFieldCard(
      label: widget.variant.name,
      icon: Icons.layers_outlined,
      trailing: IconButton(
        tooltip: l10n.catalogVariantRemove,
        onPressed: widget.onRemove,
        icon: const Icon(Icons.close_rounded),
      ),
      child: Column(
        children: [
          TextFormField(
            decoration: catalogInputDecoration(context, hint: l10n.catalogVariantName),
            controller: _name,
            onChanged: (_) => _emit(),
          ),
          const SizedBox(height: 8),
          TextFormField(
            decoration: catalogInputDecoration(context, hint: l10n.catalogSku),
            controller: _sku,
            onChanged: (_) => _emit(),
          ),
          const SizedBox(height: 8),
          TextFormField(
            decoration: catalogInputDecoration(context, hint: l10n.catalogPrice),
            controller: _price,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            onChanged: (_) => _emit(),
          ),
          const SizedBox(height: 8),
          CatalogSelectField<String>(
            label: l10n.catalogInventoryPolicy,
            icon: Icons.inventory_outlined,
            value: chatCatalogInventoryPolicies.contains(policy) ? policy : 'not_tracked',
            onChanged: (v) {
              widget.onChanged(widget.variant.copyWith(inventoryPolicy: v ?? 'not_tracked'));
            },
            items: [
              for (final p in chatCatalogInventoryPolicies)
                DropdownMenuItem(value: p, child: Text(l10n.inventoryPolicyLabel(p))),
            ],
          ),
          if (policy == 'finite_stock') ...[
            const SizedBox(height: 8),
            TextFormField(
              decoration: catalogInputDecoration(context, hint: l10n.catalogStockQuantity),
              controller: _stock,
              keyboardType: TextInputType.number,
              onChanged: (_) => _emit(),
            ),
          ],
        ],
      ),
    );
  }
}
