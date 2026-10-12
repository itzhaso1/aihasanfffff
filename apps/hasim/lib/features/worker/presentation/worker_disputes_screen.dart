import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/hasim_form_field.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/features/worker/data/worker_dispute.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';
import 'package:hasim/features/worker/presentation/worker_account_widgets.dart';
import 'package:hasim/features/worker/presentation/worker_scaffold.dart';
import 'package:hasim/features/worker/providers/worker_disputes_controller.dart';
import 'package:hasim/router/worker_nav.dart';
import 'package:intl/intl.dart';

class WorkerEngagementDisputesSection extends ConsumerWidget {
  const WorkerEngagementDisputesSection({super.key, required this.engagement});

  final WorkerEngagement engagement;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(workerDisputesProvider);
    final items = [
      for (final item in state.items)
        if (item.workerEngagementId == engagement.id) item,
    ];

    return WorkerSectionCard(
      title: 'نزاعات الارتباط',
      child: Column(
        key: const Key('worker-engagement-disputes-section'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('نزاع الارتباط مستقل عن الاعتراض على فترة الأرباح.'),
          if (state.actionError != null)
            WorkerInlineMessage(
              state.actionError!,
              key: const Key('worker-dispute-create-error'),
              error: true,
            ),
          const SizedBox(height: 8),
          FilledButton(
            key: const Key('worker-dispute-create'),
            onPressed: state.creating
                ? null
                : () => createWorkerDisputeFlow(context, ref, engagement.id),
            child: state.creating
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('فتح نزاع ارتباط'),
          ),
          const SizedBox(height: 8),
          TextButton(
            key: const Key('worker-disputes-open-all'),
            onPressed: () =>
                navigateWorkerDestination(context, workerDisputesLocation),
            child: const Text('عرض كل النزاعات'),
          ),
          if (items.isEmpty)
            const Text('لا توجد نزاعات ظاهرة لهذا الارتباط في الصفحة الحالية')
          else
            for (final dispute in items)
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(dispute.category),
                subtitle: Text(dispute.statusLabel),
                onTap: () => navigateWorkerDestination(
                  context,
                  '$workerDisputesLocation/${dispute.id}',
                ),
              ),
        ],
      ),
    );
  }
}

class WorkerDisputesScreen extends ConsumerStatefulWidget {
  const WorkerDisputesScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  ConsumerState<WorkerDisputesScreen> createState() =>
      _WorkerDisputesScreenState();
}

class _WorkerDisputesScreenState extends ConsumerState<WorkerDisputesScreen> {
  String _status = '';
  final _from = TextEditingController();
  final _to = TextEditingController();

  @override
  void dispose() {
    _from.dispose();
    _to.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(workerDisputesProvider);

    final body = SafeArea(
      child: state.loading && state.items.isEmpty
          ? const Center(
              key: Key('worker-disputes-loading'),
              child: CircularProgressIndicator(),
            )
          : ListView(
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
              children: [
                HasimPageHeader(
                  title: 'النزاعات',
                  subtitle: 'حالات نزاع الارتباط. لا يمكن حل النزاع من هنا.',
                  onBack: () =>
                      navigateWorkerBack(context, workerEngagementsLocation),
                ),
                const SizedBox(height: 12),
                KeyedSubtree(
                  key: const Key('worker-disputes-status-filter'),
                  child: DropdownButtonFormField<String>(
                    key: ValueKey('worker-disputes-status-filter-$_status'),
                    initialValue: _status,
                    items: const [
                      DropdownMenuItem(value: '', child: Text('كل الحالات')),
                      DropdownMenuItem(value: 'open', child: Text('مفتوح')),
                      DropdownMenuItem(
                        value: 'under_review',
                        child: Text('قيد المراجعة'),
                      ),
                      DropdownMenuItem(value: 'resolved', child: Text('محلول')),
                      DropdownMenuItem(value: 'closed', child: Text('مغلق')),
                    ],
                    onChanged: (value) => setState(() => _status = value ?? ''),
                    decoration: const InputDecoration(labelText: 'الحالة'),
                  ),
                ),
                KeyedSubtree(
                  key: const Key('worker-disputes-from'),
                  child: HasimFormField(
                    controller: _from,
                    label: 'من تاريخ (YYYY-MM-DD)',
                  ),
                ),
                KeyedSubtree(
                  key: const Key('worker-disputes-to'),
                  child: HasimFormField(
                    controller: _to,
                    label: 'إلى تاريخ (YYYY-MM-DD)',
                  ),
                ),
                FilledButton(
                  key: const Key('worker-disputes-apply-filters'),
                  onPressed: () => ref
                      .read(workerDisputesProvider.notifier)
                      .load(
                        WorkerDisputeFilters(
                          status: _status.isEmpty ? null : _status,
                          from: _from.text.trim().isEmpty
                              ? null
                              : _from.text.trim(),
                          to: _to.text.trim().isEmpty ? null : _to.text.trim(),
                        ),
                      ),
                  child: const Text('تطبيق التصفية'),
                ),
                const SizedBox(height: 16),
                if (state.error != null && state.items.isEmpty)
                  EmptyState(
                    title: state.error!,
                    actionLabel: 'إعادة المحاولة',
                    onAction: () =>
                        ref.read(workerDisputesProvider.notifier).load(),
                  )
                else if (state.items.isEmpty)
                  const EmptyState(title: 'لا توجد نزاعات')
                else ...[
                  for (final dispute in state.items) ...[
                    Material(
                      color: Colors.transparent,
                      child: InkWell(
                        key: Key('worker-dispute-${dispute.id}'),
                        onTap: () => navigateWorkerDestination(
                          context,
                          '$workerDisputesLocation/${dispute.id}',
                        ),
                        child: WorkerSectionCard(
                          title: dispute.category,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('الحالة: ${dispute.statusLabel}'),
                              Text(
                                'الارتباط رقم ${dispute.workerEngagementId}',
                              ),
                              if (dispute.openedAt != null)
                                Text(
                                  'فُتح: ${_formatDateTime(dispute.openedAt)}',
                                ),
                            ],
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(height: 12),
                  ],
                  if (state.hasMore)
                    OutlinedButton(
                      key: const Key('worker-disputes-load-more'),
                      onPressed: state.loadingMore
                          ? null
                          : () => ref
                                .read(workerDisputesProvider.notifier)
                                .loadMore(),
                      child: state.loadingMore
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Text('تحميل المزيد'),
                    ),
                ],
              ],
            ),
    );

    if (widget.embedded) {
      return body;
    }

    return WorkerScaffold(
      selectedIndex: WorkerNav.engagementsIndex,
      showBottomNavigation: false,
      body: body,
    );
  }
}

class WorkerDisputeDetailScreen extends ConsumerWidget {
  const WorkerDisputeDetailScreen({
    super.key,
    required this.disputeId,
    this.embedded = false,
  });

  final int? disputeId;
  final bool embedded;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(workerDisputeDetailProvider(disputeId));
    final dispute = state.dispute;

    final body = SafeArea(
      child: state.loading && dispute == null
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
              children: [
                HasimPageHeader(
                  title: 'تفاصيل النزاع',
                  onBack: () =>
                      navigateWorkerBack(context, workerDisputesLocation),
                ),
                const SizedBox(height: 16),
                if (state.error != null && dispute == null)
                  EmptyState(
                    title: state.error!,
                    actionLabel: 'إعادة المحاولة',
                    onAction: () => ref
                        .read(workerDisputeDetailProvider(disputeId).notifier)
                        .load(),
                  )
                else if (dispute != null)
                  WorkerSectionCard(
                    title: dispute.category,
                    child: Column(
                      key: Key('worker-dispute-detail-${dispute.id}'),
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('الارتباط رقم ${dispute.workerEngagementId}'),
                        Text('الحالة: ${dispute.statusLabel}'),
                        Text('المبلّغ: ${dispute.reporterLabel}'),
                        Text(dispute.description),
                        if (dispute.resolutionSummary != null)
                          Text('الخلاصة: ${dispute.resolutionSummary}'),
                        if (dispute.openedAt != null)
                          Text('فُتح: ${_formatDateTime(dispute.openedAt)}'),
                        if (dispute.underReviewAt != null)
                          Text(
                            'قيد المراجعة: ${_formatDateTime(dispute.underReviewAt)}',
                          ),
                        if (dispute.resolvedAt != null)
                          Text('حُل: ${_formatDateTime(dispute.resolvedAt)}'),
                        if (dispute.closedAt != null)
                          Text('أُغلق: ${_formatDateTime(dispute.closedAt)}'),
                      ],
                    ),
                  ),
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

Future<void> createWorkerDisputeFlow(
  BuildContext context,
  WidgetRef ref,
  int engagementId,
) async {
  final created = await showDialog<WorkerDispute>(
    context: context,
    builder: (dialogContext) =>
        _CreateDisputeDialog(engagementId: engagementId, ref: ref),
  );
  if (created == null || !context.mounted) return;
  showWorkerSnack(context, 'تم تسجيل نزاع الارتباط');
  navigateWorkerDestination(context, '$workerDisputesLocation/${created.id}');
}

class _CreateDisputeDialog extends StatefulWidget {
  const _CreateDisputeDialog({required this.engagementId, required this.ref});

  final int engagementId;
  final WidgetRef ref;

  @override
  State<_CreateDisputeDialog> createState() => _CreateDisputeDialogState();
}

class _CreateDisputeDialogState extends State<_CreateDisputeDialog> {
  final _category = TextEditingController();
  final _description = TextEditingController();
  String? _validation;

  @override
  void dispose() {
    _category.dispose();
    _description.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('فتح نزاع ارتباط'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'هذا نزاع على الارتباط، وليس اعتراضاً على فترة أرباح.',
            key: Key('worker-dispute-create-help'),
          ),
          TextField(
            key: const Key('worker-dispute-category'),
            controller: _category,
            maxLength: 64,
            decoration: const InputDecoration(labelText: 'التصنيف'),
          ),
          TextField(
            key: const Key('worker-dispute-description'),
            controller: _description,
            maxLength: 5000,
            maxLines: 4,
            decoration: const InputDecoration(labelText: 'الوصف'),
          ),
          if (_validation != null)
            WorkerInlineMessage(
              _validation!,
              key: const Key('worker-dispute-validation'),
              error: true,
            ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('إلغاء'),
        ),
        TextButton(
          key: const Key('worker-dispute-submit'),
          onPressed: _submit,
          child: const Text('إرسال'),
        ),
      ],
    );
  }

  Future<void> _submit() async {
    final category = _category.text.trim();
    final description = _description.text.trim();
    if (category.isEmpty || category.length > 64) {
      setState(() => _validation = 'التصنيف مطلوب وبحد أقصى 64 حرفاً.');
      return;
    }
    if (description.isEmpty || description.length > 5000) {
      setState(() => _validation = 'الوصف مطلوب وبحد أقصى 5000 حرف.');
      return;
    }
    final created = await widget.ref
        .read(workerDisputesProvider.notifier)
        .create(
          engagementId: widget.engagementId,
          category: category,
          description: description,
        );
    if (!mounted) return;
    if (created == null) return;
    Navigator.pop(context, created);
  }
}

String _formatDateTime(DateTime? value) {
  if (value == null) return '';
  return DateFormat('yyyy-MM-dd HH:mm').format(value.toLocal());
}
