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
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/features/conversations/data/chat_wallpaper_catalog.dart';
import 'package:hasim/features/conversations/presentation/chat_screen.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_channel_avatar.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_filter_chip.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_panel_resize_handle.dart';
import 'package:hasim/features/conversations/presentation/widgets/customer_info_drawer.dart';
import 'package:hasim/features/conversations/presentation/widgets/inbox_empty_illustration.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_panel_width.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_selection.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/home/providers/home_controller.dart';
import 'package:hasim/features/worker/providers/worker_inbox_workspace_controller.dart';
import 'package:hasim/features/privacy/presentation/locked_chats_navigation.dart';
import 'package:hasim/features/privacy/presentation/pin_prompt.dart';
import 'package:hasim/features/privacy/providers/chat_lock_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:hasim/router/worker_nav.dart';

/// الشاشة الأولى بعد الدخول — محادثات فقط (بحث + فلاتر + قائمة).
///
/// [folder] يفتح صفحة مستقلة: `archived` للمؤرشفة أو `locked` للدردشات المقفلة.
class ConversationsScreen extends ConsumerStatefulWidget {
  const ConversationsScreen({
    super.key,
    this.initialSelectedId,
    this.initialChannel,
    this.folder,
  });

  /// عند فتح رابط محادثة على سطح المكتب قبل مزامنة الاختيار.
  final int? initialSelectedId;

  /// قناة مطلوبة من مسار مثل `/conversations?channel=whatsapp`.
  final String? initialChannel;

  /// `archived` أو `locked`. فارغ يعني صفحة المحادثات.
  final String? folder;

  static const emptyTitle = 'لا توجد محادثات حاليًا';
  static const emptySubtitle =
      'ستظهر هنا رسائل واتساب والويب والقنوات الموحدة.';

  @override
  ConsumerState<ConversationsScreen> createState() =>
      _ConversationsScreenState();
}

String _folderEmptyTitle(String? folder) {
  return switch (folder) {
    'locked' => 'لا توجد دردشات مقفلة',
    'archived' => 'لا توجد محادثات مؤرشفة',
    _ => ConversationsScreen.emptyTitle,
  };
}

String _folderEmptySubtitle(String? folder) {
  return switch (folder) {
    'locked' => 'اقفل دردشة من قائمتها لتظهر هنا بعد المصادقة.',
    'archived' => 'عند أرشفة محادثة من القائمة تظهر هنا.',
    _ => ConversationsScreen.emptySubtitle,
  };
}

class _ConversationsScreenState extends ConsumerState<ConversationsScreen> {
  final _search = TextEditingController();
  int? _folderSelectedId;
  int? _actionConversationId;

  /// فلاتر مدعومة في Mobile API: all | unread | archived
  /// «المفضلة» غير متاحة كفلتر محادثات في الـ API — لا نخترع فلترًا وهميًا.
  static const _filters = [('all', 'الكل'), ('unread', 'غير مقروءة')];

  static const _channels = [
    ('whatsapp', 'واتساب'),
    ('email', 'بريد'),
    ('web', 'ويب'),
    ('facebook_messenger', 'ماسنجر'),
    ('instagram', 'إنستغرام'),
  ];

  bool get _isFolder => widget.folder != null;

  ConversationsController get _inbox => _isFolder
      ? ref.read(inboxFolderControllerProvider(widget.folder!).notifier)
      : ref.read(conversationsControllerProvider.notifier);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      precacheImage(
        ChatWallpaperCatalog.decorationImage(
          ChatWallpaperCatalog.defaultId,
        ).image,
        context,
      );
    });
    if (_isFolder) return;
    final initial = widget.initialSelectedId;
    if (initial != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        ref
            .read(conversationsControllerProvider.notifier)
            .clearUnreadInList(initial);
        ref.read(desktopSelectedConversationIdProvider.notifier).state =
            initial;
      });
    }
    _applyInitialChannel(widget.initialChannel);
  }

  @override
  void didUpdateWidget(covariant ConversationsScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.initialChannel != widget.initialChannel) {
      _applyInitialChannel(widget.initialChannel);
    }
  }

  void _applyInitialChannel(String? raw) {
    final channel = raw?.trim();
    if (channel == null || channel.isEmpty) return;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      ref.read(conversationsControllerProvider.notifier).setChannel(channel);
    });
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  void _openConversation(int id) {
    final currentState = _isFolder
        ? ref.read(inboxFolderControllerProvider(widget.folder!))
        : ref.read(conversationsControllerProvider);
    ConversationModel? initialConversation;
    for (final item in currentState.items) {
      if (item.id == id) {
        initialConversation = item;
        break;
      }
    }
    if (_actionConversationId != null) {
      setState(() => _actionConversationId = null);
    }
    final navRailWidth = HasimLayout.navRailWidth;
    final split = HasimLayout.useConversationSplit(
      context,
      navRailWidth: navRailWidth,
    );
    if (split) {
      _inbox.clearUnreadInList(id);
      ref.read(chatControllerProvider(id));
      if (_isFolder) {
        setState(() => _folderSelectedId = id);
      } else {
        ref.read(desktopSelectedConversationIdProvider.notifier).state = id;
      }
      return;
    }
    // Phone: push first. Rebuilding the inbox list or starting ChatController
    // on this frame contends with the route animation and feels like a freeze.
    context.push('/conversations/$id', extra: initialConversation);
  }

  void _startConversationActionMode(int id) {
    if (!HasimLayout.isCompact(context)) {
      return;
    }
    setState(() => _actionConversationId = id);
  }

  void _closeConversationActionMode() {
    if (!mounted) {
      return;
    }
    setState(() => _actionConversationId = null);
  }

  Future<void> _archiveSelectedConversation(
    ConversationModel conversation,
  ) async {
    final error = await _inbox.setArchived(
      conversation.id,
      archived: !conversation.archived,
    );
    if (!mounted) {
      return;
    }
    if (error != null) {
      final l10n = AppLocalizations.of(context);
      final message = conversation.archived
          ? l10n.unarchiveFailed
          : l10n.archiveFailed;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(message)));
      return;
    }
    _closeConversationActionMode();
  }

  Future<void> _muteSelectedConversation(ConversationModel conversation) async {
    final error = await _inbox.setMuted(
      conversation.id,
      muted: !conversation.muted,
    );
    if (!mounted) {
      return;
    }
    if (error != null) {
      final l10n = AppLocalizations.of(context);
      final message = conversation.muted ? l10n.unmuteFailed : l10n.muteFailed;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(message)));
      return;
    }
    _closeConversationActionMode();
  }

  Future<void> _toggleSelectedConversationLock(
    ConversationModel conversation,
    bool locked,
  ) async {
    if (locked) {
      await ref
          .read(chatLockControllerProvider.notifier)
          .setConversationLocked(conversation.id, locked: false);
    } else {
      await lockConversation(context, ref, conversation.id);
    }
    if (mounted) {
      _closeConversationActionMode();
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final showSpecialInboxSections = auth.canSeeSpecialInboxSections;
    final activeWorkspaceId = ref.watch(activeWorkspaceIdProvider);
    final workerWorkspaceState = auth.isIndependentWorker && !_isFolder
        ? ref.watch(workerInboxWorkspaceControllerProvider)
        : const WorkerInboxWorkspaceState();
    final inboxState = _isFolder
        ? ref.watch(inboxFolderControllerProvider(widget.folder!))
        : ref.watch(conversationsControllerProvider);
    // Without a company the worker Inbox has nothing to request, so the list
    // follows the company list load instead of waiting forever.
    final workerWithoutInbox =
        auth.isIndependentWorker && !_isFolder && activeWorkspaceId == null;
    final state = workerWithoutInbox
        ? ConversationsState(
            loading: workerWorkspaceState.loading,
            error: workerWorkspaceState.error,
          )
        : inboxState;
    final canStartConversation =
        widget.folder == null && !auth.isIndependentWorker;
    final emptySubtitle = workerWithoutInbox
        ? 'لا توجد شركة متاحة للمحادثات'
        : _folderEmptySubtitle(widget.folder);
    final notifier = _inbox;
    void retry() {
      if (workerWithoutInbox) {
        ref
            .read(workerInboxWorkspaceControllerProvider.notifier)
            .load(force: true);
        return;
      }
      notifier.refresh();
    }

    final theme = Theme.of(context);
    final canvas = HasimTokens.chatCanvas(context);
    final navRailWidth = HasimLayout.navRailWidth;
    final isMobile = HasimLayout.isCompact(context);
    final split = HasimLayout.useConversationSplit(
      context,
      navRailWidth: navRailWidth,
    );
    final chatsUnlocked = ref.watch(
      chatLockControllerProvider.select((lock) => lock.chatsUnlocked),
    );
    final gated = widget.folder == 'locked' && !chatsUnlocked;
    final selectedId = gated
        ? null
        : _isFolder
        ? _folderSelectedId
        : widget.initialSelectedId ??
              ref.watch(desktopSelectedConversationIdProvider);
    final drawerOpen = split && ref.watch(desktopCustomerDrawerOpenProvider);
    ref.watch(desktopConversationPanelWidthProvider);
    final panelWidth = split
        ? ref
              .read(desktopConversationPanelWidthProvider.notifier)
              .resolve(
                context,
                customerDrawerOpen: drawerOpen,
                navRailWidth: navRailWidth,
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

    ConversationModel? actionConversation;
    if (_actionConversationId != null) {
      for (final item in state.items) {
        if (item.id == _actionConversationId) {
          actionConversation = item;
          break;
        }
      }
    }
    final selectionMode = isMobile && !split && actionConversation != null;

    final lockedIds = ref.watch(lockedConversationIdsProvider);
    final panel = _ConversationsPanel(
      search: _search,
      state: state,
      notifier: notifier,
      theme: theme,
      filters: _filters,
      channels: _channels,
      selectedId: split ? selectedId : null,
      actionSelectedId: actionConversation?.id,
      selectionMode: selectionMode,
      compactEmpty: split,
      lockedIds: lockedIds,
      chatsUnlocked: chatsUnlocked,
      folder: widget.folder,
      canStartConversation: canStartConversation,
      emptySubtitle: emptySubtitle,
      onRetry: retry,
      onOpen: _openConversation,
      onSelectForActions: _startConversationActionMode,
      onRefresh: () async {
        if (auth.isIndependentWorker && !_isFolder) {
          await ref
              .read(workerInboxWorkspaceControllerProvider.notifier)
              .load(force: true);
        }
        await notifier.refresh();
        if (!auth.isIndependentWorker) {
          await ref.read(homeControllerProvider.notifier).refresh();
        }
      },
      onOpenArchive: () => openArchivePage(context, ref),
      onOpenLocked: () {
        if (widget.folder == 'locked') {
          openLockedChats(context, ref);
          return;
        }
        openLockedPage(context);
      },
      onLock: (id) => lockConversation(context, ref, id),
      onUnlock: (id) => ref
          .read(chatLockControllerProvider.notifier)
          .setConversationLocked(id, locked: false),
      showSpecialInboxSections: showSpecialInboxSections,
    );

    final scaffold = !split
        ? Scaffold(
            backgroundColor: HasimTokens.cardBg(context),
            appBar: selectionMode
                ? _ConversationSelectionAppBar(
                    conversation: actionConversation,
                    locked: lockedIds.contains(actionConversation.id),
                    showSpecialInboxSections: showSpecialInboxSections,
                    onClose: _closeConversationActionMode,
                    onArchive: () =>
                        _archiveSelectedConversation(actionConversation!),
                    onMute: () =>
                        _muteSelectedConversation(actionConversation!),
                    onLock: () => _toggleSelectedConversationLock(
                      actionConversation!,
                      lockedIds.contains(actionConversation.id),
                    ),
                  )
                : _isFolder
                ? null
                : HasimShellHeader(
                    backgroundColor: HasimTokens.cardBg(context),
                  ),
            body: _isFolder
                ? SafeArea(top: !selectionMode, bottom: false, child: panel)
                : panel,
          )
        : Scaffold(
            backgroundColor: HasimTokens.cardBg(context),
            body: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SizedBox(
                  width: panelWidth,
                  child: ColoredBox(
                    color: HasimTokens.cardBg(context),
                    child: panel,
                  ),
                ),
                ConversationPanelResizeHandle(navRailWidth: navRailWidth),
                Expanded(
                  child: ColoredBox(
                    color: canvas,
                    child: _DesktopChatPane(
                      selectedId: selectedId,
                      initialConversation: selectedConversation,
                      listEmpty:
                          !gated && !state.loading && state.items.isEmpty,
                      listError: gated ? null : state.error,
                      blocked: gated,
                      emptyTitle: _folderEmptyTitle(widget.folder),
                      emptySubtitle: emptySubtitle,
                      showEmptyAction: canStartConversation,
                      idleTitle: widget.folder == null
                          ? 'اختر محادثة لبدء المحادثة'
                          : 'اختر محادثة',
                      idleSubtitle: widget.folder == null
                          ? ConversationsScreen.emptySubtitle
                          : null,
                      onRetry: retry,
                      onEmptyAction: () => context.push('/contacts'),
                    ),
                  ),
                ),
                if (drawerOpen && selectedConversation != null)
                  SizedBox(
                    width: HasimLayout.customerDrawerWidth,
                    child: CustomerInfoDrawer(
                      conversation: selectedConversation,
                      onClose: () =>
                          ref
                                  .read(
                                    desktopCustomerDrawerOpenProvider.notifier,
                                  )
                                  .state =
                              false,
                    ),
                  ),
              ],
            ),
          );

    if (_isFolder || !auth.isIndependentWorker) {
      return scaffold;
    }

    return PopScope(
      canPop: !auth.isIndependentWorker || context.canPop(),
      onPopInvokedWithResult: (didPop, _) {
        if (didPop || !auth.isIndependentWorker) {
          return;
        }
        if (context.canPop()) {
          return;
        }
        context.go(workerEngagementsLocation);
      },
      child: scaffold,
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
    required this.actionSelectedId,
    required this.selectionMode,
    required this.compactEmpty,
    required this.onOpen,
    required this.onSelectForActions,
    required this.onRefresh,
    required this.onOpenArchive,
    required this.onOpenLocked,
    required this.showSpecialInboxSections,
    required this.onLock,
    required this.onUnlock,
    required this.lockedIds,
    required this.chatsUnlocked,
    required this.canStartConversation,
    required this.emptySubtitle,
    required this.onRetry,
    this.folder,
  });

  final TextEditingController search;
  final ConversationsState state;
  final ConversationsController notifier;
  final ThemeData theme;
  final List<(String, String)> filters;
  final List<(String, String)> channels;
  final int? selectedId;
  final int? actionSelectedId;
  final bool selectionMode;
  final bool compactEmpty;
  final ValueChanged<int> onOpen;
  final ValueChanged<int> onSelectForActions;
  final Future<void> Function() onRefresh;
  final VoidCallback onOpenArchive;
  final VoidCallback onOpenLocked;
  final bool showSpecialInboxSections;
  final ValueChanged<int> onLock;
  final ValueChanged<int> onUnlock;
  final Set<int> lockedIds;
  final bool chatsUnlocked;
  final bool canStartConversation;
  final String emptySubtitle;
  final VoidCallback onRetry;
  final String? folder;

  @override
  Widget build(BuildContext context) {
    final isMobile = HasimLayout.isCompact(context);
    final l10n = AppLocalizations.of(context);
    final gated = folder == 'locked' && !chatsUnlocked;
    final title = switch (folder) {
      'locked' => 'الدردشات المقفلة',
      'archived' => 'الدردشات المؤرشفة',
      _ => 'المحادثات',
    };
    final subtitle = switch (folder) {
      'locked' => 'محمية برمز PIN أو البصمة',
      'archived' => 'المحادثات التي أرشفتها',
      _ => null,
    };
    final headerIcon = switch (folder) {
      'locked' => Icons.lock_outline,
      'archived' => Icons.archive_outlined,
      _ => null,
    };

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if ((!isMobile || folder != null) && !selectionMode)
          Padding(
            key: folder == null ? null : const Key('inbox-folder-header'),
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
            child: HasimPageHeader(
              title: title,
              subtitle: subtitle,
              icon: isMobile ? null : headerIcon,
              onBack: folder != null && isMobile
                  ? () => context.go('/conversations')
                  : null,
            ),
          ),
        if (!gated)
          Padding(
            padding: EdgeInsets.fromLTRB(16, isMobile ? 8 : 4, 16, 0),
            child: HasimSearchBar(
              controller: search,
              hintText: switch (folder) {
                'archived' => 'ابحث في المؤرشفة',
                'locked' => 'ابحث في الدردشات المقفلة',
                _ => 'ابحث في المحادثات',
              },
              flat: true,
              onSubmitted: notifier.setSearch,
              onChanged: (v) {
                if (v.isEmpty) notifier.setSearch('');
              },
              onClear: () => notifier.setSearch(''),
            ),
          ),
        if (folder == null && isMobile)
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
            child: Row(
              children: [
                ConversationFilterChip(
                  label: 'الكل',
                  selected: state.filter == 'all' && state.channel == null,
                  onTap: () => notifier.setChannel(null, resetFilter: true),
                ),
                const SizedBox(width: 8),
                ConversationFilterChip(
                  key: const Key('conversation-filter-important'),
                  label: 'مهم',
                  selected: state.customerPriority == 'important',
                  onTap: () => notifier.toggleClassification(
                    customerPriority: 'important',
                  ),
                ),
                const SizedBox(width: 8),
                ConversationFilterChip(
                  label: 'مدفوع',
                  selected: state.paymentStatus == 'paid',
                  onTap: () =>
                      notifier.toggleClassification(paymentStatus: 'paid'),
                ),
                const SizedBox(width: 8),
                ConversationFilterChip(
                  label: 'غير مدفوع',
                  selected: state.paymentStatus == 'unpaid',
                  onTap: () =>
                      notifier.toggleClassification(paymentStatus: 'unpaid'),
                ),
                const SizedBox(width: 8),
                ConversationFilterChip(
                  label: 'يحتاج مراجعة',
                  selected: state.reviewStatus == 'needs_review',
                  onTap: () => notifier.toggleClassification(
                    reviewStatus: 'needs_review',
                  ),
                ),
                const SizedBox(width: 8),
                for (final c in channels) ...[
                  ConversationFilterChip(
                    label: c.$2,
                    selected: state.channel == c.$1,
                    onTap: () {
                      notifier.setChannel(
                        state.channel == c.$1 ? null : c.$1,
                        resetFilter: true,
                      );
                    },
                  ),
                  const SizedBox(width: 8),
                ],
              ],
            ),
          ),
        if (folder == null && isMobile && showSpecialInboxSections)
          Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (lockedIds.isNotEmpty)
                _SpecialInboxFolderLink(
                  key: const Key('conversation-locked-tile'),
                  icon: Icons.lock_outline,
                  label: 'الدردشات المقفلة',
                  onTap: onOpenLocked,
                ),
              _SpecialInboxFolderLink(
                key: const Key('conversation-archived-tile'),
                icon: Icons.archive_outlined,
                label: 'مؤرشفة',
                onTap: onOpenArchive,
              ),
            ],
          )
        else if (folder == null && !isMobile) ...[
          Padding(
            padding: EdgeInsets.fromLTRB(16, 14, 16, compactEmpty ? 8 : 4),
            child: compactEmpty
                ? DesktopAdaptiveConversationFilters(
                    primary: () => [
                      for (final f in filters)
                        ConversationFilterChip(
                          key: Key('conversation-filter-${f.$1}'),
                          label: f.$2,
                          selected: state.filter == f.$1,
                          onTap: () => notifier.setFilter(f.$1),
                          leading: f.$1 == 'unread' ? const _UnreadDot() : null,
                        ),
                    ],
                    extras: () => [
                      for (final c in const [
                        ('whatsapp', 'واتساب'),
                        ('email', 'بريد'),
                        ('web', 'ويب'),
                        ('facebook_messenger', 'ماسنجر'),
                        ('instagram', 'إنستغرام'),
                      ])
                        ConversationFilterChip(
                          key: Key('conversation-extra-${c.$1}'),
                          label: c.$2,
                          selected: state.channel == c.$1,
                          onTap: () => notifier.setChannel(
                            state.channel == c.$1 ? null : c.$1,
                          ),
                        ),
                    ],
                    dropdown: (promoted) {
                      const channels = [
                        ('whatsapp', 'واتساب', Icons.chat),
                        ('email', 'بريد', Icons.mail_outline),
                        ('web', 'ويب', Icons.language),
                        (
                          'facebook_messenger',
                          'ماسنجر',
                          Icons.facebook_outlined,
                        ),
                        ('instagram', 'إنستغرام', Icons.camera_alt_outlined),
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
                            leading: f.$1 == 'unread'
                                ? const _UnreadDot()
                                : null,
                          ),
                          const SizedBox(width: 8),
                        ],
                      ],
                    ),
                  ),
          ),
          if (!compactEmpty)
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.fromLTRB(16, 6, 16, 8),
              child: Row(
                children: [
                  for (final c in channels) ...[
                    ConversationChannelChip(
                      label: c.$2,
                      selected: state.channel == c.$1,
                      onSelected: (sel) =>
                          notifier.setChannel(sel ? c.$1 : null),
                    ),
                    const SizedBox(width: 8),
                  ],
                ],
              ),
            ),
        ],
        Expanded(
          child: gated
              ? LockedContentGate(
                  title: 'الدردشات المقفلة',
                  subtitle:
                      'أدخل رمز PIN أو استخدم البصمة قبل عرض هذه الدردشات.',
                  onUnlock: onOpenLocked,
                )
              : RefreshIndicator(
                  color: AppTheme.brand,
                  onRefresh: onRefresh,
                  child: state.loading && state.items.isEmpty
                      ? const SkeletonList()
                      : compactEmpty &&
                            !state.loading &&
                            state.items.isEmpty &&
                            state.error == null
                      ? ListView(
                          physics: const AlwaysScrollableScrollPhysics(),
                          children: const [SizedBox(height: 1)],
                        )
                      : AsyncBody(
                          loading: false,
                          error: state.error,
                          isEmpty: !state.loading && state.items.isEmpty,
                          emptyTitle: _folderEmptyTitle(folder),
                          emptySubtitle: emptySubtitle,
                          emptyIllustration: const InboxEmptyIllustration(),
                          emptyActionLabel: canStartConversation
                              ? 'ابدأ محادثة جديدة'
                              : null,
                          emptyActionIcon: Icons.add_rounded,
                          emptyPillAction: true,
                          onEmptyAction: canStartConversation
                              ? () => context.push('/contacts')
                              : null,
                          onRetry: onRetry,
                          child: NotificationListener<ScrollNotification>(
                            onNotification: (n) {
                              if (n.metrics.pixels >
                                  n.metrics.maxScrollExtent - 200) {
                                notifier.loadMore();
                              }
                              return false;
                            },
                            child: ListView.builder(
                              itemCount:
                                  state.items.length +
                                  (state.loadingMore ? 1 : 0),
                              itemBuilder: (context, index) {
                                if (index >= state.items.length) {
                                  return const Padding(
                                    padding: EdgeInsets.all(16),
                                    child: Center(
                                      child: CircularProgressIndicator(),
                                    ),
                                  );
                                }
                                final c = state.items[index];
                                final time = inboxListTimeAr(c.lastMessageAt);
                                final selected =
                                    (selectedId != null &&
                                        selectedId == c.id) ||
                                    actionSelectedId == c.id;
                                final unread = c.unreadCount > 0 && !selected;
                                final titleInitial = c.title.isNotEmpty
                                    ? c.title.characters.first
                                    : '#';
                                return Material(
                                  key: ValueKey('conversation-item-${c.id}'),
                                  color: selected
                                      ? HasimTokens.conversationSelected(
                                          context,
                                        )
                                      : Colors.transparent,
                                  child: Builder(
                                    builder: (rowContext) => InkWell(
                                      onTap: () => onOpen(c.id),
                                      onLongPress: isMobile
                                          ? () => onSelectForActions(c.id)
                                          : () => _showConversationActions(
                                              rowContext,
                                              conversation: c,
                                              busy:
                                                  state
                                                      .mutatingConversationId ==
                                                  c.id,
                                              l10n: l10n,
                                              locked: lockedIds.contains(c.id),
                                              showSpecialInboxSections:
                                                  showSpecialInboxSections,
                                              onArchive: () =>
                                                  notifier.setArchived(
                                                    c.id,
                                                    archived: !c.archived,
                                                  ),
                                              onMute: () => notifier.setMuted(
                                                c.id,
                                                muted: !c.muted,
                                              ),
                                              onLock: () =>
                                                  lockedIds.contains(c.id)
                                                  ? onUnlock(c.id)
                                                  : onLock(c.id),
                                            ),
                                      child: Padding(
                                        padding: const EdgeInsets.symmetric(
                                          horizontal: 16,
                                          vertical: 12,
                                        ),
                                        child: Row(
                                          children: [
                                            CircleAvatar(
                                              radius: 22,
                                              backgroundColor: HasimTokens
                                                  .chatGreen
                                                  .withValues(alpha: 0.14),
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
                                                crossAxisAlignment:
                                                    CrossAxisAlignment.start,
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
                                                                overflow:
                                                                    TextOverflow
                                                                        .ellipsis,
                                                                style: TextStyle(
                                                                  fontWeight:
                                                                      unread
                                                                      ? FontWeight
                                                                            .w800
                                                                      : FontWeight
                                                                            .w700,
                                                                  fontSize: 14,
                                                                ),
                                                              ),
                                                            ),
                                                            const SizedBox(
                                                              width: 4,
                                                            ),
                                                            ConversationChannelIcon(
                                                              channel:
                                                                  c.channel,
                                                              tooltip: c
                                                                  .channelLabel,
                                                            ),
                                                            if (c.muted)
                                                              Padding(
                                                                padding:
                                                                    const EdgeInsetsDirectional.only(
                                                                      start: 4,
                                                                    ),
                                                                child: Icon(
                                                                  Icons
                                                                      .notifications_off_outlined,
                                                                  size: 14,
                                                                  color: theme
                                                                      .colorScheme
                                                                      .onSurfaceVariant,
                                                                ),
                                                              ),
                                                          ],
                                                        ),
                                                      ),
                                                      Text(
                                                        time,
                                                        style: theme
                                                            .textTheme
                                                            .labelSmall
                                                            ?.copyWith(
                                                              color: unread
                                                                  ? theme
                                                                        .colorScheme
                                                                        .primary
                                                                  : theme
                                                                        .colorScheme
                                                                        .onSurfaceVariant,
                                                              fontWeight:
                                                                  FontWeight
                                                                      .w500,
                                                            ),
                                                      ),
                                                    ],
                                                  ),
                                                  const SizedBox(height: 4),
                                                  Wrap(
                                                    spacing: 4,
                                                    runSpacing: 4,
                                                    children: [
                                                      if (c.isHumanHandoffActive)
                                                        _ClassBadge(
                                                          label:
                                                              'تحتاج مراجعة من الفريق',
                                                          key: Key(
                                                            'team-review-${c.id}',
                                                          ),
                                                        ),
                                                      if (c.isAiReplying)
                                                        _ClassBadge(
                                                          label: 'رد AI',
                                                          key: Key(
                                                            'ai-reply-${c.id}',
                                                          ),
                                                        ),
                                                      if (c.customerPriority ==
                                                          'important')
                                                        _ClassBadge(
                                                          label: 'مهم',
                                                          key: Key(
                                                            'priority-${c.id}',
                                                          ),
                                                        ),
                                                      if (c.customerPriority ==
                                                          'not_important')
                                                        _ClassBadge(
                                                          label: 'غير مهم',
                                                          key: Key(
                                                            'priority-${c.id}',
                                                          ),
                                                        ),
                                                      if (c.paymentStatus ==
                                                          'paid')
                                                        _ClassBadge(
                                                          label: 'مدفوع',
                                                          key: Key(
                                                            'paid-${c.id}',
                                                          ),
                                                        ),
                                                      if (c.reviewStatus ==
                                                          'needs_review')
                                                        _ClassBadge(
                                                          label: 'يحتاج مراجعة',
                                                          key: Key(
                                                            'review-${c.id}',
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
                                                          overflow: TextOverflow
                                                              .ellipsis,
                                                          style: TextStyle(
                                                            fontSize: 12.5,
                                                            color: theme
                                                                .colorScheme
                                                                .onSurfaceVariant,
                                                            fontWeight: unread
                                                                ? FontWeight
                                                                      .w600
                                                                : FontWeight
                                                                      .w400,
                                                          ),
                                                        ),
                                                      ),
                                                      if (unread)
                                                        Padding(
                                                          padding:
                                                              const EdgeInsetsDirectional.only(
                                                                start: 8,
                                                              ),
                                                          child: CircleAvatar(
                                                            radius: 10,
                                                            backgroundColor:
                                                                HasimTokens
                                                                    .chatGreen,
                                                            child: Text(
                                                              '${c.unreadCount}',
                                                              style: const TextStyle(
                                                                color: Colors
                                                                    .white,
                                                                fontSize: 10,
                                                                fontWeight:
                                                                    FontWeight
                                                                        .w700,
                                                              ),
                                                            ),
                                                          ),
                                                        ),
                                                    ],
                                                  ),
                                                ],
                                              ),
                                            ),
                                            if (!isMobile) ...[
                                              if (state
                                                      .mutatingConversationId ==
                                                  c.id)
                                                const SizedBox(
                                                  key: Key(
                                                    'conversation-actions-progress',
                                                  ),
                                                  width: 20,
                                                  height: 20,
                                                  child:
                                                      CircularProgressIndicator(
                                                        strokeWidth: 2,
                                                      ),
                                                )
                                              else
                                                Icon(
                                                  key: Key(
                                                    'conversation-actions-${c.id}',
                                                  ),
                                                  Icons
                                                      .keyboard_arrow_down_rounded,
                                                  size: 18,
                                                  color: theme
                                                      .colorScheme
                                                      .onSurfaceVariant,
                                                ),
                                            ],
                                          ],
                                        ),
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

class _SpecialInboxFolderLink extends StatelessWidget {
  const _SpecialInboxFolderLink({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 28, 0),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          child: Align(
            alignment: Alignment.centerRight,
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 10),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                textDirection: TextDirection.rtl,
                children: [
                  Icon(icon, size: 20, color: HasimTokens.mutedColor(context)),
                  const SizedBox(width: 8),
                  Text(
                    label,
                    textAlign: TextAlign.end,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w600,
                      color: HasimTokens.mutedColor(context),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _ConversationSelectionAppBar extends StatelessWidget
    implements PreferredSizeWidget {
  const _ConversationSelectionAppBar({
    required this.conversation,
    required this.locked,
    required this.showSpecialInboxSections,
    required this.onClose,
    required this.onArchive,
    required this.onMute,
    required this.onLock,
  });

  final ConversationModel conversation;
  final bool locked;
  final bool showSpecialInboxSections;
  final VoidCallback onClose;
  final VoidCallback onArchive;
  final VoidCallback onMute;
  final VoidCallback onLock;

  @override
  Size get preferredSize => const Size.fromHeight(kToolbarHeight);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = conversation.muted;
    Widget menuRow(IconData icon, String label, {Color? color}) {
      return Row(
        children: [
          Icon(icon, size: 20, color: color),
          const SizedBox(width: 8),
          Flexible(
            child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis),
          ),
        ],
      );
    }

    return AppBar(
      key: const Key('conversation-selection-appbar'),
      automaticallyImplyLeading: false,
      centerTitle: false,
      backgroundColor: HasimTokens.cardBg(context),
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      leading: IconButton(
        key: const Key('conversation-selection-close'),
        tooltip: 'إلغاء التحديد',
        onPressed: onClose,
        icon: const Icon(Icons.arrow_forward_rounded),
      ),
      title: Text(
        '1',
        style: theme.textTheme.titleLarge?.copyWith(
          fontWeight: FontWeight.w700,
        ),
      ),
      actions: [
        if (showSpecialInboxSections)
          IconButton(
            key: const Key('conversation-selection-archive'),
            tooltip: conversation.archived ? 'إلغاء الأرشفة' : 'أرشفة',
            onPressed: onArchive,
            icon: Icon(
              conversation.archived
                  ? Icons.unarchive_outlined
                  : Icons.archive_outlined,
            ),
          ),
        IconButton(
          key: const Key('conversation-selection-mute'),
          tooltip: muted ? 'إلغاء الكتم' : 'كتم',
          onPressed: onMute,
          icon: Icon(
            muted
                ? Icons.notifications_active_outlined
                : Icons.notifications_off_outlined,
          ),
        ),
        if (showSpecialInboxSections)
          IconButton(
            key: const Key('conversation-selection-lock'),
            tooltip: locked ? 'إلغاء القفل' : 'قفل الدردشة',
            onPressed: onLock,
            icon: Icon(locked ? Icons.lock_open_outlined : Icons.lock_outline),
          ),
        PopupMenuButton<String>(
          key: const Key('conversation-selection-more'),
          tooltip: 'المزيد',
          padding: EdgeInsets.zero,
          position: PopupMenuPosition.under,
          offset: const Offset(0, 8),
          constraints: const BoxConstraints(minWidth: 0, maxWidth: 220),
          icon: const Icon(Icons.more_vert_rounded),
          onSelected: (value) {
            if (value == 'mute') {
              onMute();
            }
          },
          itemBuilder: (context) => [
            PopupMenuItem<String>(
              value: 'clear',
              enabled: false,
              padding: const EdgeInsets.symmetric(horizontal: 10),
              child: menuRow(
                Icons.delete_outline_rounded,
                'مسح محتوى الدردشة',
                color: theme.disabledColor,
              ),
            ),
            PopupMenuItem<String>(
              value: 'mute',
              padding: const EdgeInsets.symmetric(horizontal: 10),
              child: menuRow(
                muted
                    ? Icons.notifications_active_outlined
                    : Icons.notifications_off_outlined,
                muted ? 'إلغاء كتم الدردشة' : 'كتم الدردشة',
              ),
            ),
            PopupMenuItem<String>(
              value: 'pin',
              enabled: false,
              padding: const EdgeInsets.symmetric(horizontal: 10),
              child: menuRow(
                Icons.push_pin_outlined,
                'تثبيت الدردشة',
                color: theme.disabledColor,
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class _DesktopChatPane extends StatelessWidget {
  const _DesktopChatPane({
    required this.selectedId,
    required this.initialConversation,
    required this.listEmpty,
    required this.listError,
    required this.onRetry,
    required this.onEmptyAction,
    this.blocked = false,
    this.emptyTitle = ConversationsScreen.emptyTitle,
    this.emptySubtitle = ConversationsScreen.emptySubtitle,
    this.showEmptyAction = true,
    this.idleTitle = 'اختر محادثة لبدء المحادثة',
    this.idleSubtitle = ConversationsScreen.emptySubtitle,
  });

  final int? selectedId;
  final ConversationModel? initialConversation;
  final bool listEmpty;
  final String? listError;
  final bool blocked;
  final String emptyTitle;
  final String emptySubtitle;
  final bool showEmptyAction;
  final String idleTitle;
  final String? idleSubtitle;
  final VoidCallback onRetry;
  final VoidCallback onEmptyAction;

  @override
  Widget build(BuildContext context) {
    if (blocked) return const SizedBox.expand();

    if (selectedId != null) {
      return ChatScreen(
        key: ValueKey(selectedId),
        conversationId: selectedId!,
        initialConversation: initialConversation,
        embedded: true,
      );
    }

    final illustration = InboxEmptyIllustration(
      size: HasimLayout.isCompact(context) ? 200 : 280,
    );

    if (listError != null) {
      return EmptyState(
        title: listError!,
        actionLabel: 'إعادة المحاولة',
        onAction: onRetry,
      );
    }

    if (listEmpty) {
      return EmptyState(
        title: emptyTitle,
        subtitle: emptySubtitle,
        illustration: illustration,
        actionLabel: showEmptyAction ? 'ابدأ محادثة جديدة' : null,
        actionIcon: Icons.add_rounded,
        pillAction: true,
        onAction: showEmptyAction ? onEmptyAction : null,
      );
    }

    return EmptyState(
      title: idleTitle,
      subtitle: idleSubtitle,
      illustration: illustration,
    );
  }
}

Future<void> _showConversationActions(
  BuildContext context, {
  required ConversationModel conversation,
  required bool busy,
  required AppLocalizations l10n,
  required bool locked,
  required bool showSpecialInboxSections,
  required Future<String?> Function() onArchive,
  required Future<String?> Function() onMute,
  required VoidCallback onLock,
}) async {
  if (busy) {
    return;
  }

  final renderObject = context.findRenderObject();
  final item = renderObject is RenderBox ? renderObject : null;
  final overlay = Overlay.of(context).context.findRenderObject() as RenderBox?;
  final position = item == null || overlay == null
      ? const RelativeRect.fromLTRB(8, 56, 8, 0)
      : RelativeRect.fromRect(
          Rect.fromLTWH(
            item.localToGlobal(Offset.zero, ancestor: overlay).dx,
            item.localToGlobal(Offset.zero, ancestor: overlay).dy +
                item.size.height,
            item.size.width,
            0,
          ),
          Offset.zero & overlay.size,
        );

  final selected = await showMenu<String>(
    context: context,
    position: position,
    color: HasimTokens.cardBg(context),
    shape: RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(16),
      side: BorderSide(color: HasimTokens.lineColor(context)),
    ),
    items: [
      if (showSpecialInboxSections)
        PopupMenuItem(
          value: 'archive',
          child: Text(
            conversation.archived
                ? l10n.unarchiveConversation
                : l10n.archiveConversation,
          ),
        ),
      PopupMenuItem(
        value: 'mute',
        child: Text(
          conversation.muted ? l10n.unmuteConversation : l10n.muteConversation,
        ),
      ),
      if (showSpecialInboxSections)
        PopupMenuItem(
          value: 'lock',
          child: Text(locked ? 'إلغاء القفل' : 'قفل الدردشة'),
        ),
    ],
  );

  if (!context.mounted || selected == null) {
    return;
  }
  if (selected == 'lock') {
    onLock();
    return;
  }

  final error = selected == 'archive' ? await onArchive() : await onMute();
  if (!context.mounted || error == null) {
    return;
  }

  final fallback = selected == 'archive'
      ? (conversation.archived ? l10n.unarchiveFailed : l10n.archiveFailed)
      : (conversation.muted ? l10n.unmuteFailed : l10n.muteFailed);
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(fallback)));
}

class _ClassBadge extends StatelessWidget {
  const _ClassBadge({super.key, required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    final dark = HasimTokens.isDark(context);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
      decoration: BoxDecoration(
        color: HasimTokens.mintFill(context),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        label,
        style: TextStyle(
          fontSize: 10,
          fontWeight: FontWeight.w700,
          color: dark ? Colors.white : HasimTokens.accentDark,
        ),
      ),
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
