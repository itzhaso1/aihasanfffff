import 'package:flutter/material.dart';
import 'package:hasim_finance/core/layout/finance_layout.dart';

/// Read-only ISO date (yyyy-MM-dd) input backed by a [showDatePicker] dialog.
///
/// The [controller] keeps holding the ISO string so existing callers that read
/// `controller.text` for API filters keep working unchanged.
class DateField extends StatelessWidget {
  const DateField({
    super.key,
    required this.controller,
    this.label,
    this.compact = false,
    this.allowClear = true,
    this.onChanged,
    this.firstDate,
    this.lastDate,
  });

  final TextEditingController controller;
  final String? label;

  /// Uses the compact filter-bar decoration (label rendered by the caller).
  final bool compact;
  final bool allowClear;
  final ValueChanged<DateTime?>? onChanged;
  final DateTime? firstDate;
  final DateTime? lastDate;

  Future<void> _pick(BuildContext context) async {
    final now = DateTime.now();
    final current = DateTime.tryParse(controller.text.trim());
    final first = firstDate ?? DateTime(2000);
    final last = lastDate ?? DateTime(now.year + 5, 12, 31);
    var initial = current ?? now;
    if (initial.isBefore(first)) initial = first;
    if (initial.isAfter(last)) initial = last;
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: first,
      lastDate: last,
    );
    if (picked == null) return;
    controller.text = isoDate(picked);
    onChanged?.call(picked);
  }

  void _clear() {
    controller.clear();
    onChanged?.call(null);
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: controller,
      builder: (context, _) {
        final hasValue = controller.text.trim().isNotEmpty;
        final clear = allowClear && hasValue
            ? IconButton(
                tooltip: MaterialLocalizations.of(context).deleteButtonTooltip,
                onPressed: _clear,
                icon: const Icon(Icons.close, size: 16),
                padding: EdgeInsets.zero,
                constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
              )
            : null;
        const icon = Icon(Icons.event, size: 18);
        return TextField(
          controller: controller,
          readOnly: true,
          textAlignVertical: TextAlignVertical.center,
          onTap: () => _pick(context),
          decoration: compact
              ? FinanceFilterField.decoration(hintText: 'yyyy-mm-dd', prefixIcon: icon, suffixIcon: clear)
              : InputDecoration(labelText: label, hintText: 'yyyy-mm-dd', prefixIcon: icon, suffixIcon: clear),
        );
      },
    );
  }
}
