import 'dart:io';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/async_body.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:file_picker/file_picker.dart';
import 'package:hasim/features/conversations/data/chat_attachment_policy.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_attachment_menu.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_composer.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_emoji_picker.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_message_bubble.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_wallpaper.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_channel_avatar.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_selection.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:image_picker/image_picker.dart';

class ChatScreen extends ConsumerStatefulWidget {
  const ChatScreen({
    super.key,
    required this.conversationId,
    this.embedded = false,
  });
  final int conversationId;

  /// داخل تخطيط سطح المكتب بجانب القائمة — بدون زر رجوع يغطي القائمة الظاهرة.
  final bool embedded;

  @override
  ConsumerState<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends ConsumerState<ChatScreen> {
  final _controller = TextEditingController();
  final _composerFocus = FocusNode();
  final _scroll = ScrollController();
  final _emojiButtonKey = GlobalKey();
  final _attachButtonKey = GlobalKey();
  bool _openedAtBottom = false;
  bool _messagesVisible = true;
  bool _stickToBottom = true;
  bool _emojiPickerOpen = false;
  bool _attachMenuOpen = false;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
  }

  @override
  void didUpdateWidget(covariant ChatScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.conversationId != widget.conversationId) {
      _openedAtBottom = false;
      _messagesVisible = false;
      _stickToBottom = true;
      _emojiPickerOpen = false;
      _attachMenuOpen = false;
    }
  }

  void _onScroll() {
    if (!_scroll.hasClients) return;
    final pos = _scroll.position;
    if (pos.pixels <= 48) {
      ref.read(chatControllerProvider(widget.conversationId).notifier).loadOlder();
    }
    final atBottom = pos.pixels >= pos.maxScrollExtent - 96;
    if (atBottom != _stickToBottom) {
      setState(() => _stickToBottom = atBottom);
    }
  }

  void _onScrollToBottomTap() {
    _scrollToLatest(frames: 2);
    setState(() => _stickToBottom = true);
  }

  @override
  void dispose() {
    _scroll.removeListener(_onScroll);
    _controller.dispose();
    _composerFocus.dispose();
    _scroll.dispose();
    super.dispose();
  }

  void _scrollToLatest({int frames = 1, bool reveal = false}) {
    void tick(int remaining) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        if (_scroll.hasClients) {
          _scroll.jumpTo(_scroll.position.maxScrollExtent);
        }
        if (remaining > 0) {
          tick(remaining - 1);
        } else if (reveal) {
          setState(() {
            _messagesVisible = true;
            _stickToBottom = true;
          });
        }
      });
    }

    tick(frames);
  }

  void _keepComposerFocus() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _composerFocus.requestFocus();
    });
  }

  Future<void> _send() async {
    final text = _controller.text;
    if (text.trim().isEmpty) return;
    _controller.clear();
    _keepComposerFocus();
    _stickToBottom = true;
    await ref.read(chatControllerProvider(widget.conversationId).notifier).send(text);
    if (!mounted) return;
    _keepComposerFocus();
  }

  bool _useDesktopChatPopovers(BuildContext context) => !HasimLayout.isCompact(context);

  void _onAttachPressed() {
    if (_useDesktopChatPopovers(context)) {
      _toggleAttachMenu();
    } else {
      _showAttachSheetMobile();
    }
  }

  void _toggleAttachMenu() {
    if (!mounted) return;
    setState(() {
      _attachMenuOpen = !_attachMenuOpen;
      if (_attachMenuOpen) _emojiPickerOpen = false;
    });
  }

  Future<void> _showAttachSheetMobile() async {
    final l10n = AppLocalizations.of(context);
    final action = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: Text(l10n.chatAttachmentPhoto),
              onTap: () => Navigator.pop(ctx, 'gallery'),
            ),
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: Text(l10n.chatAttachmentCamera),
              onTap: () => Navigator.pop(ctx, 'camera'),
            ),
            ListTile(
              leading: const Icon(Icons.videocam_outlined),
              title: Text(l10n.chatAttachmentVideo),
              onTap: () => Navigator.pop(ctx, 'video'),
            ),
            ListTile(
              leading: const Icon(Icons.audiotrack_outlined),
              title: Text(l10n.chatAttachmentAudio),
              onTap: () => Navigator.pop(ctx, 'audio'),
            ),
            ListTile(
              leading: const Icon(Icons.insert_drive_file_outlined),
              title: Text(l10n.chatAttachmentFile),
              onTap: () => Navigator.pop(ctx, 'file'),
            ),
          ],
        ),
      ),
    );
    if (!mounted || action == null) return;
    await _pickAttachment(action);
  }

  Future<void> _onAttachMenuSelected(String action) async {
    setState(() => _attachMenuOpen = false);
    const comingSoon = {'contact', 'poll', 'event', 'sticker', 'catalog', 'quick_replies'};
    if (comingSoon.contains(action)) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('قريباً')),
      );
      return;
    }
    final resolved = switch (action) {
      'document' => 'file',
      'photos_videos' => 'photos_videos',
      _ => action,
    };
    await _pickAttachment(resolved);
  }

  double _buttonCenterX(GlobalKey key) {
    final ctx = key.currentContext;
    if (ctx == null) return 0;
    final box = ctx.findRenderObject() as RenderBox?;
    if (box == null || !box.hasSize) return 0;
    final anchor = box.localToGlobal(Offset.zero);
    return anchor.dx + box.size.width / 2;
  }

  double _anchorMenuLeft(GlobalKey key, double panelWidth) {
    final ctx = key.currentContext;
    if (ctx == null) return 12;
    final box = ctx.findRenderObject() as RenderBox?;
    if (box == null || !box.hasSize) return 12;
    final anchor = box.localToGlobal(Offset.zero);
    final screenW = MediaQuery.sizeOf(context).width;
    final left = anchor.dx + box.size.width - panelWidth;
    final maxLeft = screenW - panelWidth - 12;
    return left.clamp(12.0, maxLeft);
  }

  double _attachMenuArrowOffset(double panelLeft) {
    final center = _buttonCenterX(_attachButtonKey);
    final raw = center - panelLeft - ChatAttachmentMenuPanel.arrowHalfWidth;
    return raw.clamp(20.0, ChatAttachmentMenuPanel.panelWidth - 28);
  }

  Future<void> _pickAttachment(String action) async {
    final l10n = AppLocalizations.of(context);
    File? file;
    switch (action) {
      case 'photos_videos':
        final media = await ImagePicker().pickMedia(imageQuality: 85);
        if (media != null) file = File(media.path);
      case 'gallery':
        final picked = await ImagePicker().pickImage(source: ImageSource.gallery, imageQuality: 85);
        if (picked != null) file = File(picked.path);
      case 'camera':
        final picked = await ImagePicker().pickImage(source: ImageSource.camera, imageQuality: 85);
        if (picked != null) file = File(picked.path);
      case 'video':
        final result = await FilePicker.platform.pickFiles(
          type: FileType.custom,
          allowedExtensions: const ['mp4', 'webm'],
        );
        final path = result?.files.single.path;
        if (path != null) file = File(path);
      case 'audio':
        final result = await FilePicker.platform.pickFiles(
          type: FileType.custom,
          allowedExtensions: const ['mp3', 'mpeg', 'wav', 'webm'],
        );
        final path = result?.files.single.path;
        if (path != null) file = File(path);
      case 'file':
        final result = await FilePicker.platform.pickFiles(
          type: FileType.custom,
          allowedExtensions: ChatAttachmentPolicy.allowedExtensions.toList()..sort(),
        );
        final path = result?.files.single.path;
        if (path != null) file = File(path);
    }
    if (file == null || !mounted) return;
    _stickToBottom = true;
    final err = await ref.read(chatControllerProvider(widget.conversationId).notifier).sendAttachment(file);
    if (!mounted) return;
    if (err != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(_attachmentErrorMessage(l10n, err))),
      );
    }
    _keepComposerFocus();
  }

  String _attachmentErrorMessage(AppLocalizations l10n, String code) {
    return switch (code) {
      'unsupported' => l10n.chatAttachmentUnsupported,
      'tooLarge' => l10n.chatAttachmentTooLarge,
      'offline' => l10n.chatAttachmentOffline,
      'missing' => l10n.chatAttachmentMissing,
      'uploadFailed' => l10n.chatAttachmentUploadFailed,
      _ => l10n.chatAttachmentUploadFailed,
    };
  }

  void _refreshMessages() {
    ref.read(chatControllerProvider(widget.conversationId).notifier).refresh();
  }

  void _onEmojiPressed() {
    if (_useDesktopChatPopovers(context)) {
      _toggleEmojiPicker();
    } else {
      _showEmojiSheetMobile();
    }
  }

  void _toggleEmojiPicker() {
    if (!mounted) return;
    setState(() {
      _emojiPickerOpen = !_emojiPickerOpen;
      if (_emojiPickerOpen) _attachMenuOpen = false;
    });
  }

  Future<void> _showEmojiSheetMobile() async {
    final picked = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) {
        final height = (MediaQuery.sizeOf(ctx).height * 0.46).clamp(320.0, 420.0);
        return SafeArea(
          child: SizedBox(
            height: height,
            child: ChatEmojiPickerPanel(
              onEmojiSelected: (emoji) => Navigator.pop(ctx, emoji),
            ),
          ),
        );
      },
    );
    if (picked == null || !mounted) return;
    _insertEmojiCharacter(picked);
  }

  void _insertEmojiCharacter(String emoji) {
    final value = _controller.text;
    final selection = _controller.selection;
    final start = selection.isValid ? selection.start : value.length;
    final end = selection.isValid ? selection.end : value.length;
    final next = value.replaceRange(start, end, emoji);
    _controller.value = TextEditingValue(
      text: next,
      selection: TextSelection.collapsed(offset: start + emoji.length),
    );
    _keepComposerFocus();
  }

  double _emojiPickerLeft(double panelWidth) => _anchorMenuLeft(_emojiButtonKey, panelWidth);

  Future<void> _suggest() async {
    final text = await ref.read(chatControllerProvider(widget.conversationId).notifier).suggestReply();
    if (!mounted || text == null) return;
    _controller.text = text;
    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم إدراج اقتراح الرد.')));
  }

  Future<void> _summarize() async {
    final text = await ref.read(chatControllerProvider(widget.conversationId).notifier).summarize();
    if (!mounted || text == null) return;
    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('ملخص المحادثة'),
        content: SingleChildScrollView(child: Text(text, style: const TextStyle(height: 1.45))),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('إغلاق')),
        ],
      ),
    );
  }

  Future<void> _quickAddContact() async {
    final customer = ref.read(chatControllerProvider(widget.conversationId)).conversation?.customer;
    final email = customer?.email?.trim();
    if (email == null || email.isEmpty) return;
    try {
      final existing = await ref.read(contactRepositoryProvider).findByEmail(email);
      if (!mounted) return;
      if (existing.isNotEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: const Text('جهة الاتصال موجودة مسبقاً'),
            action: SnackBarAction(
              label: 'عرض',
              onPressed: () => context.push('/contacts/${existing.first.id}'),
            ),
          ),
        );
        return;
      }
      final created = await ref.read(contactRepositoryProvider).create(
            name: customer?.name ?? email,
            email: email,
            phone: customer?.phone,
          );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: const Text('تمت الإضافة إلى جهات الاتصال'),
          action: SnackBarAction(
            label: 'عرض',
            onPressed: () => context.push('/contacts/${created.id}'),
          ),
        ),
      );
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تعذر الإضافة')));
      }
    }
  }

  void _openImage(String url) {
    showDialog<void>(
      context: context,
      builder: (context) => Dialog(
        insetPadding: const EdgeInsets.all(16),
        child: InteractiveViewer(
          child: CachedNetworkImage(imageUrl: url, fit: BoxFit.contain),
        ),
      ),
    );
  }

  List<_ChatRow> _rows(List<MessageModel> messages) {
    final rows = <_ChatRow>[];
    DateTime? lastDay;
    for (final message in messages) {
      final created = message.createdAt?.toLocal();
      if (created != null) {
        final day = DateTime(created.year, created.month, created.day);
        if (lastDay == null || day != lastDay) {
          rows.add(_ChatRow.date(day));
          lastDay = day;
        }
      }
      rows.add(_ChatRow.message(message));
    }
    return rows;
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(chatControllerProvider(widget.conversationId), (previous, next) {
      if (previous?.sending == true && !next.sending) {
        _keepComposerFocus();
      }
      if (next.messages.isEmpty) return;

      final prevLen = previous?.messages.length ?? 0;
      final grew = next.messages.length > prevLen;
      if (grew && _stickToBottom) {
        _scrollToLatest(frames: 1);
      }

      if (!_openedAtBottom && next.messages.isNotEmpty) {
        _openedAtBottom = true;
        _messagesVisible = false;
        _scrollToLatest(frames: 4, reveal: true);
      }
    });

    final state = ref.watch(chatControllerProvider(widget.conversationId));
    final l10n = AppLocalizations.of(context);
    final title = state.conversation?.title ?? 'محادثة';
    final customerId = state.conversation?.customer?.id;
    final rows = _rows(state.messages);
    final conversation = state.conversation;
    final initial = title.isNotEmpty ? title.characters.first : '#';
    final headerBg = HasimTokens.cardBg(context);

    Widget titleBlock({required bool tappable}) {
      final row = Row(
        children: [
          CircleAvatar(
            radius: 18,
            backgroundColor: HasimTokens.chatGreen.withValues(alpha: 0.15),
            child: Text(
              initial,
              style: const TextStyle(
                color: HasimTokens.chatGreen,
                fontWeight: FontWeight.w800,
                fontSize: 14,
              ),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Row(
              children: [
                Flexible(
                  child: Text(
                    title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                  ),
                ),
                if (conversation != null) ...[
                  const SizedBox(width: 6),
                  ConversationChannelIcon(
                    channel: conversation.channel,
                    size: 18,
                    tooltip: conversation.channelLabel,
                  ),
                ],
              ],
            ),
          ),
        ],
      );
      if (!tappable) return row;
      return InkWell(
        key: const Key('desktop-customer-header'),
        onTap: () => ref.read(desktopCustomerDrawerOpenProvider.notifier).state = true,
        borderRadius: BorderRadius.circular(8),
        child: row,
      );
    }

    return Scaffold(
      backgroundColor: HasimTokens.chatCanvas(context),
      appBar: AppBar(
        automaticallyImplyLeading: !widget.embedded,
        centerTitle: false,
        backgroundColor: headerBg,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        titleSpacing: widget.embedded ? 16 : 0,
        shape: Border(bottom: BorderSide(color: HasimTokens.lineColor(context))),
        title: titleBlock(tappable: widget.embedded),
        actions: [
          if (widget.embedded)
            IconButton(
              key: const Key('desktop-customer-info'),
              tooltip: 'معلومات العميل',
              onPressed: () => ref.read(desktopCustomerDrawerOpenProvider.notifier).state = true,
              icon: Icon(Icons.info_outline_rounded, color: HasimTokens.mutedColor(context)),
            ),
          PopupMenuButton<String>(
            enabled: !state.mutating,
            icon: Icon(Icons.more_horiz_rounded, color: HasimTokens.mutedColor(context)),
            onSelected: (v) async {
              if (v == 'suggest') _suggest();
              if (v == 'summarize') _summarize();
              if (v == 'add_contact') _quickAddContact();
              if (v == 'customer' && customerId != null) {
                if (widget.embedded) {
                  ref.read(desktopCustomerDrawerOpenProvider.notifier).state = true;
                } else {
                  context.push('/customers/$customerId');
                }
              }
              if (v == 'themes') {
                context.push('/chat-themes');
              }
              if (v == 'archive' || v == 'mute') {
                final current = ref.read(chatControllerProvider(widget.conversationId)).conversation;
                if (current == null) return;
                final notifier = ref.read(chatControllerProvider(widget.conversationId).notifier);
                final error = v == 'archive'
                    ? await notifier.setArchived(archived: !current.archived)
                    : await notifier.setMuted(muted: !current.muted);
                if (error == null) return;
                if (!context.mounted) return;
                final fallback = v == 'archive'
                    ? (current.archived ? l10n.unarchiveFailed : l10n.archiveFailed)
                    : (current.muted ? l10n.unmuteFailed : l10n.muteFailed);
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(fallback)));
              }
            },
            itemBuilder: (_) => [
              PopupMenuItem(
                key: const Key('chat-action-themes'),
                value: 'themes',
                child: Text(l10n.chatThemes),
              ),
              const PopupMenuItem(value: 'suggest', child: Text('اقتراح رد')),
              const PopupMenuItem(value: 'summarize', child: Text('تلخيص')),
              if (conversation != null)
                PopupMenuItem(
                  key: const Key('chat-action-archive'),
                  value: 'archive',
                  child: Text(
                    conversation.archived ? l10n.unarchiveConversation : l10n.archiveConversation,
                  ),
                ),
              if (conversation != null)
                PopupMenuItem(
                  key: const Key('chat-action-mute'),
                  value: 'mute',
                  child: Text(
                    conversation.muted ? l10n.unmuteConversation : l10n.muteConversation,
                  ),
                ),
              if (customerId != null) const PopupMenuItem(value: 'customer', child: Text('ملف العميل')),
              if (state.conversation?.customer?.email != null &&
                  state.conversation!.customer!.email!.trim().isNotEmpty)
                const PopupMenuItem(value: 'add_contact', child: Text('إضافة لجهات الاتصال')),
            ],
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: Stack(
        children: [
          const Positioned.fill(child: ChatWallpaper()),
          Column(
        children: [
          if (state.loadingOlder) const LinearProgressIndicator(minHeight: 2),
          if (state.aiBusy || state.mutating) const LinearProgressIndicator(minHeight: 2),
          Expanded(
            child: state.loading && state.messages.isEmpty
                ? const SkeletonList()
                : AsyncBody(
                    loading: false,
                    error: state.error != null && state.messages.isEmpty ? state.error : null,
                    isEmpty: !state.loading && state.messages.isEmpty,
                    emptyTitle: 'ابدأ المحادثة',
                    emptySubtitle: 'لا توجد رسائل بعد.',
                    onRetry: () => ref.read(chatControllerProvider(widget.conversationId).notifier).refresh(),
                    child: LayoutBuilder(
                      builder: (context, constraints) {
                        final compact = HasimLayout.isCompact(context);
                        final bubbleCap = constraints.maxWidth * (compact ? 0.78 : 0.72);
                        final composerPad = ChatComposer.floatingOuterHeight + 8;
                        final list = ListView.builder(
                          controller: _scroll,
                          padding: EdgeInsets.fromLTRB(
                            compact ? 10 : 16,
                            8,
                            compact ? 10 : 16,
                            composerPad,
                          ),
                          itemCount: rows.length,
                          itemBuilder: (context, index) {
                            final row = rows[index];
                            if (row.date != null) {
                              return ChatDateChip(date: row.date!);
                            }
                            final m = row.message!;
                            return SizedBox(
                              width: double.infinity,
                              child: ChatMessageBubble(
                                message: m,
                                maxWidth: bubbleCap,
                                onRetry: m.localFailed
                                    ? () => ref
                                        .read(chatControllerProvider(widget.conversationId).notifier)
                                        .retryFailed(m)
                                    : null,
                                onImageTap: _openImage,
                                onMediaUrlExpired: _refreshMessages,
                                onOpenFailed: () {
                                  if (!mounted) return;
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    SnackBar(content: Text(l10n.chatAttachmentOpenFailed)),
                                  );
                                },
                              ),
                            );
                          },
                        );
                        if (rows.isEmpty) return list;
                        return Opacity(
                          opacity: _messagesVisible ? 1 : 0,
                          child: list,
                        );
                      },
                    ),
                  ),
          ),
        ],
        ),
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: ChatComposer(
              controller: _controller,
              focusNode: _composerFocus,
              emojiButtonKey: _emojiButtonKey,
              attachButtonKey: _attachButtonKey,
              sendEnabled: !state.sending,
              onSend: _send,
              onAttach: _onAttachPressed,
              onEmoji: _onEmojiPressed,
            ),
          ),
          if (!_stickToBottom && rows.isNotEmpty && !(state.loading && state.messages.isEmpty))
            PositionedDirectional(
              key: const Key('chat-scroll-to-bottom'),
              end: HasimLayout.isCompact(context) ? 12 : 20,
              bottom: ChatComposer.floatingOuterHeight + 10,
              child: Material(
                elevation: 2,
                shadowColor: Colors.black26,
                color: HasimTokens.cardBg(context),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(24),
                  side: BorderSide(color: HasimTokens.lineColor(context)),
                ),
                child: InkWell(
                  onTap: _onScrollToBottomTap,
                  borderRadius: BorderRadius.circular(24),
                  child: const Padding(
                    padding: EdgeInsets.all(8),
                    child: Icon(
                      Icons.keyboard_arrow_down_rounded,
                      size: 26,
                      color: HasimTokens.chatGreen,
                    ),
                  ),
                ),
              ),
            ),
          if (_useDesktopChatPopovers(context) && _attachMenuOpen)
            Builder(
              builder: (context) {
                final left = _anchorMenuLeft(_attachButtonKey, ChatAttachmentMenuPanel.panelWidth);
                final arrowOffset = _attachMenuArrowOffset(left);
                return Positioned(
                  left: left,
                  bottom: ChatComposer.floatingOuterHeight + 4,
                  child: ChatAttachmentMenuPanel(
                    arrowOffset: arrowOffset,
                    onSelected: _onAttachMenuSelected,
                  ),
                );
              },
            ),
          if (_useDesktopChatPopovers(context) && _emojiPickerOpen)
            Builder(
              builder: (context) {
                final screenW = MediaQuery.sizeOf(context).width;
                final panelWidth = (screenW - 24).clamp(280.0, ChatEmojiPickerPanel.panelMaxWidth);
                final left = _emojiPickerLeft(panelWidth);
                return Positioned(
                  left: left,
                  width: panelWidth,
                  bottom: ChatComposer.floatingOuterHeight + 8,
                  height: ChatEmojiPickerPanel.panelHeight,
                  child: ChatEmojiPickerPanel(onEmojiSelected: _insertEmojiCharacter),
                );
              },
            ),
        ],
      ),
    );
  }
}

class _ChatRow {
  const _ChatRow._({this.message, this.date});

  factory _ChatRow.message(MessageModel message) => _ChatRow._(message: message);

  factory _ChatRow.date(DateTime date) => _ChatRow._(date: date);

  final MessageModel? message;
  final DateTime? date;
}
