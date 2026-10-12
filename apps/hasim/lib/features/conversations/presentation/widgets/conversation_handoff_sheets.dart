import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/conversations/data/conversation_handoff.dart';

Future<void> showHandoffRequestSheet({
  required BuildContext context,
  required bool submitting,
  required Future<String?> Function(String reason) onSubmit,
}) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    backgroundColor: HasimTokens.cardBg(context),
    shape: RoundedRectangleBorder(
      borderRadius: HasimLayout.modalSheetRadius(context),
    ),
    builder: (context) =>
        _HandoffRequestSheet(submitting: submitting, onSubmit: onSubmit),
  );
}

class _HandoffRequestSheet extends StatefulWidget {
  const _HandoffRequestSheet({
    required this.submitting,
    required this.onSubmit,
  });

  final bool submitting;
  final Future<String?> Function(String reason) onSubmit;

  @override
  State<_HandoffRequestSheet> createState() => _HandoffRequestSheetState();
}

class _HandoffRequestSheetState extends State<_HandoffRequestSheet> {
  String _reason = ConversationHandoffValues.defaultReason;
  bool _busy = false;
  String? _error;

  Future<void> _submit() async {
    if (_busy || widget.submitting) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final error = await widget.onSubmit(_reason);
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
        padding: const EdgeInsets.fromLTRB(8, 16, 8, 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Text(
                'طلب تحويل المحادثة',
                style: Theme.of(
                  context,
                ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
              ),
            ),
            const SizedBox(height: 8),
            ConstrainedBox(
              constraints: BoxConstraints(
                maxHeight: MediaQuery.sizeOf(context).height * 0.55,
              ),
              child: ListView(
                shrinkWrap: true,
                children: [
                  for (final reason in ConversationHandoffValues.reasons)
                    ListTile(
                      key: Key('handoff-reason-$reason'),
                      leading: Icon(
                        _reason == reason
                            ? Icons.radio_button_checked
                            : Icons.radio_button_off,
                      ),
                      title: Text(
                        ConversationHandoffValues.reasonLabel(reason),
                      ),
                      selected: _reason == reason,
                      enabled: !busy,
                      onTap: busy
                          ? null
                          : () => setState(() => _reason = reason),
                    ),
                ],
              ),
            ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: 16,
                  vertical: 8,
                ),
                child: Text(
                  _error!,
                  key: const Key('handoff-request-error'),
                  style: TextStyle(color: HasimTokens.danger),
                ),
              ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: FilledButton(
                key: const Key('handoff-request-submit'),
                onPressed: busy ? null : _submit,
                child: busy
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('إرسال الطلب'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class HandoffAssignTarget {
  const HandoffAssignTarget.staff({required this.userId, required this.name})
    : workerEngagementId = null;

  const HandoffAssignTarget.worker({
    required this.workerEngagementId,
    required this.name,
  }) : userId = null;

  final int? userId;
  final int? workerEngagementId;
  final String name;

  bool get isWorker => workerEngagementId != null;

  String get keySuffix =>
      isWorker ? 'engagement-$workerEngagementId' : '$userId';
}

Future<void> showHandoffAssignSheet({
  required BuildContext context,
  required List<HandoffAssignTarget> targets,
  required bool loading,
  required String? loadError,
  required bool submitting,
  required int? assignedUserId,
  required int? assignedWorkerEngagementId,
  required VoidCallback onRetry,
  required Future<String?> Function(HandoffAssignTarget target) onAssign,
}) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    backgroundColor: HasimTokens.cardBg(context),
    shape: RoundedRectangleBorder(
      borderRadius: HasimLayout.modalSheetRadius(context),
    ),
    builder: (context) => _HandoffAssignSheet(
      targets: targets,
      loading: loading,
      loadError: loadError,
      submitting: submitting,
      assignedUserId: assignedUserId,
      assignedWorkerEngagementId: assignedWorkerEngagementId,
      onRetry: onRetry,
      onAssign: onAssign,
    ),
  );
}

class _HandoffAssignSheet extends StatefulWidget {
  const _HandoffAssignSheet({
    required this.targets,
    required this.loading,
    required this.loadError,
    required this.submitting,
    required this.assignedUserId,
    required this.assignedWorkerEngagementId,
    required this.onRetry,
    required this.onAssign,
  });

  final List<HandoffAssignTarget> targets;
  final bool loading;
  final String? loadError;
  final bool submitting;
  final int? assignedUserId;
  final int? assignedWorkerEngagementId;
  final VoidCallback onRetry;
  final Future<String?> Function(HandoffAssignTarget target) onAssign;

  @override
  State<_HandoffAssignSheet> createState() => _HandoffAssignSheetState();
}

class _HandoffAssignSheetState extends State<_HandoffAssignSheet> {
  bool _busy = false;
  String? _error;

  Future<void> _assign(HandoffAssignTarget target) async {
    if (_busy || widget.submitting) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final error = await widget.onAssign(target);
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
        padding: const EdgeInsets.fromLTRB(8, 16, 8, 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: Text(
                'تعيين المحادثة',
                style: Theme.of(
                  context,
                ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
              ),
            ),
            const SizedBox(height: 8),
            if (widget.loading)
              const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (widget.loadError != null)
              Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  children: [
                    Text(widget.loadError!, textAlign: TextAlign.center),
                    const SizedBox(height: 12),
                    FilledButton(
                      onPressed: widget.onRetry,
                      child: const Text('إعادة المحاولة'),
                    ),
                  ],
                ),
              )
            else if (widget.targets.isEmpty)
              const Padding(
                padding: EdgeInsets.all(24),
                child: Text(
                  'لا يوجد موظف من مساحة العمل يمكن تعيينه من العقد الحالي.',
                  textAlign: TextAlign.center,
                ),
              )
            else
              ConstrainedBox(
                constraints: BoxConstraints(
                  maxHeight: MediaQuery.sizeOf(context).height * 0.45,
                ),
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    for (final target in widget.targets)
                      ListTile(
                        key: Key('handoff-assign-target-${target.keySuffix}'),
                        leading: Icon(
                          target.isWorker
                              ? Icons.badge_outlined
                              : Icons.person_outline,
                        ),
                        title: Text(target.name),
                        subtitle:
                            (target.isWorker
                                ? widget.assignedWorkerEngagementId ==
                                      target.workerEngagementId
                                : widget.assignedUserId == target.userId)
                            ? const Text('المعيَّن حالياً')
                            : Text(
                                target.isWorker
                                    ? 'عامل مرتبط بالمساحة'
                                    : 'موظف مساحة العمل',
                              ),
                        enabled: !busy,
                        onTap: busy ? null : () => _assign(target),
                      ),
                  ],
                ),
              ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: 16,
                  vertical: 8,
                ),
                child: Text(
                  _error!,
                  key: const Key('handoff-assign-error'),
                  style: TextStyle(color: HasimTokens.danger),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
