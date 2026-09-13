import 'package:flutter/material.dart';
import 'package:hasim_finance/core/layout/finance_layout.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class LineDraft {
  LineDraft({
    String description = '',
    String quantity = '1',
    String unitPrice = '0',
    String unit = '',
    String taxRate = '15',
    String discount = '0',
    this.productId,
    this.taxProfileType,
    String exemptionReason = '',
    String exemptionCode = '',
  })  : description = TextEditingController(text: description),
        quantity = TextEditingController(text: quantity),
        unitPrice = TextEditingController(text: unitPrice),
        unit = TextEditingController(text: unit),
        taxRate = TextEditingController(text: taxRate),
        discount = TextEditingController(text: discount),
        exemptionReason = TextEditingController(text: exemptionReason),
        exemptionCode = TextEditingController(text: exemptionCode);

  factory LineDraft.fromItem(LineItem item) {
    return LineDraft(
      description: item.productName ?? item.description ?? '',
      quantity: item.quantity ?? '1',
      unitPrice: item.unitPrice ?? '0',
      unit: item.unit ?? '',
      taxRate: item.taxRate ?? '15',
      discount: item.discount ?? '0',
      productId: item.productId,
      taxProfileType: item.taxProfileType,
      exemptionReason: item.exemptionReason ?? '',
      exemptionCode: item.exemptionCode ?? '',
    );
  }

  int? productId;
  String? taxProfileType;
  final TextEditingController description;
  final TextEditingController quantity;
  final TextEditingController unitPrice;
  final TextEditingController unit;
  final TextEditingController taxRate;
  final TextEditingController discount;
  final TextEditingController exemptionReason;
  final TextEditingController exemptionCode;

  Map<String, dynamic> toPayload() => {
        if (productId != null) 'product_id': productId,
        'product_name': description.text.trim(),
        'description': description.text.trim(),
        'quantity': quantity.text.trim(),
        'unit_price': unitPrice.text.trim(),
        'unit': unit.text.trim(),
        'tax_rate': taxRate.text.trim().isEmpty ? '15' : taxRate.text.trim(),
        'discount': discount.text.trim().isEmpty ? '0' : discount.text.trim(),
        if (taxProfileType != null && taxProfileType!.isNotEmpty) 'tax_profile_type': taxProfileType,
        if (exemptionReason.text.trim().isNotEmpty) 'exemption_reason': exemptionReason.text.trim(),
        if (exemptionCode.text.trim().isNotEmpty) 'exemption_code': exemptionCode.text.trim(),
      };

  void dispose() {
    description.dispose();
    quantity.dispose();
    unitPrice.dispose();
    unit.dispose();
    taxRate.dispose();
    discount.dispose();
    exemptionReason.dispose();
    exemptionCode.dispose();
  }
}

class DocumentLinesEditor extends StatelessWidget {
  const DocumentLinesEditor({
    super.key,
    required this.lines,
    required this.onChanged,
    this.products = const [],
  });

  final List<LineDraft> lines;
  final VoidCallback onChanged;
  final List<CatalogOption> products;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < lines.length; i++)
          Card(
            margin: const EdgeInsets.only(bottom: 10),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Text('${l.lines} ${i + 1}', style: Theme.of(context).textTheme.labelLarge),
                      const Spacer(),
                      if (lines.length > 1)
                        IconButton(
                          tooltip: l.removeLine,
                          onPressed: () {
                            lines[i].dispose();
                            lines.removeAt(i);
                            onChanged();
                          },
                          icon: const Icon(Icons.delete_outline),
                        ),
                    ],
                  ),
                  if (products.isNotEmpty)
                    DropdownButtonFormField<int?>(
                      // ignore: deprecated_member_use
                      value: lines[i].productId != null && products.any((row) => row.id == lines[i].productId)
                          ? lines[i].productId
                          : null,
                      isExpanded: true,
                      decoration: InputDecoration(labelText: l.selectProduct),
                      items: [
                        DropdownMenuItem<int?>(value: null, child: Text(l.freeTextItem)),
                        for (final product in products)
                          DropdownMenuItem<int?>(value: product.id, child: Text(product.name)),
                      ],
                      onChanged: (id) {
                        lines[i].productId = id;
                        if (id != null) {
                          final product = products.firstWhere((row) => row.id == id);
                          lines[i].description.text = product.name;
                          final price = product.extra['price']?.toString();
                          if (price != null && price.isNotEmpty) {
                            lines[i].unitPrice.text = price;
                          }
                        }
                        onChanged();
                      },
                    ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: lines[i].description,
                    decoration: InputDecoration(labelText: l.description),
                  ),
                  const SizedBox(height: 8),
                  FormGrid(
                    minWidth: 140,
                    children: [
                      TextField(
                        controller: lines[i].quantity,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: InputDecoration(labelText: l.quantity),
                      ),
                      TextField(
                        controller: lines[i].unitPrice,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: InputDecoration(labelText: l.price),
                      ),
                      TextField(
                        controller: lines[i].unit,
                        decoration: InputDecoration(labelText: l.unit),
                      ),
                      TextField(
                        controller: lines[i].taxRate,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: InputDecoration(labelText: l.taxRate),
                      ),
                      TextField(
                        controller: lines[i].discount,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: InputDecoration(labelText: l.discount),
                      ),
                      DropdownButtonFormField<String?>(
                        // ignore: deprecated_member_use
                        value: lines[i].taxProfileType,
                        isExpanded: true,
                        decoration: InputDecoration(labelText: l.taxProfile),
                        items: [
                          const DropdownMenuItem<String?>(value: null, child: Text('—')),
                          DropdownMenuItem(value: 'standard', child: Text(l.standardTax, overflow: TextOverflow.ellipsis)),
                          DropdownMenuItem(value: 'zero_rated', child: Text(l.zeroRated, overflow: TextOverflow.ellipsis)),
                          DropdownMenuItem(value: 'exempt', child: Text(l.exempt, overflow: TextOverflow.ellipsis)),
                          DropdownMenuItem(value: 'out_of_scope', child: Text(l.outOfScope, overflow: TextOverflow.ellipsis)),
                        ],
                        onChanged: (value) {
                          lines[i].taxProfileType = value;
                          onChanged();
                        },
                      ),
                      TextField(
                        controller: lines[i].exemptionReason,
                        decoration: InputDecoration(labelText: l.exemptionReason),
                      ),
                      TextField(
                        controller: lines[i].exemptionCode,
                        decoration: InputDecoration(labelText: l.exemptionCode),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        OutlinedButton.icon(
          onPressed: () {
            lines.add(LineDraft());
            onChanged();
          },
          icon: const Icon(Icons.add),
          label: Text(l.addLine),
        ),
      ],
    );
  }
}
