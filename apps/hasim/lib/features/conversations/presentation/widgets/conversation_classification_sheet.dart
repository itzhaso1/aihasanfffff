import 'package:flutter/material.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/conversations/data/conversation_classification.dart';

Future<void> showConversationClassificationSheet({
  required BuildContext context,
  required ConversationModel conversation,
  required bool submitting,
  required Future<String?> Function({
    required String customerPriority,
    required String paymentStatus,
    required String reviewStatus,
  })
  onSave,
}) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    backgroundColor: HasimTokens.cardBg(context),
    shape: RoundedRectangleBorder(borderRadius: HasimLayout.modalSheetRadius(context)),
    builder: (context) => _ClassificationSheet(
      conversation: conversation,
      submitting: submitting,
      onSave: onSave,
    ),
  );
}

class _ClassificationSheet extends StatefulWidget {
  const _ClassificationSheet({
    required this.conversation,
    required this.submitting,
    required this.onSave,
  });

  final ConversationModel conversation;
  final bool submitting;
  final Future<String?> Function({
    required String customerPriority,
    required String paymentStatus,
    required String reviewStatus,
  })
  onSave;

  @override
  State<_ClassificationSheet> createState() => _ClassificationSheetState();
}

class _ClassificationSheetState extends State<_ClassificationSheet> {
  late String _priority;
  late String _payment;
  late String _review;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _priority = ConversationClassificationValues.isAllowedPriority(
      widget.conversation.customerPriority,
    )
        ? widget.conversation.customerPriority
        : ConversationClassificationValues.defaultPriority;
    _payment = ConversationClassificationValues.isAllowedPayment(
      widget.conversation.paymentStatus,
    )
        ? widget.conversation.paymentStatus
        : ConversationClassificationValues.defaultPayment;
    _review = ConversationClassificationValues.isAllowedReview(
      widget.conversation.reviewStatus,
    )
        ? widget.conversation.reviewStatus
        : ConversationClassificationValues.defaultReview;
  }

  Future<void> _submit() async {
    if (_busy || widget.submitting) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final error = await widget.onSave(
      customerPriority: _priority,
      paymentStatus: _payment,
      reviewStatus: _review,
    );
    if (!mounted) return;
    if (error == null) {
      Navigator.pop(context);
      return;
    }
    setState(() {
      _busy = false;
      _error = error;
    });
  }

  @override
  Widget build(BuildContext context) {
    final busy = _busy || widget.submitting;
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          16,
          16,
          16,
          16 + MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                'تصنيف المحادثة',
                style: Theme.of(context).textTheme.titleLarge?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                'المصدر الحالي: ${widget.conversation.classificationSource}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: HasimTokens.mutedColor(context),
                ),
              ),
              const SizedBox(height: 16),
              _group(
                title: 'أولوية العميل',
                value: _priority,
                options: ConversationClassificationValues.priorities,
                labelOf: ConversationClassificationValues.priorityLabel,
                onSelected: (value) => setState(() => _priority = value),
              ),
              _group(
                title: 'حالة الدفع',
                value: _payment,
                options: ConversationClassificationValues.payments,
                labelOf: ConversationClassificationValues.paymentLabel,
                onSelected: (value) => setState(() => _payment = value),
              ),
              _group(
                title: 'المراجعة',
                value: _review,
                options: ConversationClassificationValues.reviews,
                labelOf: ConversationClassificationValues.reviewLabel,
                onSelected: (value) => setState(() => _review = value),
              ),
              if (_error != null) ...[
                const SizedBox(height: 8),
                Text(
                  _error!,
                  key: const Key('classification-error'),
                  style: TextStyle(color: HasimTokens.danger),
                ),
              ],
              const SizedBox(height: 16),
              FilledButton(
                key: const Key('classification-save'),
                onPressed: busy ? null : _submit,
                child: busy
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('حفظ التصنيف'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _group({
    required String title,
    required String value,
    required List<String> options,
    required String Function(String) labelOf,
    required ValueChanged<String> onSelected,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final option in options)
                ChoiceChip(
                  key: Key('classification-$option'),
                  label: Text(labelOf(option)),
                  selected: value == option,
                  onSelected: (_) => onSelected(option),
                ),
            ],
          ),
        ],
      ),
    );
  }
}
