import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/utils/relative_time.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/hasim_search_bar.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_selection.dart';
import 'package:hasim/features/search/providers/global_search_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';

class GlobalSearchScreen extends ConsumerStatefulWidget {
  const GlobalSearchScreen({super.key});

  @override
  ConsumerState<GlobalSearchScreen> createState() => _GlobalSearchScreenState();
}

class _GlobalSearchScreenState extends ConsumerState<GlobalSearchScreen> {
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _openConversation(int id) {
    if (HasimLayout.useConversationSplit(context)) {
      ref.read(desktopSelectedConversationIdProvider.notifier).state = id;
      context.go('/conversations');
      return;
    }
    context.push('/conversations/$id');
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final state = ref.watch(globalSearchControllerProvider);
    final notifier = ref.read(globalSearchControllerProvider.notifier);

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: Text(l10n.globalSearchTitle),
        backgroundColor: HasimTokens.pageBg(context),
        surfaceTintColor: Colors.transparent,
      ),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: HasimSearchBar(
              controller: _controller,
              hintText: l10n.globalSearchHint,
              onChanged: notifier.setQuery,
              onClear: () => notifier.setQuery(''),
            ),
          ),
          if (state.loading) const LinearProgressIndicator(minHeight: 2),
          Expanded(child: _body(context, l10n, state, notifier)),
        ],
      ),
    );
  }

  Widget _body(
    BuildContext context,
    AppLocalizations l10n,
    GlobalSearchState state,
    GlobalSearchController notifier,
  ) {
    if (state.offline) {
      return EmptyState(
        icon: Icons.cloud_off_outlined,
        title: l10n.globalSearchOfflineTitle,
        subtitle: l10n.globalSearchOfflineSubtitle,
        actionLabel: l10n.globalSearchRetry,
        onAction: notifier.retry,
      );
    }

    if (state.error != null) {
      final message = state.error == 'search_failed' ? l10n.globalSearchFailed : state.error!;
      return EmptyState(
        icon: Icons.error_outline,
        title: l10n.globalSearchFailed,
        subtitle: message,
        actionLabel: l10n.globalSearchRetry,
        onAction: notifier.retry,
      );
    }

    final submitted = state.submittedQuery?.trim() ?? '';
    if (submitted.isEmpty) {
      return EmptyState(
        icon: Icons.search_rounded,
        title: l10n.globalSearchTitle,
        subtitle: l10n.globalSearchHint,
      );
    }

    if (!state.loading && (state.result == null || state.result!.isEmpty)) {
      return EmptyState(
        icon: Icons.search_off_rounded,
        title: l10n.globalSearchEmptyTitle,
        subtitle: l10n.globalSearchEmptySubtitle,
      );
    }

    final result = state.result;
    if (result == null) {
      return const SizedBox.shrink();
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
      children: [
        if (result.conversations.isNotEmpty) ...[
          _SectionTitle(l10n.globalSearchSectionConversations),
          for (final c in result.conversations)
            _ConversationTile(
              conversation: c,
              onTap: () => _openConversation(c.id),
            ),
        ],
        if (result.customers.isNotEmpty) ...[
          _SectionTitle(l10n.globalSearchSectionCustomers),
          for (final c in result.customers)
            _CustomerTile(
              customer: c,
              onTap: () => context.push('/customers/${c.id}'),
            ),
        ],
        if (result.emails.isNotEmpty) ...[
          _SectionTitle(l10n.globalSearchSectionEmails),
          for (final e in result.emails)
            _EmailTile(
              email: e,
              onTap: () => context.push('/email/${e.id}'),
            ),
        ],
      ],
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.label);

  final String label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 16, 4, 8),
      child: Text(
        label,
        style: Theme.of(context).textTheme.titleSmall?.copyWith(
              fontWeight: FontWeight.w800,
              color: HasimTokens.mutedColor(context),
            ),
      ),
    );
  }
}

class _ConversationTile extends StatelessWidget {
  const _ConversationTile({required this.conversation, required this.onTap});

  final ConversationModel conversation;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      key: Key('global-search-conversation-${conversation.id}'),
      onTap: onTap,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      leading: CircleAvatar(
        child: Text(
          conversation.title.isNotEmpty ? conversation.title.characters.first : '?',
        ),
      ),
      title: Text(conversation.title, maxLines: 1, overflow: TextOverflow.ellipsis),
      subtitle: Text(
        conversation.lastMessage?.content ?? conversation.channelLabel,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
      trailing: conversation.lastMessageAt == null
          ? null
          : Text(
              inboxListTimeAr(conversation.lastMessageAt!),
              style: TextStyle(fontSize: 12, color: HasimTokens.mutedColor(context)),
            ),
    );
  }
}

class _CustomerTile extends StatelessWidget {
  const _CustomerTile({required this.customer, required this.onTap});

  final CustomerModel customer;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      key: Key('global-search-customer-${customer.id}'),
      onTap: onTap,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      leading: const CircleAvatar(child: Icon(Icons.person_outline)),
      title: Text(customer.name, maxLines: 1, overflow: TextOverflow.ellipsis),
      subtitle: Text(
        [customer.phone, customer.email].where((v) => v != null && v.isNotEmpty).join(' · '),
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
    );
  }
}

class _EmailTile extends StatelessWidget {
  const _EmailTile({required this.email, required this.onTap});

  final EmailMessageModel email;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      key: Key('global-search-email-${email.id}'),
      onTap: onTap,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      leading: const CircleAvatar(child: Icon(Icons.mail_outline)),
      title: Text(
        email.subject?.trim().isNotEmpty == true ? email.subject! : email.sender,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
      subtitle: Text(email.sender, maxLines: 1, overflow: TextOverflow.ellipsis),
    );
  }
}
