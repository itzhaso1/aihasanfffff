import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/utils/relative_time.dart';
import 'package:hasim/core/widgets/async_body.dart';
import 'package:hasim/core/widgets/hasim_message_card.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/core/widgets/hasim_search_bar.dart';
import 'package:hasim/core/widgets/hasim_segmented_tabs.dart';
import 'package:hasim/core/widgets/hasim_shell_header.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/email/providers/email_controller.dart';
import 'package:hasim/features/worker/presentation/worker_capability_notice.dart';
import 'package:hasim/features/worker/providers/worker_capability_access.dart';

class EmailListScreen extends ConsumerStatefulWidget {
  const EmailListScreen({super.key});

  @override
  ConsumerState<EmailListScreen> createState() => _EmailListScreenState();
}

class _EmailListScreenState extends ConsumerState<EmailListScreen> {
  final _search = TextEditingController();

  static const _tabs = [
    HasimSegmentedTab(value: 'inbox', label: 'الوارد'),
    HasimSegmentedTab(value: 'sent', label: 'المرسل'),
    HasimSegmentedTab(value: 'drafts', label: 'المسودات'),
  ];

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  String _hintFor(String tab) {
    return switch (tab) {
      'sent' => 'البحث في الرسائل المرسلة...',
      'drafts' => 'البحث في المسودات...',
      _ => 'البحث في الرسائل الواردة...',
    };
  }

  String _emptyTitleFor(String tab) {
    return switch (tab) {
      'sent' => 'لا رسائل مرسلة',
      'drafts' => 'لا مسودات',
      _ => 'لا رسائل',
    };
  }

  String _addressOf(String tab, EmailMessageModel message) {
    if (tab == 'inbox') return message.sender;
    return message.recipient.isNotEmpty ? message.recipient : message.sender;
  }

  @override
  Widget build(BuildContext context) {
    final access = ref.watch(workerWorkspaceCapabilityProvider('email.view'));
    if (access.isLoading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (access.hasError) {
      return WorkerCapabilityNotice(
        title: 'البريد',
        message: 'تعذر التحقق من صلاحيات البريد حالياً.',
        onRetry: () =>
            ref.invalidate(workerWorkspaceCapabilityProvider('email.view')),
      );
    }
    final permission = access.value;
    if (permission == null || !permission.workspaceSelected) {
      return const WorkerCapabilityNotice(
        title: 'البريد',
        message: 'اختر شركة أولاً للوصول إلى البريد.',
      );
    }
    if (!permission.allowed) {
      return const WorkerCapabilityNotice(
        title: 'البريد',
        message: 'ليس لديك صلاحيات الوصول إلى البريد.',
      );
    }

    final state = ref.watch(emailControllerProvider);
    final notifier = ref.read(emailControllerProvider.notifier);
    final canCompose = !ref.watch(authControllerProvider).isIndependentWorker;
    final mint = HasimTokens.pageBg(context);

    return Scaffold(
      backgroundColor: mint,
      appBar: HasimShellHeader(
        backgroundColor: mint,
        extraActions: canCompose
            ? [
                IconButton(
                  tooltip: 'رسالة جديدة',
                  onPressed: () => context.push('/email/compose'),
                  icon: const Icon(Icons.edit_outlined),
                ),
              ]
            : const [],
      ),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Padding(
            padding: EdgeInsets.fromLTRB(16, 4, 16, 0),
            child: HasimPageHeader(
              title: 'البريد',
              subtitle: 'كل رسائلك في مكان واحد',
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            child: HasimSegmentedTabs<String>(
              tabs: _tabs,
              selected: state.tab,
              onChanged: notifier.setTab,
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            child: HasimSearchBar(
              controller: _search,
              hintText: _hintFor(state.tab),
              onSubmitted: notifier.setSearch,
              onChanged: (value) {
                if (value.isEmpty) notifier.setSearch('');
              },
              onClear: () => notifier.setSearch(''),
            ),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: notifier.refresh,
              child: AsyncBody(
                loading: state.loading && state.items.isEmpty,
                error: state.error,
                isEmpty: !state.loading && state.items.isEmpty,
                emptyTitle: _emptyTitleFor(state.tab),
                emptySubtitle: 'ستظهر هنا رسائل هذا المجلد فور وصولها.',
                emptyActionLabel: canCompose ? 'رسالة جديدة' : null,
                emptyActionIcon: canCompose ? Icons.edit_outlined : null,
                emptyPillAction: true,
                onEmptyAction: canCompose
                    ? () => context.push('/email/compose')
                    : null,
                onRetry: notifier.refresh,
                child: ListView.separated(
                  padding: const EdgeInsets.fromLTRB(
                    16,
                    0,
                    16,
                    HasimTokens.pageBottom,
                  ),
                  itemCount: state.items.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 10),
                  itemBuilder: (context, i) {
                    final m = state.items[i];
                    return HasimMessageCard(
                      title: m.subject?.isNotEmpty == true
                          ? m.subject!
                          : '(بدون موضوع)',
                      address: _addressOf(state.tab, m),
                      snippet: m.preview,
                      timeLabel: relativeTimeAr(m.createdAt),
                      unread: !m.isRead,
                      starred: m.isStarred,
                      onTap: () => context.push('/email/${m.id}'),
                      onStarTap: () => notifier.toggleStar(m),
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
