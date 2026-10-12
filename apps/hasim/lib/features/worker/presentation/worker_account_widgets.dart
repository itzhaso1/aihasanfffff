import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_form_field.dart';

class WorkerSectionCard extends StatelessWidget {
  const WorkerSectionCard({
    super.key,
    required this.title,
    required this.child,
  });

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: HasimTokens.cardBg(context),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
        side: BorderSide(color: HasimTokens.lineColor(context)),
      ),
      clipBehavior: Clip.antiAlias,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              title,
              style: Theme.of(
                context,
              ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 12),
            child,
          ],
        ),
      ),
    );
  }
}

class WorkerInlineMessage extends StatelessWidget {
  const WorkerInlineMessage(this.message, {super.key, this.error = false});

  final String message;
  final bool error;

  @override
  Widget build(BuildContext context) {
    final color = error
        ? Theme.of(context).colorScheme.error
        : HasimTokens.mutedColor(context);
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Text(message, style: TextStyle(color: color, height: 1.4)),
    );
  }
}

void showWorkerSnack(BuildContext context, String message) {
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
}

/// Country and availability choices already used by the worker web form.
const workerCountryLabels = {'JO': 'الأردن', 'SA': 'السعودية'};

const workerAvailabilityLabels = {
  'available': 'متاح',
  'limited': 'متاح بشكل محدود',
  'unavailable': 'غير متاح',
};

class WorkerChoiceField extends StatelessWidget {
  const WorkerChoiceField({
    super.key,
    required this.label,
    required this.value,
    required this.options,
    required this.onChanged,
    this.errorText,
  });

  final String label;
  final String value;
  final Map<String, String> options;
  final ValueChanged<String> onChanged;
  final String? errorText;

  @override
  Widget build(BuildContext context) {
    final items = Map<String, String>.from(options);
    if (value.isNotEmpty && !items.containsKey(value)) {
      items[value] = value;
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          label,
          style: Theme.of(
            context,
          ).textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 8),
        DropdownButtonFormField<String>(
          key: ValueKey('$label-$value'),
          initialValue: value.isEmpty ? null : value,
          items: [
            for (final entry in items.entries)
              DropdownMenuItem(value: entry.key, child: Text(entry.value)),
          ],
          onChanged: (next) {
            if (next != null) onChanged(next);
          },
          decoration: InputDecoration(
            filled: true,
            fillColor: HasimTokens.pageBg(context),
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
            errorText: errorText,
          ),
        ),
      ],
    );
  }
}

class WorkerSaveButton extends StatelessWidget {
  const WorkerSaveButton({
    super.key,
    required this.label,
    required this.busy,
    required this.onPressed,
  });

  final String label;
  final bool busy;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: AlignmentDirectional.centerStart,
      child: FilledButton(
        onPressed: busy ? null : onPressed,
        child: Text(busy ? 'جارٍ الحفظ...' : label),
      ),
    );
  }
}

class WorkerTextField extends StatelessWidget {
  const WorkerTextField({
    super.key,
    required this.controller,
    required this.label,
    this.maxLines = 1,
    this.errorText,
  });

  final TextEditingController controller;
  final String label;
  final int maxLines;
  final String? errorText;

  @override
  Widget build(BuildContext context) {
    return HasimFormField(
      controller: controller,
      label: label,
      maxLines: maxLines,
      errorText: errorText,
    );
  }
}
