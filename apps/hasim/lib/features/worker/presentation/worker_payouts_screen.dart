import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/features/worker/data/worker_earning.dart';
import 'package:hasim/features/worker/data/worker_payout.dart';
import 'package:hasim/features/worker/presentation/worker_account_widgets.dart';
import 'package:hasim/features/worker/presentation/worker_scaffold.dart';
import 'package:hasim/features/worker/providers/worker_payouts_controller.dart';
import 'package:hasim/router/worker_nav.dart';
import 'package:intl/intl.dart';

class WorkerPayoutsSection extends ConsumerWidget {
  const WorkerPayoutsSection({super.key, required this.engagementId});

  final int engagementId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(workerPayoutsProvider);
    final items = state.forEngagement(engagementId);

    return WorkerSectionCard(
      title: 'الدفعات',
      child: Column(
        key: const Key('worker-payouts-section'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'سجل الدفعات المرتبطة بهذا الارتباط. لا يمكن طلب دفعة من هنا.',
          ),
          if (state.loading && state.items.isEmpty)
            const Center(
              child: Padding(
                padding: EdgeInsets.symmetric(vertical: 12),
                child: CircularProgressIndicator(),
              ),
            )
          else if (state.error != null && state.items.isEmpty)
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                WorkerInlineMessage(state.error!, error: true),
                TextButton(
                  onPressed: () =>
                      ref.read(workerPayoutsProvider.notifier).load(),
                  child: const Text('إعادة المحاولة'),
                ),
              ],
            )
          else if (items.isEmpty)
            const Text('لا توجد دفعات لهذا الارتباط')
          else
            for (final payout in items) WorkerPayoutTile(payout: payout),
        ],
      ),
    );
  }
}

class WorkerPayoutsScreen extends ConsumerWidget {
  const WorkerPayoutsScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(workerPayoutsProvider);

    final body = SafeArea(
      child: state.loading && state.items.isEmpty
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
              children: [
                HasimPageHeader(
                  title: 'سجل الدفعات',
                  subtitle: 'عرض فقط. لا يمكن طلب دفعة أو إضافة حساب بنكي.',
                  onBack: () =>
                      navigateWorkerBack(context, workerEngagementsLocation),
                ),
                const SizedBox(height: 16),
                if (state.error != null && state.items.isEmpty)
                  EmptyState(
                    title: state.error!,
                    actionLabel: 'إعادة المحاولة',
                    onAction: () =>
                        ref.read(workerPayoutsProvider.notifier).load(),
                  )
                else if (state.items.isEmpty)
                  const EmptyState(title: 'لا توجد دفعات بعد')
                else
                  for (final payout in state.items) ...[
                    WorkerSectionCard(
                      title: formatWorkerMoney(
                        payout.amountMinor,
                        payout.currency,
                      ),
                      child: WorkerPayoutTile(payout: payout),
                    ),
                    const SizedBox(height: 12),
                  ],
              ],
            ),
    );

    if (embedded) {
      return body;
    }

    return WorkerScaffold(
      selectedIndex: WorkerNav.engagementsIndex,
      showBottomNavigation: false,
      body: body,
    );
  }
}

class WorkerPayoutTile extends StatelessWidget {
  const WorkerPayoutTile({super.key, required this.payout});

  final WorkerPayout payout;

  @override
  Widget build(BuildContext context) {
    return Column(
      key: Key('worker-payout-${payout.id}'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          formatWorkerMoney(payout.amountMinor, payout.currency),
          style: const TextStyle(fontWeight: FontWeight.w700),
        ),
        Text('الحالة: ${payout.statusLabel}'),
        Text('الارتباط رقم ${payout.workerEngagementId}'),
        Text('فترة الأرباح رقم ${payout.workerEarningPeriodId}'),
        if (payout.requestedAt != null)
          Text('طُلبت: ${_formatDateTime(payout.requestedAt)}'),
        if (payout.processingAt != null)
          Text('قيد المعالجة: ${_formatDateTime(payout.processingAt)}'),
        if (payout.paidAt != null)
          Text('دُفعت: ${_formatDateTime(payout.paidAt)}'),
        if (payout.failedAt != null)
          Text('فشلت: ${_formatDateTime(payout.failedAt)}'),
        if (payout.cancelledAt != null)
          Text('أُلغيت: ${_formatDateTime(payout.cancelledAt)}'),
        if (payout.failureReason != null) Text(payout.failureReason!),
      ],
    );
  }
}

String _formatDateTime(DateTime? value) {
  if (value == null) return '';
  return DateFormat('yyyy-MM-dd HH:mm').format(value.toLocal());
}
