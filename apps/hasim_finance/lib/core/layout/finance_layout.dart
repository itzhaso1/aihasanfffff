import 'package:flutter/material.dart';
import 'package:hasim_finance/core/layout/finance_chrome.dart';
import 'package:hasim_finance/core/layout/kpi_card.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';
import 'package:hasim_finance/features/shared/paged.dart';

export 'package:hasim_finance/core/layout/finance_chrome.dart';
export 'package:hasim_finance/core/layout/kpi_card.dart';

class FinancePage extends StatelessWidget {
  const FinancePage({
    super.key,
    required this.child,
    this.maxWidth = 1600,
    this.padding = const EdgeInsets.fromLTRB(20, 8, 20, 28),
  });

  final Widget child;
  final double maxWidth;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.topCenter,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: maxWidth),
        child: Padding(padding: padding, child: child),
      ),
    );
  }
}

class FormSection extends StatelessWidget {
  const FormSection({
    super.key,
    required this.title,
    required this.child,
    this.subtitle,
  });

  final String title;
  final String? subtitle;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final p = context.hs;
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Material(
      color: p.card,
      shadowColor: const Color(0xFF000000).withValues(alpha: theme.brightness == Brightness.dark ? 0.28 : 0.06),
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(FinanceTokens.radiusLg),
        side: BorderSide(color: p.border),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(title, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
            if (subtitle != null) ...[
              const SizedBox(height: 2),
              Text(subtitle!, style: theme.textTheme.bodySmall),
            ],
            const SizedBox(height: 12),
            child,
          ],
        ),
      ),
      ),
    );
  }
}

class FormGrid extends StatelessWidget {
  const FormGrid({super.key, required this.children, this.minWidth = 220});

  final List<Widget> children;
  final double minWidth;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final cols = constraints.maxWidth >= minWidth * 3 + 24
            ? 3
            : constraints.maxWidth >= minWidth * 2 + 12
                ? 2
                : 1;
        return Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            for (final child in children)
              SizedBox(
                width: cols == 1 ? constraints.maxWidth : (constraints.maxWidth - (10 * (cols - 1))) / cols,
                child: child,
              ),
          ],
        );
      },
    );
  }
}

class MetricGrid extends StatelessWidget {
  const MetricGrid({super.key, required this.metrics});

  final List<(String, String)> metrics;

  @override
  Widget build(BuildContext context) {
    return KpiGrid(
      cards: [
        for (final metric in metrics)
          KpiCard(label: metric.$1, value: metric.$2, compact: true),
      ],
    );
  }
}

class NamedOption {
  const NamedOption({required this.id, required this.name});

  final int id;
  final String name;

  factory NamedOption.fromJson(Map<String, dynamic> json) {
    return NamedOption(
      id: int.parse('${json['id']}'),
      name: json['name']?.toString() ?? json['title']?.toString() ?? '#${json['id']}',
    );
  }
}

class OptionPicker extends StatelessWidget {
  const OptionPicker({
    super.key,
    required this.label,
    required this.options,
    required this.value,
    required this.onChanged,
    this.floatingLabel = true,
  });

  final String label;
  final List<NamedOption> options;
  final int? value;
  final ValueChanged<int?> onChanged;
  final bool floatingLabel;

  @override
  Widget build(BuildContext context) {
    return DropdownButtonFormField<int?>(
      // ignore: deprecated_member_use
      value: value != null && options.any((row) => row.id == value) ? value : null,
      isExpanded: true,
      isDense: true,
      decoration: floatingLabel ? InputDecoration(labelText: label) : FinanceFilterField.decoration(),
      items: [
        const DropdownMenuItem<int?>(value: null, child: Text('—')),
        for (final option in options) DropdownMenuItem<int?>(value: option.id, child: Text(option.name)),
      ],
      onChanged: onChanged,
    );
  }
}

String fieldError(Object error, String key) {
  if (error is! ApiException || error.errors == null) return '';
  final value = error.errors![key];
  if (value is List && value.isNotEmpty) return value.first.toString();
  if (value != null) return value.toString();
  return '';
}

void showFormError(BuildContext context, Object error) {
  if (error is ApiException && error.errors != null && error.errors!.isNotEmpty) {
    final first = error.errors!.values.first;
    final text = first is List && first.isNotEmpty ? first.first.toString() : first.toString();
    showSnack(context, text);
    return;
  }
  showApiError(context, error);
}

String isoDate([DateTime? value]) {
  final date = value ?? DateTime.now();
  return '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';
}
