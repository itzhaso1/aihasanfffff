import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/async_body.dart';
import 'package:hasim/core/widgets/hasim_search_bar.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_channel_avatar.dart';
import 'package:hasim/features/search/providers/search_controller.dart';

class GlobalSearchScreen extends ConsumerStatefulWidget {
  const GlobalSearchScreen({super.key});

  @override
  ConsumerState<GlobalSearchScreen> createState() => _GlobalSearchScreenState();
}

class _GlobalSearchScreenState extends ConsumerState<GlobalSearchScreen> {
  late final TextEditingController _field;

  @override
  void initState() {
    super.initState();
    _field = TextEditingController(
      text: ref.read(searchControllerProvider).query,
    );
  }

  @override
  void dispose() {
    _field.dispose();
    super.dispose();
  }

  void _syncField(String query) {
    if (_field.text == query) {
      return;
    }
    _field.value = TextEditingValue(
      text: query,
      selection: TextSelection.collapsed(offset: query.length),
    );
  }

  String _customerSubtitle(CustomerModel customer) {
    final parts = <String>[
      if (customer.phone != null && customer.phone!.trim().isNotEmpty)
        customer.phone!.trim(),
      if (customer.email != null && customer.email!.trim().isNotEmpty)
        customer.email!.trim(),
    ];
    return parts.join(' · ');
  }

  String _emailTitle(EmailMessageModel mail) {
    final subject = mail.subject?.trim() ?? '';
    if (subject.isNotEmpty) {
      return subject;
    }
    final preview = mail.preview?.trim() ?? '';
    if (preview.isNotEmpty) {
      return preview;
    }
    return 'بدون عنوان';
  }

  String _emailSubtitle(EmailMessageModel mail) {
    final sender = mail.sender.trim();
    final recipient = mail.recipient.trim();
    if (sender.isNotEmpty && recipient.isNotEmpty) {
      return '$sender → $recipient';
    }
    if (sender.isNotEmpty) {
      return sender;
    }
    return mail.preview?.trim() ?? '';
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(searchControllerProvider);
    final notifier = ref.read(searchControllerProvider.notifier);
    final mint = HasimTokens.pageBg(context);

    ref.listen<String>(searchControllerProvider.select((s) => s.query), (
      previous,
      next,
    ) {
      if (next.isEmpty) {
        _syncField('');
      }
    });

    final idle = state.isIdle && !state.hasQuery;
    final showEmpty =
        !state.loading &&
        state.error == null &&
        state.appliedQuery.isNotEmpty &&
        state.isEmpty;

    return Scaffold(
      key: const Key('global-search-screen'),
      backgroundColor: mint,
      appBar: AppBar(
        backgroundColor: mint,
        surfaceTintColor: Colors.transparent,
        title: const Text('بحث'),
      ),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: HasimSearchBar(
              key: const Key('global-search-field'),
              controller: _field,
              hintText: 'ابحث في المحادثات والعملاء والبريد',
              autofocus: true,
              flat: true,
              onChanged: notifier.onQueryChanged,
              onSubmitted: notifier.submit,
              onClear: notifier.reset,
            ),
          ),
          Expanded(
            child: idle
                ? AsyncBody(
                    loading: false,
                    error: null,
                    isEmpty: true,
                    onRetry: () {},
                    emptyTitle: 'ابحث في حاسم',
                    emptySubtitle: 'ابحث في المحادثات والعملاء والبريد.',
                    child: const SizedBox.shrink(),
                  )
                : AsyncBody(
                    loading: state.loading,
                    error: state.error,
                    isEmpty: showEmpty,
                    onRetry: notifier.retry,
                    emptyTitle: 'لا توجد نتائج',
                    emptySubtitle: 'جرّب كلمة أخرى.',
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(8, 0, 8, 24),
                      children: [
                        _section(
                          context,
                          title: 'المحادثات',
                          children: [
                            for (final item in state.conversations)
                              ListTile(
                                key: Key('search-conversation-${item.id}'),
                                leading: ConversationChannelAvatar(
                                  channel: item.channel,
                                ),
                                title: Text(item.title),
                                subtitle: Text(
                                  [item.channelLabel, item.preview]
                                      .where((part) => part.trim().isNotEmpty)
                                      .join(' · '),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                                trailing: const _ResultKind(label: 'محادثة'),
                                onTap: state.loading
                                    ? null
                                    : () => context.push(
                                        '/conversations/${item.id}',
                                      ),
                              ),
                          ],
                        ),
                        _section(
                          context,
                          title: 'العملاء',
                          children: [
                            for (final item in state.customers)
                              ListTile(
                                key: Key('search-customer-${item.id}'),
                                leading: CircleAvatar(
                                  backgroundColor: HasimTokens.mintFill(
                                    context,
                                  ),
                                  child: Icon(
                                    Icons.person_outline,
                                    color: HasimTokens.inkColor(context),
                                  ),
                                ),
                                title: Text(item.name),
                                subtitle: _customerSubtitle(item).isEmpty
                                    ? null
                                    : Text(
                                        _customerSubtitle(item),
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                      ),
                                trailing: const _ResultKind(label: 'عميل'),
                                onTap: state.loading
                                    ? null
                                    : () => context.push(
                                        '/customers/${item.id}',
                                        extra: item,
                                      ),
                              ),
                          ],
                        ),
                        _section(
                          context,
                          title: 'البريد',
                          children: [
                            for (final item in state.emails)
                              ListTile(
                                key: Key('search-email-${item.id}'),
                                leading: CircleAvatar(
                                  backgroundColor: HasimTokens.mintFill(
                                    context,
                                  ),
                                  child: Icon(
                                    Icons.mail_outline,
                                    color: HasimTokens.inkColor(context),
                                  ),
                                ),
                                title: Text(_emailTitle(item)),
                                subtitle: Text(
                                  _emailSubtitle(item),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                                trailing: const _ResultKind(label: 'بريد'),
                                onTap: state.loading
                                    ? null
                                    : () => context.push('/email/${item.id}'),
                              ),
                          ],
                        ),
                      ],
                    ),
                  ),
          ),
        ],
      ),
    );
  }

  Widget _section(
    BuildContext context, {
    required String title,
    required List<Widget> children,
  }) {
    if (children.isEmpty) {
      return const SizedBox.shrink();
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
          child: Text(
            title,
            style: Theme.of(context).textTheme.titleSmall?.copyWith(
              fontWeight: FontWeight.w800,
              color: HasimTokens.mutedColor(context),
            ),
          ),
        ),
        ...children,
      ],
    );
  }
}

class _ResultKind extends StatelessWidget {
  const _ResultKind({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Text(
      label,
      style: Theme.of(context).textTheme.labelSmall?.copyWith(
        color: HasimTokens.mutedColor(context),
        fontWeight: FontWeight.w700,
      ),
    );
  }
}
