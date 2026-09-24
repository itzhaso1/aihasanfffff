import 'package:flutter/material.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';
import 'package:hasim_finance/features/contracts/contract_form_widgets.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class ContractLineDraft {
  ContractLineDraft({
    this.id,
    String title = '',
    this.itemKind = 'service',
    String description = '',
    String quantity = '1',
    String unitPrice = '0',
    String unit = '',
    String discount = '0',
    String taxRate = '0',
    this.taxType = 'standard',
  })  : title = TextEditingController(text: title),
        description = TextEditingController(text: description),
        quantity = TextEditingController(text: quantity),
        unitPrice = TextEditingController(text: unitPrice),
        unit = TextEditingController(text: unit),
        discount = TextEditingController(text: discount),
        taxRate = TextEditingController(text: taxRate);

  factory ContractLineDraft.fromItem(ContractItemRecord item) {
    return ContractLineDraft(
      id: item.id,
      title: item.title ?? item.description ?? '',
      itemKind: item.itemKind ?? 'service',
      description: item.description ?? '',
      quantity: item.quantity ?? '1',
      unitPrice: item.unitPrice ?? '0',
      unit: item.unit ?? '',
      discount: item.discount ?? '0',
      taxRate: item.taxRate ?? '0',
      taxType: item.taxType ?? 'standard',
    );
  }

  final int? id;
  String itemKind;
  String taxType;
  final TextEditingController title;
  final TextEditingController description;
  final TextEditingController quantity;
  final TextEditingController unitPrice;
  final TextEditingController unit;
  final TextEditingController discount;
  final TextEditingController taxRate;

  Map<String, dynamic> toPayload() => {
        if (id != null) 'id': id,
        'title': title.text.trim(),
        'item_kind': itemKind,
        'description': description.text.trim(),
        'unit': unit.text.trim(),
        'quantity': quantity.text.trim().isEmpty ? '1' : quantity.text.trim(),
        'unit_price': unitPrice.text.trim().isEmpty ? '0' : unitPrice.text.trim(),
        'discount': discount.text.trim().isEmpty ? '0' : discount.text.trim(),
        'discount_type': 'amount',
        'tax_rate': taxRate.text.trim().isEmpty ? '0' : taxRate.text.trim(),
        'tax_type': taxType,
      };

  void dispose() {
    title.dispose();
    description.dispose();
    quantity.dispose();
    unitPrice.dispose();
    unit.dispose();
    discount.dispose();
    taxRate.dispose();
  }
}

class ContractLinesEditor extends StatelessWidget {
  const ContractLinesEditor({
    super.key,
    required this.lines,
    required this.onChanged,
  });

  final List<ContractLineDraft> lines;
  final VoidCallback onChanged;

  static const _kinds = <(String, String)>[
    ('service', 'خدمة'),
    ('product', 'منتج'),
    ('subscription', 'اشتراك'),
    ('one_time', 'مرة واحدة'),
    ('monthly', 'شهري'),
    ('recurring', 'دوري'),
  ];

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
                      ContractLabeledField(
                        label: l.fieldName,
                        child: TextField(
                          controller: lines[i].title,
                          decoration: contractFieldDecoration(hintText: 'اسم البند', prefixIcon: Icons.label_outline),
                        ),
                      ),
                      ContractLabeledField(
                        label: 'نوع البند',
                        child: DropdownButtonFormField<String>(
                          // ignore: deprecated_member_use
                          value: lines[i].itemKind,
                          isExpanded: true,
                          decoration: contractFieldDecoration(hintText: 'اختر النوع', prefixIcon: Icons.category_outlined),
                          items: [
                            for (final kind in _kinds)
                              DropdownMenuItem(value: kind.$1, child: Text(kind.$2)),
                          ],
                          onChanged: (value) {
                            if (value == null) return;
                            lines[i].itemKind = value;
                            onChanged();
                          },
                        ),
                      ),
                      ContractLabeledField(
                        label: l.description,
                        fullWidth: true,
                        child: TextField(
                          controller: lines[i].description,
                          maxLines: 2,
                          decoration: contractFieldDecoration(hintText: 'وصف البند'),
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
                        label: l.unit,
                        child: TextField(
                          controller: lines[i].unit,
                          decoration: contractFieldDecoration(hintText: 'وحدة', prefixIcon: Icons.straighten),
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
                        label: l.taxRate,
                        child: TextField(
                          controller: lines[i].taxRate,
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          decoration: contractFieldDecoration(hintText: '0', prefixIcon: Icons.percent),
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
                    ],
                  ),
                ],
              ),
            ),
          ),
        const SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: () {
            lines.add(ContractLineDraft());
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
