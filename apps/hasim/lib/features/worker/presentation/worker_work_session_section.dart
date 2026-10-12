import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';
import 'package:hasim/features/worker/data/worker_work_session.dart';
import 'package:hasim/features/worker/presentation/worker_account_widgets.dart';
import 'package:hasim/features/worker/providers/worker_work_session_controller.dart';
import 'package:intl/intl.dart';

class WorkerWorkSessionSection extends ConsumerStatefulWidget {
  const WorkerWorkSessionSection({super.key, required this.engagement});

  final WorkerEngagement engagement;

  @override
  ConsumerState<WorkerWorkSessionSection> createState() =>
      _WorkerWorkSessionSectionState();
}

class _WorkerWorkSessionSectionState
    extends ConsumerState<WorkerWorkSessionSection>
    with WidgetsBindingObserver {
  int get _engagementId => widget.engagement.id;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) return;
    final session = ref.read(workerWorkSessionProvider(_engagementId));
    if (session.busy) return;
    ref.read(workerWorkSessionProvider(_engagementId).notifier).load();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(workerWorkSessionProvider(_engagementId));
    final current = state.currentSession;
    final history = [
      for (final session in state.sessions)
        if (session.id != current?.id) session,
    ];
    final showStart =
        current == null && widget.engagement.status == 'active';
    final open = current?.openSegment;

    return WorkerSectionCard(
      title: 'جلسة العمل',
      child: Column(
        key: const Key('worker-session-section'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (state.loading && state.sessions.isEmpty)
            const Center(
              key: Key('worker-session-loading'),
              child: Padding(
                padding: EdgeInsets.symmetric(vertical: 12),
                child: CircularProgressIndicator(),
              ),
            )
          else if (state.error != null && state.sessions.isEmpty)
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                WorkerInlineMessage(state.error!, error: true),
                TextButton(
                  key: const Key('worker-session-retry'),
                  onPressed: () => ref
                      .read(workerWorkSessionProvider(_engagementId).notifier)
                      .load(),
                  child: const Text('إعادة المحاولة'),
                ),
              ],
            )
          else ...[
            if (state.error != null)
              WorkerInlineMessage(state.error!, error: true),
            if (current != null)
              _CurrentSession(session: current, open: open)
            else if (state.sessions.isEmpty)
              const Text('لا توجد جلسات عمل بعد')
            else
              const Text('لا توجد جلسة عمل جارية'),
            if (state.actionError != null)
              WorkerInlineMessage(
                state.actionError!,
                key: const Key('worker-session-action-error'),
                error: true,
              ),
            const SizedBox(height: 12),
            _SessionActions(
              engagementId: _engagementId,
              busy: state.busy,
              action: state.action,
              showStart: showStart,
              canBreak: current != null && (open?.isActive ?? false),
              canResume: current != null && (open?.isBreak ?? false),
              canEnd: current != null,
            ),
            if (history.isNotEmpty) ...[
              const SizedBox(height: 16),
              const Text(
                'السجل',
                style: TextStyle(fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 8),
              for (final session in history) ...[
                _HistoryRow(session: session),
                const SizedBox(height: 8),
              ],
            ],
          ],
        ],
      ),
    );
  }
}

class _CurrentSession extends StatelessWidget {
  const _CurrentSession({required this.session, required this.open});

  final WorkerWorkSession session;
  final WorkerWorkSessionSegment? open;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'الحالة: ${session.statusLabel}',
          key: const Key('worker-session-status'),
        ),
        _LiveElapsed(session: session),
        if (session.startedAt != null)
          Text('بدأت: ${_formatDateTime(session.startedAt)}'),
        if (session.endedAt != null)
          Text('انتهت: ${_formatDateTime(session.endedAt)}'),
        if (session.durationSeconds != null)
          Text(
            'المدة: ${formatWorkSessionDuration(session.durationSeconds!)}',
          ),
        if (open != null) Text('القطعة الحالية: ${open!.typeLabel}'),
        if (open?.startedAt != null)
          Text('بداية القطعة: ${_formatDateTime(open!.startedAt)}'),
        for (final segment in session.segments) Text(_segmentLine(segment)),
      ],
    );
  }
}

class _LiveElapsed extends StatefulWidget {
  const _LiveElapsed({required this.session});

  final WorkerWorkSession session;

  @override
  State<_LiveElapsed> createState() => _LiveElapsedState();
}

class _LiveElapsedState extends State<_LiveElapsed> {
  Timer? _ticker;
  DateTime _now = DateTime.now();

  @override
  void initState() {
    super.initState();
    _syncTicker();
  }

  @override
  void didUpdateWidget(covariant _LiveElapsed oldWidget) {
    super.didUpdateWidget(oldWidget);
    _syncTicker();
  }

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Text(
      formatWorkSessionDuration(widget.session.displayElapsedSeconds(_now)),
      key: const Key('worker-session-timer'),
      style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 22),
    );
  }

  void _syncTicker() {
    final live =
        widget.session.isStarted && widget.session.durationSeconds == null;
    if (live && _ticker == null) {
      _ticker = Timer.periodic(const Duration(seconds: 1), (_) {
        if (!mounted) return;
        setState(() => _now = _now.add(const Duration(seconds: 1)));
      });
    } else if (!live && _ticker != null) {
      _ticker?.cancel();
      _ticker = null;
    }
  }
}

class _SessionActions extends ConsumerWidget {
  const _SessionActions({
    required this.engagementId,
    required this.busy,
    required this.action,
    required this.showStart,
    required this.canBreak,
    required this.canResume,
    required this.canEnd,
  });

  final int engagementId;
  final bool busy;
  final WorkerSessionAction? action;
  final bool showStart;
  final bool canBreak;
  final bool canResume;
  final bool canEnd;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final controller = ref.read(
      workerWorkSessionProvider(engagementId).notifier,
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (showStart)
          FilledButton(
            key: const Key('worker-session-start'),
            onPressed: busy
                ? null
                : () async {
                    final ok = await controller.start();
                    if (!context.mounted || !ok) return;
                    showWorkerSnack(context, 'تم بدء جلسة العمل');
                  },
            child: action == WorkerSessionAction.start
                ? const _ActionSpinner()
                : const Text('بدء العمل'),
          ),
        if (canBreak) ...[
          FilledButton(
            key: const Key('worker-session-break'),
            onPressed: busy
                ? null
                : () async {
                    final ok = await controller.beginBreak();
                    if (!context.mounted || !ok) return;
                    showWorkerSnack(context, 'تم تسجيل بداية الاستراحة');
                  },
            child: action == WorkerSessionAction.beginBreak
                ? const _ActionSpinner()
                : const Text('استراحة'),
          ),
          const SizedBox(height: 8),
        ],
        if (canResume) ...[
          FilledButton(
            key: const Key('worker-session-resume'),
            onPressed: busy
                ? null
                : () async {
                    final ok = await controller.resume();
                    if (!context.mounted || !ok) return;
                    showWorkerSnack(context, 'تم استئناف العمل');
                  },
            child: action == WorkerSessionAction.resume
                ? const _ActionSpinner()
                : const Text('استئناف العمل'),
          ),
          const SizedBox(height: 8),
        ],
        if (canEnd)
          OutlinedButton(
            key: const Key('worker-session-end'),
            onPressed: busy ? null : () => _end(context, controller),
            child: action == WorkerSessionAction.end
                ? const _ActionSpinner()
                : const Text('إنهاء العمل'),
          ),
      ],
    );
  }

  Future<void> _end(
    BuildContext context,
    WorkerWorkSessionController controller,
  ) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('إنهاء العمل'),
        content: const Text('إنهاء جلسة العمل الحالية؟'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('إلغاء'),
          ),
          TextButton(
            key: const Key('worker-session-end-confirm'),
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('إنهاء'),
          ),
        ],
      ),
    );
    if (confirmed != true || !context.mounted) return;
    final ok = await controller.end();
    if (!context.mounted || !ok) return;
    showWorkerSnack(context, 'تم إنهاء جلسة العمل');
  }
}

class _HistoryRow extends StatelessWidget {
  const _HistoryRow({required this.session});

  final WorkerWorkSession session;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          session.statusLabel,
          style: const TextStyle(fontWeight: FontWeight.w700),
        ),
        if (session.startedAt != null)
          Text('بدأت: ${_formatDateTime(session.startedAt)}'),
        if (session.endedAt != null)
          Text('انتهت: ${_formatDateTime(session.endedAt)}'),
        if (session.durationSeconds != null)
          Text(
            'المدة: ${formatWorkSessionDuration(session.durationSeconds!)}',
          ),
        for (final segment in session.segments) Text(_segmentLine(segment)),
      ],
    );
  }
}

class _ActionSpinner extends StatelessWidget {
  const _ActionSpinner();

  @override
  Widget build(BuildContext context) {
    return const SizedBox(
      width: 18,
      height: 18,
      child: CircularProgressIndicator(strokeWidth: 2),
    );
  }
}

String _formatDateTime(DateTime? value) {
  if (value == null) return '';
  return DateFormat('yyyy-MM-dd HH:mm').format(value.toLocal());
}

String _segmentLine(WorkerWorkSessionSegment segment) {
  final start = _formatDateTime(segment.startedAt);
  final end = segment.endedAt == null
      ? 'جارية'
      : _formatDateTime(segment.endedAt);
  return '${segment.typeLabel}: $start — $end';
}
