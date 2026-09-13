import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/widgets/async_body.dart';
import 'package:hasim/core/widgets/hasim_shell_header.dart';
import 'package:hasim/features/email/providers/email_controller.dart';

class EmailListScreen extends ConsumerWidget {
  const EmailListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(emailControllerProvider);
    final notifier = ref.read(emailControllerProvider.notifier);
    return Scaffold(
      appBar: HasimShellHeader(
        extraActions: [
          IconButton(
            tooltip: 'رسالة جديدة',
            onPressed: () => context.push('/email/compose'),
            icon: const Icon(Icons.edit_outlined),
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: Align(
              alignment: AlignmentDirectional.centerStart,
              child: Text('البريد', style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(8),
            child: SegmentedButton<String>(
              segments: const [
                ButtonSegment(value: 'inbox', label: Text('الوارد')),
                ButtonSegment(value: 'sent', label: Text('المرسل')),
                ButtonSegment(value: 'drafts', label: Text('المسودات')),
              ],
              selected: {state.tab},
              onSelectionChanged: (s) => notifier.setTab(s.first),
            ),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: notifier.refresh,
              child: AsyncBody(
                loading: state.loading && state.items.isEmpty,
                error: state.error,
                isEmpty: !state.loading && state.items.isEmpty,
                emptyTitle: 'لا رسائل',
                onRetry: notifier.refresh,
                child: ListView.separated(
                  itemCount: state.items.length,
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (context, i) {
                    final m = state.items[i];
                    return ListTile(
                      onTap: () => context.push('/email/${m.id}'),
                      leading: Icon(m.isRead ? Icons.mail_outline : Icons.mark_email_unread, color: Theme.of(context).colorScheme.primary),
                      title: Text(m.subject?.isNotEmpty == true ? m.subject! : '(بدون موضوع)', style: TextStyle(fontWeight: m.isRead ? FontWeight.w500 : FontWeight.w800)),
                      subtitle: Text('${m.sender}\n${m.preview ?? ''}', maxLines: 2, overflow: TextOverflow.ellipsis),
                      isThreeLine: true,
                      trailing: m.isStarred ? const Icon(Icons.star, color: Colors.amber) : null,
                    );
                  },
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
