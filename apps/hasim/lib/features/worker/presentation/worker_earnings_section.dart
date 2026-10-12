import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/features/worker/data/worker_earning.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';
import 'package:hasim/features/worker/presentation/worker_account_widgets.dart';
import 'package:hasim/features/worker/providers/worker_earnings_controller.dart';

class WorkerEarningsSection extends ConsumerWidget {
  const WorkerEarningsSection({super.key, required this.engagement});

  final WorkerEngagement engagement;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(workerEarningsProvider(engagement.id));

    return WorkerSectionCard(
      title: 'الأرباح',
      child: Column(
        key: const Key('worker-earnings-section'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('الارتباط رقم ${engagement.id}'),
          if (state.loading && state.periods.isEmpty)
            const Center(
              key: Key('worker-earnings-loading'),
              child: Padding(
                padding: EdgeInsets.symmetric(vertical: 12),
                child: CircularProgressIndicator(),
              ),
            )
          else if (state.error != null && state.periods.isEmpty)
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                WorkerInlineMessage(state.error!, error: true),
                TextButton(
                  key: const Key('worker-earnings-retry'),
                  onPressed: () => ref
                      .read(workerEarningsProvider(engagement.id).notifier)
                      .load(),
                  child: const Text('إعادة المحاولة'),
                ),
              ],
            )
          else ...[
            if (state.error != null)
              WorkerInlineMessage(state.error!, error: true),
            if (state.actionError != null)
              WorkerInlineMessage(
                state.actionError!,
                key: const Key('worker-earnings-action-error'),
                error: true,
              ),
            if (state.periods.isEmpty)
              const Text('لا توجد فترات أرباح بعد')
            else
              for (final period in state.periods) ...[
                const SizedBox(height: 8),
                _PeriodCard(
                  engagementId: engagement.id,
                  period: period,
                  expanded: state.expandedPeriodId == period.id,
                  busy: state.busy,
                ),
              ],
          ],
        ],
      ),
    );
  }
}

class _PeriodCard extends ConsumerWidget {
  const _PeriodCard({
    required this.engagementId,
    required this.period,
    required this.expanded,
    required this.busy,
  });

  final int engagementId;
  final WorkerEarningPeriod period;
  final bool expanded;
  final bool busy;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        key: Key('worker-earning-period-${period.id}'),
        onTap: () => ref
            .read(workerEarningsProvider(engagementId).notifier)
            .togglePeriod(period.id),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                _periodRange(period),
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
              Text('الحالة: ${period.statusLabel}'),
              for (final entry in period.totalsMinor.entries)
                Text(formatWorkerMoney(entry.value, entry.key)),
              Text('البنود: ${period.lines.length}'),
              if (expanded) ...[
                const SizedBox(height: 8),
                if (period.lines.isEmpty)
                  const Text('لا توجد بنود في هذه الفترة')
                else
                  for (final line in period.lines) _LineRow(line: line),
                if (period.canDispute) ...[
                  const SizedBox(height: 8),
                  OutlinedButton(
                    key: Key('worker-earning-dispute-${period.id}'),
                    onPressed: busy ? null : () => _dispute(context, ref),
                    child: const Text('اعتراض على المبلغ'),
                  ),
                ],
              ],
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _dispute(BuildContext context, WidgetRef ref) async {
    final reason = await showDialog<String>(
      context: context,
      builder: (dialogContext) => _PeriodDisputeDialog(period: period),
    );
    if (reason == null || !context.mounted) return;
    final ok = await ref
        .read(workerEarningsProvider(engagementId).notifier)
        .disputePeriod(period.id, reason: reason.isEmpty ? null : reason);
    if (!context.mounted || !ok) return;
    showWorkerSnack(context, 'تم تسجيل الاعتراض على فترة الأرباح');
  }
}

class _LineRow extends StatelessWidget {
  const _LineRow({required this.line});

  final WorkerEarningLine line;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '${line.lineTypeLabel} — ${formatWorkerMoney(line.amountMinor, line.currency)}',
          ),
          Text('المصدر: ${line.sourceTypeLabel}'),
          if (line.sourceType == 'work_session' && line.sourceId != null)
            Text('جلسة العمل رقم ${line.sourceId}'),
          if (line.description != null) Text(line.description!),
        ],
      ),
    );
  }
}

class _PeriodDisputeDialog extends StatefulWidget {
  const _PeriodDisputeDialog({required this.period});

  final WorkerEarningPeriod period;

  @override
  State<_PeriodDisputeDialog> createState() => _PeriodDisputeDialogState();
}

class _PeriodDisputeDialogState extends State<_PeriodDisputeDialog> {
  final _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('اعتراض على فترة الأرباح'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'هذا اعتراض على مبلغ فترة الأرباح، وليس نزاع ارتباط.',
            key: const Key('worker-earning-dispute-help'),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('worker-earning-dispute-reason'),
            controller: _reason,
            maxLength: 2000,
            maxLines: 3,
            decoration: const InputDecoration(labelText: 'السبب (اختياري)'),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('إلغاء'),
        ),
        TextButton(
          key: const Key('worker-earning-dispute-confirm'),
          onPressed: () => Navigator.pop(context, _reason.text.trim()),
          child: const Text('تأكيد الاعتراض'),
        ),
      ],
    );
  }
}

String _periodRange(WorkerEarningPeriod period) {
  final start = period.startsAt ?? '—';
  final end = period.endsAt ?? '—';
  return '$start — $end';
}
