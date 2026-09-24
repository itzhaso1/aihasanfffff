import 'package:flutter/material.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';
import 'package:hasim_finance/features/contracts/contract_form_widgets.dart';
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
    final p = context.hs;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (var i = 0; i < lines.length; i++)
          Container(
            margin: EdgeInsets.only(bottom: i == lines.length - 1 ? 0 : 12),
            decoration: BoxDecoration(
              color: p.canvasAlt,
              borderRadius: BorderRadius.circular(FinanceTokens.radius),
              border: Border.all(color: p.border),
            ),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: FinanceTokens.brandSoft,
                          borderRadius: BorderRadius.circular(FinanceTokens.radiusSm),
                        ),
                        child: Text(
                          '${l.lines} ${i + 1}',
                          style: Theme.of(context).textTheme.labelLarge?.copyWith(
                                color: FinanceTokens.brand,
                                fontWeight: FontWeight.w700,
                              ),
                        ),
                      ),
                      const Spacer(),
                      if (lines.length > 1)
                        TextButton.icon(
                          onPressed: () {
                            lines[i].dispose();
                            lines.removeAt(i);
                            onChanged();
                          },
                          icon: Icon(Icons.delete_outline, size: 18, color: p.danger),
                          label: Text(l.removeLine, style: TextStyle(color: p.danger)),
                        ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  ContractFormGrid(
                    minWidth: 200,
                    children: [
                      if (products.isNotEmpty)
                        ContractLabeledField(
                          label: l.selectProduct,
                          child: DropdownButtonFormField<int?>(
                            // ignore: deprecated_member_use
                            value: lines[i].productId != null && products.any((row) => row.id == lines[i].productId)
                                ? lines[i].productId
                                : null,
                            isExpanded: true,
                            decoration: contractFieldDecoration(hintText: l.freeTextItem, prefixIcon: Icons.inventory_2_outlined),
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
                        ),
                      ContractLabeledField(
                        label: l.description,
                        fullWidth: true,
                        child: TextField(
                          controller: lines[i].description,
                          decoration: contractFieldDecoration(hintText: 'وصف البند', prefixIcon: Icons.notes),
                        ),
                      ),
                      ContractLabeledField(
                        label: l.quantity,
                        required: true,
                        child: TextField(
                          controller: lines[i].quantity,
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          decoration: contractFieldDecoration(hintText: '1', prefixIcon: Icons.numbers),
                        ),
                      ),
                      ContractLabeledField(
                        label: l.price,
                        required: true,
                        child: TextField(
                          controller: lines[i].unitPrice,
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          decoration: contractFieldDecoration(hintText: '0.00', prefixIcon: Icons.attach_money),
                        ),
                      ),
                      ContractLabeledField(
                        label: l.unit,
                        child: TextField(
                          controller: lines[i].unit,
                          decoration: contractFieldDecoration(hintText: 'وحدة', prefixIcon: Icons.straighten),
                        ),
                      ),
                      ContractLabeledField(
                        label: l.taxRate,
                        child: TextField(
                          controller: lines[i].taxRate,
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          decoration: contractFieldDecoration(hintText: '15', prefixIcon: Icons.percent),
                        ),
                      ),
                      ContractLabeledField(
                        label: l.discount,
                        child: TextField(
                          controller: lines[i].discount,
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          decoration: contractFieldDecoration(hintText: '0', prefixIcon: Icons.local_offer_outlined),
                        ),
                      ),
                      ContractLabeledField(
                        label: l.taxProfile,
                        child: DropdownButtonFormField<String?>(
                          // ignore: deprecated_member_use
                          value: lines[i].taxProfileType,
                          isExpanded: true,
                          decoration: contractFieldDecoration(prefixIcon: Icons.account_balance_outlined),
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
                      ),
                      ContractLabeledField(
                        label: l.exemptionReason,
                        child: TextField(
                          controller: lines[i].exemptionReason,
                          decoration: contractFieldDecoration(hintText: l.exemptionReason),
                        ),
                      ),
                      ContractLabeledField(
                        label: l.exemptionCode,
                        child: TextField(
                          controller: lines[i].exemptionCode,
                          decoration: contractFieldDecoration(hintText: l.exemptionCode),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        const SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: () {
            lines.add(LineDraft());
            onChanged();
          },
          icon: const Icon(Icons.add),
          label: Text(l.addLine),
          style: OutlinedButton.styleFrom(
            foregroundColor: FinanceTokens.brand,
            side: BorderSide(color: p.border),
            padding: const EdgeInsets.symmetric(vertical: 14),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(FinanceTokens.radius)),
          ),
        ),
      ],
    );
  }
}
