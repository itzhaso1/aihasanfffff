import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/utils/relative_time.dart';
import 'package:hasim/core/widgets/async_body.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/core/widgets/hasim_search_bar.dart';
import 'package:hasim/core/widgets/hasim_shell_header.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/conversations/presentation/chat_screen.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_channel_avatar.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_filter_chip.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_panel_resize_handle.dart';
import 'package:hasim/features/conversations/presentation/widgets/customer_info_drawer.dart';
import 'package:hasim/features/conversations/presentation/widgets/inbox_empty_illustration.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_panel_width.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_selection.dart';
import 'package:hasim/features/home/providers/home_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';

/// الشاشة الأولى بعد الدخول — محادثات فقط (بحث + فلاتر + قائمة).
class ConversationsScreen extends ConsumerStatefulWidget {
  const ConversationsScreen({super.key, this.initialSelectedId});

  /// عند فتح رابط محادثة على سطح المكتب قبل مزامنة الاختيار.
  final int? initialSelectedId;

  static const emptyTitle = 'لا توجد محادثات حاليًا';
  static const emptySubtitle = 'ستظهر هنا رسائل واتساب والويب والقنوات الموحدة.';

  @override
  ConsumerState<ConversationsScreen> createState() => _ConversationsScreenState();
}

class _ConversationsScreenState extends ConsumerState<ConversationsScreen> {
  final _search = TextEditingController();

  /// فلاتر مدعومة في Mobile API: all | unread | archived
  /// «المفضلة» غير متاحة كفلتر محادثات في الـ API — لا نخترع فلترًا وهميًا.
  static const _filters = [
    ('all', 'الكل'),
    ('unread', 'غير مقروءة'),
    ('archived', 'مؤرشفة'),
  ];

  static const _channels = [
    ('whatsapp', 'واتساب'),
    ('email', 'بريد'),
    ('web', 'ويب'),
    ('manual', 'يدوي'),
  ];

  @override
  void initState() {
    super.initState();
    final initial = widget.initialSelectedId;
    if (initial != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        ref.read(conversationsControllerProvider.notifier).clearUnreadInList(initial);
        ref.read(desktopSelectedConversationIdProvider.notifier).state = initial;
      });
    }
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  void _openConversation(int id) {
    ref.read(conversationsControllerProvider.notifier).clearUnreadInList(id);
    if (HasimLayout.useConversationSplit(context)) {
      ref.read(desktopSelectedConversationIdProvider.notifier).state = id;
      return;
    }
    context.push('/conversations/$id');
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(conversationsControllerProvider);
    final notifier = ref.read(conversationsControllerProvider.notifier);
    final theme = Theme.of(context);
    final canvas = HasimTokens.chatCanvas(context);
    final split = HasimLayout.useConversationSplit(context);
    final selectedId = widget.initialSelectedId ?? ref.watch(desktopSelectedConversationIdProvider);
    final drawerOpen = split && ref.watch(desktopCustomerDrawerOpenProvider);
    ref.watch(desktopConversationPanelWidthProvider);
    final panelWidth = split
        ? ref.read(desktopConversationPanelWidthProvider.notifier).resolve(
              context,
              customerDrawerOpen: drawerOpen,
            )
        : HasimLayout.conversationPanelWidth(context);
    ConversationModel? selectedConversation;
    if (selectedId != null) {
      for (final item in state.items) {
        if (item.id == selectedId) {
          selectedConversation = item;
          break;
        }
      }
    }

    final panel = _ConversationsPanel(
      search: _search,
      state: state,
      notifier: notifier,
      theme: theme,
      filters: _filters,
      channels: _channels,
      selectedId: split ? selectedId : null,
      compactEmpty: split,
      onOpen: _openConversation,
      onRefresh: () async {
        await notifier.refresh();
        await ref.read(homeControllerProvider.notifier).refresh();
      },
      onGlobalSearch: () => context.push('/search'),
    );

    if (!split) {
      return Scaffold(
        backgroundColor: HasimTokens.cardBg(context),
        appBar: HasimShellHeader(backgroundColor: HasimTokens.cardBg(context)),
        body: panel,
      );
    }

    return Scaffold(
      backgroundColor: HasimTokens.cardBg(context),
      body: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: panelWidth,
            child: ColoredBox(
              color: HasimTokens.pageBg(context),
              child: ClipRRect(
                borderRadius: const BorderRadius.only(topRight: Radius.circular(16)),
                child: ColoredBox(
                  color: HasimTokens.cardBg(context),
                  child: panel,
                ),
              ),
            ),
          ),
          const ConversationPanelResizeHandle(),
          Expanded(
            child: ColoredBox(
              color: canvas,
              child: _DesktopChatPane(
                selectedId: selectedId,
                listEmpty: !state.loading && state.items.isEmpty,
                listError: state.error,
                onRetry: () => notifier.refresh(),
                onEmptyAction: () => context.push('/contacts'),
              ),
            ),
          ),
          if (drawerOpen && selectedConversation != null)
            SizedBox(
              width: HasimLayout.customerDrawerWidth,
              child: CustomerInfoDrawer(
                conversation: selectedConversation,
                onClose: () => ref.read(desktopCustomerDrawerOpenProvider.notifier).state = false,
              ),
            ),
        ],
      ),
    );
  }
}

class _ConversationsPanel extends StatelessWidget {
  const _ConversationsPanel({
    required this.search,
    required this.state,
    required this.notifier,
    required this.theme,
    required this.filters,
    required this.channels,
    required this.selectedId,
    required this.compactEmpty,
    required this.onOpen,
    required this.onRefresh,
    required this.onGlobalSearch,
  });

  final TextEditingController search;
  final ConversationsState state;
  final ConversationsController notifier;
  final ThemeData theme;
  final List<(String, String)> filters;
  final List<(String, String)> channels;
  final int? selectedId;
  final bool compactEmpty;
  final ValueChanged<int> onOpen;
  final Future<void> Function() onRefresh;
  final VoidCallback onGlobalSearch;

  @override
  Widget build(BuildContext context) {
    final isMobile = HasimLayout.isCompact(context);
    final l10n = AppLocalizations.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (!isMobile)
          const Padding(
            padding: EdgeInsets.fromLTRB(16, 4, 16, 0),
            child: HasimPageHeader(title: 'المحادثات'),
          ),
        Padding(
          padding: EdgeInsets.fromLTRB(16, isMobile ? 8 : 4, 16, 0),
          child: Row(
            children: [
              Expanded(
                child: HasimSearchBar(
                  controller: search,
                  hintText: 'ابحث في المحادثات',
                  flat: true,
                  onSubmitted: notifier.setSearch,
                  onChanged: (v) {
                    if (v.isEmpty) notifier.setSearch('');
                  },
                  onClear: () => notifier.setSearch(''),
                ),
              ),
              IconButton(
                key: const Key('inbox-global-search'),
                tooltip: l10n.globalSearchTitle,
                onPressed: onGlobalSearch,
                icon: Icon(Icons.manage_search_rounded, color: HasimTokens.mutedColor(context)),
              ),
            ],
          ),
        ),
        if (isMobile)
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
            child: Row(
              children: [
                ConversationFilterChip(
                  label: 'الكل',
                  selected: state.filter == 'all' && state.channel == null,
                  onTap: () {
                    notifier.setFilter('all');
                    notifier.setChannel(null);
                  },
                ),
                const SizedBox(width: 8),
                ConversationFilterChip(
                  key: const Key('conversation-filter-archived'),
                  label: 'مؤرشفة',
                  selected: state.filter == 'archived',
                  onTap: () {
                    notifier.setFilter(state.filter == 'archived' ? 'all' : 'archived');
                    notifier.setChannel(null);
                  },
                ),
                const SizedBox(width: 8),
                for (final c in channels) ...[
                  ConversationFilterChip(
                    label: c.$2,
                    selected: state.channel == c.$1,
                    onTap: () {
                      if (state.channel == c.$1) {
                        notifier.setChannel(null);
                        return;
                      }
                      notifier.setFilter('all');
                      notifier.setChannel(c.$1);
                    },
                  ),
                  const SizedBox(width: 8),
                ],
              ],
            ),
          )
        else ...[
          Padding(
            padding: EdgeInsets.fromLTRB(16, 14, 16, HasimLayout.useConversationSplit(context) ? 8 : 4),
            child: HasimLayout.useConversationSplit(context)
                ? DesktopAdaptiveConversationFilters(
                    primary: () => [
                      for (final f in filters)
                        ConversationFilterChip(
                          key: Key('conversation-filter-${f.$1}'),
                          label: f.$2,
                          selected: state.filter == f.$1,
                          onTap: () => notifier.setFilter(f.$1),
                          leading: switch (f.$1) {
                            'unread' => const _UnreadDot(),
                            'archived' => Icon(
                                Icons.calendar_today_outlined,
                                size: 14,
                                color: theme.colorScheme.onSurface.withValues(alpha: 0.45),
                              ),
                            _ => null,
                          },
                        ),
                    ],
                    extras: () => [
                      for (final c in const [
                        ('whatsapp', 'واتساب'),
                        ('email', 'بريد'),
                        ('web', 'ويب'),
                        ('manual', 'رسيدي'),
                      ])
                        ConversationFilterChip(
                          key: Key('conversation-extra-${c.$1}'),
                          label: c.$2,
                          selected: state.channel == c.$1,
                          onTap: () => notifier.setChannel(state.channel == c.$1 ? null : c.$1),
                        ),
                    ],
                    dropdown: (promoted) {
                      const channels = [
                        ('whatsapp', 'واتساب', Icons.chat),
                        ('email', 'بريد', Icons.mail_outline),
                        ('web', 'ويب', Icons.language),
                        ('manual', 'رسيدي', Icons.forum_outlined),
                      ];
                      final hidden = channels.skip(promoted).toList();
                      return ConversationChannelDropdown(
                        channels: hidden,
                        selectedChannel: state.channel,
                        onSelected: notifier.setChannel,
                      );
                    },
                  )
                : SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: [
                        for (final f in filters) ...[
                          ConversationFilterChip(
                            label: f.$2,
                            selected: state.filter == f.$1,
                            onTap: () => notifier.setFilter(f.$1),
                            leading: switch (f.$1) {
                              'unread' => const _UnreadDot(),
                              'archived' => Icon(
                                  Icons.calendar_today_outlined,
                                  size: 14,
                                  color: theme.colorScheme.onSurface.withValues(alpha: 0.45),
                                ),
                              _ => null,
                            },
                          ),
                          const SizedBox(width: 8),
                        ],
                      ],
                    ),
                  ),
          ),
          if (!HasimLayout.useConversationSplit(context))
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.fromLTRB(16, 6, 16, 8),
              child: Row(
                children: [
                  for (final c in channels) ...[
                    ConversationChannelChip(
                      label: c.$2,
                      selected: state.channel == c.$1,
                      onSelected: (sel) => notifier.setChannel(sel ? c.$1 : null),
                    ),
                    const SizedBox(width: 8),
                  ],
                ],
              ),
            ),
        ],
        Expanded(
          child: RefreshIndicator(
            color: AppTheme.brand,
            onRefresh: onRefresh,
            child: state.loading && state.items.isEmpty
                ? const SkeletonList()
                : compactEmpty && !state.loading && state.items.isEmpty && state.error == null
                    ? ListView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        children: const [SizedBox(height: 1)],
                      )
                    : AsyncBody(
                        loading: false,
                        error: state.error,
                        isEmpty: !state.loading && state.items.isEmpty,
                        emptyTitle: ConversationsScreen.emptyTitle,
                        emptySubtitle: ConversationsScreen.emptySubtitle,
                        emptyIllustration: const InboxEmptyIllustration(),
                        emptyActionLabel: 'ابدأ محادثة جديدة',
                        emptyActionIcon: Icons.add_rounded,
                        emptyPillAction: true,
                        onEmptyAction: () => context.push('/contacts'),
                        onRetry: () => notifier.refresh(),
                        child: NotificationListener<ScrollNotification>(
                          onNotification: (n) {
                            if (n.metrics.pixels > n.metrics.maxScrollExtent - 200) {
                              notifier.loadMore();
                            }
                            return false;
                          },
                          child: ListView.builder(
                            itemCount: state.items.length + (state.loadingMore ? 1 : 0),
                            itemBuilder: (context, index) {
                              if (index >= state.items.length) {
                                return const Padding(
                                  padding: EdgeInsets.all(16),
                                  child: Center(child: CircularProgressIndicator()),
                                );
                              }
                              final c = state.items[index];
                              final time = inboxListTimeAr(c.lastMessageAt);
                              final selected = selectedId != null && selectedId == c.id;
                              final unread = c.unreadCount > 0 && !selected;
                              final titleInitial = c.title.isNotEmpty ? c.title.characters.first : '#';
                              return Material(
                                color: selected ? HasimTokens.conversationSelected(context) : Colors.transparent,
                                child: InkWell(
                                  onTap: () => onOpen(c.id),
                                  child: Padding(
                                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                                    child: Row(
                                      children: [
                                        CircleAvatar(
                                          radius: 22,
                                          backgroundColor: HasimTokens.chatGreen.withValues(alpha: 0.14),
                                          child: Text(
                                            titleInitial,
                                            style: const TextStyle(
                                              color: HasimTokens.chatGreen,
                                              fontWeight: FontWeight.w800,
                                              fontSize: 16,
                                            ),
                                          ),
                                        ),
                                        const SizedBox(width: 12),
                                        Expanded(
                                          child: Column(
                                            crossAxisAlignment: CrossAxisAlignment.start,
                                            children: [
                                              Row(
                                                children: [
                                                  Expanded(
                                                    child: Row(
                                                      children: [
                                                        Flexible(
                                                          child: Text(
                                                            c.title,
                                                            maxLines: 1,
                                                            overflow: TextOverflow.ellipsis,
                                                            style: TextStyle(
                                                              fontWeight: unread ? FontWeight.w800 : FontWeight.w700,
                                                              fontSize: 14,
                                                            ),
                                                          ),
                                                        ),
                                                        const SizedBox(width: 4),
                                                        ConversationChannelIcon(
                                                          channel: c.channel,
                                                          tooltip: c.channelLabel,
                                                        ),
                                                        if (c.muted)
                                                          Padding(
                                                            padding: const EdgeInsetsDirectional.only(start: 4),
                                                            child: Icon(
                                                              Icons.notifications_off_outlined,
                                                              size: 14,
                                                              color: theme.colorScheme.onSurfaceVariant,
                                                            ),
                                                          ),
                                                      ],
                                                    ),
                                                  ),
                                                  Text(
                                                    time,
                                                    style: theme.textTheme.labelSmall?.copyWith(
                                                      color: unread
                                                          ? theme.colorScheme.primary
                                                          : theme.colorScheme.onSurfaceVariant,
                                                      fontWeight: FontWeight.w500,
                                                    ),
                                                  ),
                                                ],
                                              ),
                                              const SizedBox(height: 4),
                                              Row(
                                                children: [
                                                  Expanded(
                                                    child: Text(
                                                      c.preview,
                                                      maxLines: 1,
                                                      overflow: TextOverflow.ellipsis,
                                                      style: TextStyle(
                                                        fontSize: 12.5,
                                                        color: theme.colorScheme.onSurfaceVariant,
                                                        fontWeight: unread ? FontWeight.w600 : FontWeight.w400,
                                                      ),
                                                    ),
                                                  ),
                                                  if (unread)
                                                    Padding(
                                                      padding: const EdgeInsetsDirectional.only(start: 8),
                                                      child: CircleAvatar(
                                                        radius: 10,
                                                        backgroundColor: HasimTokens.chatGreen,
                                                        child: Text(
                                                          '${c.unreadCount}',
                                                          style: const TextStyle(
                                                            color: Colors.white,
                                                            fontSize: 10,
                                                            fontWeight: FontWeight.w700,
                                                          ),
                                                        ),
                                                      ),
                                                    ),
                                                ],
                                              ),
                                            ],
                                          ),
                                        ),
                                        _ConversationOverflowMenu(
                                          conversation: c,
                                          busy: state.mutatingConversationId == c.id,
                                          l10n: l10n,
                                          onArchive: () => notifier.setArchived(c.id, archived: !c.archived),
                                          onMute: () => notifier.setMuted(c.id, muted: !c.muted),
                                        ),
                                      ],
                                    ),
                                  ),
                                ),
                              );
                            },
                          ),
                        ),
                      ),
          ),
        ),
      ],
    );
  }
}

class _DesktopChatPane extends StatelessWidget {
  const _DesktopChatPane({
    required this.selectedId,
    required this.listEmpty,
    required this.listError,
    required this.onRetry,
    required this.onEmptyAction,
  });

  final int? selectedId;
  final bool listEmpty;
  final String? listError;
  final VoidCallback onRetry;
  final VoidCallback onEmptyAction;

  @override
  Widget build(BuildContext context) {
    if (selectedId != null) {
      return ChatScreen(
        key: ValueKey(selectedId),
        conversationId: selectedId!,
        embedded: true,
      );
    }

    final illustration = InboxEmptyIllustration(size: HasimLayout.isCompact(context) ? 200 : 280);

    if (listError != null) {
      return EmptyState(
        title: listError!,
        actionLabel: 'إعادة المحاولة',
        onAction: onRetry,
      );
    }

    if (listEmpty) {
      return EmptyState(
        title: ConversationsScreen.emptyTitle,
        subtitle: ConversationsScreen.emptySubtitle,
        illustration: illustration,
        actionLabel: 'ابدأ محادثة جديدة',
        actionIcon: Icons.add_rounded,
        pillAction: true,
        onAction: onEmptyAction,
      );
    }

    return EmptyState(
      title: 'اختر محادثة لبدء المحادثة',
      subtitle: ConversationsScreen.emptySubtitle,
      illustration: illustration,
    );
  }
}

class _ConversationOverflowMenu extends StatelessWidget {
  const _ConversationOverflowMenu({
    required this.conversation,
    required this.busy,
    required this.l10n,
    required this.onArchive,
    required this.onMute,
  });

  final ConversationModel conversation;
  final bool busy;
  final AppLocalizations l10n;
  final Future<String?> Function() onArchive;
  final Future<String?> Function() onMute;

  @override
  Widget build(BuildContext context) {
    return PopupMenuButton<String>(
      key: Key('conversation-actions-${conversation.id}'),
      enabled: !busy,
      tooltip: l10n.conversationActions,
      padding: EdgeInsets.zero,
      onSelected: (value) async {
        final error = value == 'archive' ? await onArchive() : await onMute();
        if (!context.mounted || error == null) return;
        final fallback = value == 'archive'
            ? (conversation.archived ? l10n.unarchiveFailed : l10n.archiveFailed)
            : (conversation.muted ? l10n.unmuteFailed : l10n.muteFailed);
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(fallback)));
      },
      itemBuilder: (context) => [
        PopupMenuItem(
          value: 'archive',
          child: Text(
            conversation.archived ? l10n.unarchiveConversation : l10n.archiveConversation,
          ),
        ),
        PopupMenuItem(
          value: 'mute',
          child: Text(
            conversation.muted ? l10n.unmuteConversation : l10n.muteConversation,
          ),
        ),
      ],
      icon: busy
          ? const SizedBox(
              width: 20,
              height: 20,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          : const Icon(Icons.more_vert_rounded),
    );
  }
}

class _UnreadDot extends StatelessWidget {
  const _UnreadDot();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 8,
      height: 8,
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.onSurface.withValues(alpha: 0.35),
        shape: BoxShape.circle,
      ),
    );
  }
}
