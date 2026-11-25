import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/network/media_auth_headers.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/utils/quoted_reply.dart';
import 'package:hasim/core/widgets/async_body.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:file_picker/file_picker.dart';
import 'package:hasim/features/conversations/data/chat_attachment_policy.dart';
import 'package:hasim/features/conversations/data/chat_voice_recorder.dart';
import 'package:hasim/features/conversations/data/conversation_classification.dart';
import 'package:hasim/features/conversations/data/conversation_handoff.dart';
import 'package:hasim/features/conversations/presentation/ai_suggestion_apply.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_attachment_grid.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_attachment_menu.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_composer.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_emoji_picker.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_media_preview_dialog.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_media_viewer_dialog.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_message_bubble.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_wallpaper.dart';
import 'package:hasim/features/conversations/providers/chat_bubble_color_controller.dart';
import 'package:hasim/features/conversations/providers/conversation_theme_controller.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_channel_avatar.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_classification_sheet.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_context_menu.dart';
import 'package:hasim/features/conversations/providers/conversation_marks_controller.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_selection.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/privacy/presentation/locked_chats_navigation.dart';
import 'package:hasim/features/privacy/presentation/pin_prompt.dart';
import 'package:hasim/features/privacy/providers/chat_lock_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:hasim/realtime/reverb_websocket_client.dart';
import 'package:image_picker/image_picker.dart';

class ChatScreen extends ConsumerStatefulWidget {
  const ChatScreen({
    super.key,
    required this.conversationId,
    this.embedded = false,
    this.initialConversation,
  });
  final int conversationId;

  /// داخل تخطيط سطح المكتب بجانب القائمة — بدون زر رجوع يغطي القائمة الظاهرة.
  final bool embedded;
  final ConversationModel? initialConversation;

  @override
  ConsumerState<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends ConsumerState<ChatScreen> {
  final _controller = TextEditingController();
  final _composerFocus = FocusNode();
  final _scroll = ScrollController();
  final _emojiButtonKey = GlobalKey();
  final _attachButtonKey = GlobalKey();
  final _chatMenuKey = GlobalKey();
  bool _openedAtBottom = false;
  final ValueNotifier<bool> _stickToBottomNotifier = ValueNotifier<bool>(true);
  final ValueNotifier<int> _unseenBelowNotifier = ValueNotifier<int>(0);
  final ValueNotifier<String> _searchQueryNotifier = ValueNotifier<String>('');
  bool _emojiPickerOpen = false;
  bool _attachMenuOpen = false;
  bool _findingMessages = false;
  bool _recordingVoice = false;
  bool _voicePaused = false;
  double _voiceAmplitude = 0.35;
  Duration _voiceElapsed = Duration.zero;
  Timer? _voiceTick;
  StreamSubscription<double>? _voiceAmplitudeSub;
  ChatVoiceRecorder? _voiceRecorder;
  final _messageSearch = TextEditingController();
  int _suggestGeneration = 0;

  /// الرسالة التي يُرد عليها من قائمة «رد».
  MessageModel? _replyTo;

  /// مفاتيح الرسائل المحددة بعد اختيار «تحديد».
  final Set<String> _selectedMessageKeys = <String>{};

  late final RealtimeService _realtime;
  late ChatController _chatController;

  @override
  void initState() {
    super.initState();
    _realtime = ref.read(realtimeServiceProvider);
    _chatController = ref.read(
      chatControllerProvider(widget.conversationId).notifier,
    );
    _scroll.addListener(_onScroll);
    _controller.addListener(_onComposerTextChanged);
    final initial = widget.initialConversation;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      if (initial != null) {
        _chatController.seedConversation(initial);
      }
      _chatController.onActivated();
    });
  }

  DateTime? _lastTypingSent;
  void _onComposerTextChanged() {
    final text = _controller.text.trim();
    if (text.isEmpty) return;
    final now = DateTime.now();
    if (_lastTypingSent != null &&
        now.difference(_lastTypingSent!).inMilliseconds < 2500) {
      return;
    }
    _lastTypingSent = now;
    ref
        .read(chatControllerProvider(widget.conversationId).notifier)
        .sendTyping(true);
  }

  @override
  void didUpdateWidget(covariant ChatScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.conversationId != widget.conversationId) {
      _chatController.onDeactivated();
      _chatController = ref.read(
        chatControllerProvider(widget.conversationId).notifier,
      );
      _suggestGeneration++;
      _openedAtBottom = false;
      _stickToBottomNotifier.value = true;
      _unseenBelowNotifier.value = 0;
      _emojiPickerOpen = false;
      _attachMenuOpen = false;
      _replyTo = null;
      _selectedMessageKeys.clear();
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        _chatController.onActivated();
      });
    }
  }

  void _onScroll() {
    if (!_scroll.hasClients) return;
    final pos = _scroll.position;
    if (pos.maxScrollExtent > 80 && pos.pixels >= pos.maxScrollExtent - 48) {
      ref
          .read(chatControllerProvider(widget.conversationId).notifier)
          .loadOlder();
    }
    final atLatest = pos.pixels <= 96;
    if (atLatest != _stickToBottomNotifier.value) {
      _stickToBottomNotifier.value = atLatest;
    }
    if (atLatest && _unseenBelowNotifier.value > 0) {
      _unseenBelowNotifier.value = 0;
    }
  }

  void _onScrollToBottomTap() {
    _scrollToLatest(reveal: true);
  }

  @override
  void dispose() {
    if (_chatController.mounted) {
      _chatController.onDeactivated();
    } else {
      _realtime.unwatchConversation(widget.conversationId);
    }
    _voiceTick?.cancel();
    _voiceAmplitudeSub?.cancel();
    unawaited(_voiceRecorder?.cancel());
    unawaited(_voiceRecorder?.dispose());
    _scroll.removeListener(_onScroll);
    _controller.removeListener(_onComposerTextChanged);
    _stickToBottomNotifier.dispose();
    _unseenBelowNotifier.dispose();
    _searchQueryNotifier.dispose();
    _controller.dispose();
    _messageSearch.dispose();
    _composerFocus.dispose();
    _scroll.dispose();
    super.dispose();
  }

  void _scrollToLatest({bool reveal = false}) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      if (_scroll.hasClients && _scroll.offset > 0) {
        _scroll.jumpTo(0);
      }
      if (reveal && !_stickToBottomNotifier.value) {
        _stickToBottomNotifier.value = true;
      }
      if (_unseenBelowNotifier.value > 0) {
        _unseenBelowNotifier.value = 0;
      }
    });
  }

  void _keepComposerFocus() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _composerFocus.requestFocus();
    });
  }

  bool _sendingComposer = false;

  Future<void> _send() async {
    if (_sendingComposer) return;
    final currentConversation =
        ref.read(chatControllerProvider(widget.conversationId)).conversation ??
        widget.initialConversation;
    if (currentConversation?.canReply == false) {
      return;
    }
    final text = _controller.text;
    if (text.trim().isEmpty) return;
    _sendingComposer = true;
    try {
      unawaited(
        ref
            .read(chatControllerProvider(widget.conversationId).notifier)
            .sendTyping(false),
      );
      final reply = _replyTo;
      final outgoing = reply == null
          ? text
          : composeQuotedReply(quoted: _messageText(reply), reply: text);
      _controller.clear();
      if (reply != null) {
        setState(() => _replyTo = null);
      }
      _keepComposerFocus();
      _stickToBottomNotifier.value = true;
      _scrollToLatest();
      final accepted = await ref
          .read(chatControllerProvider(widget.conversationId).notifier)
          .send(outgoing);
      if (!mounted) return;
      if (!accepted) {
        if (_controller.text.isEmpty) {
          _controller.value = TextEditingValue(
            text: text,
            selection: TextSelection.collapsed(offset: text.length),
          );
        }
        if (reply != null && _replyTo == null) {
          setState(() => _replyTo = reply);
        }
      }
      _keepComposerFocus();
    } finally {
      _sendingComposer = false;
    }
  }

  double get _composerHeight =>
      ChatComposer.floatingOuterHeight +
      (_replyTo != null ? ChatComposer.replyBarHeight : 0);

  ConversationModel? get _currentConversation =>
      ref.read(chatControllerProvider(widget.conversationId)).conversation ??
      widget.initialConversation;

  /// نص الرسالة للنسخ/الرد/الملاحظة — أو اسم المرفق إن لم يوجد نص.
  String _messageText(MessageModel message) {
    final text = message.content.trim();
    if (text.isNotEmpty && text != '📎') {
      return text;
    }
    for (final attachment in message.attachments) {
      final name = attachment.originalName.trim();
      if (name.isNotEmpty) {
        return name;
      }
    }
    return '';
  }

  String _replyTitle(MessageModel message) {
    if (message.isOutbound) {
      final sender = message.userName?.trim() ?? '';
      if (sender.isNotEmpty) return 'الرد على $sender';
      return message.aiGenerated ? 'الرد على رد AI' : 'الرد على رسالة مرسلة';
    }
    final name = message.customerName?.trim().isNotEmpty == true
        ? message.customerName!.trim()
        : _currentConversation?.title;
    return name == null || name.isEmpty ? 'الرد على العميل' : 'الرد على $name';
  }

  List<MessageModel> _selectedMessages() {
    final messages = ref
        .read(chatControllerProvider(widget.conversationId))
        .messages;
    return [
      for (final message in messages)
        if (_selectedMessageKeys.contains(
          ConversationMarks.messageKey(message),
        ))
          message,
    ];
  }

  void _toggleMessageSelection(MessageModel message) {
    final key = ConversationMarks.messageKey(message);
    setState(() {
      if (!_selectedMessageKeys.remove(key)) {
        _selectedMessageKeys.add(key);
      }
    });
  }

  void _clearMessageSelection() {
    if (_selectedMessageKeys.isEmpty) return;
    setState(_selectedMessageKeys.clear);
  }

  Future<void> _showMessageMenu(
    MessageModel message,
    Offset globalPosition,
  ) async {
    if (_selectedMessageKeys.isNotEmpty) {
      _toggleMessageSelection(message);
      return;
    }
    final marks = ref.read(conversationMarksProvider);
    final action = await showConversationContextMenu(
      context: context,
      globalPosition: globalPosition,
      pinned: marks.isMessagePinned(message),
      starred: marks.isMessageStarred(message),
      preview: splitQuotedReply(_messageText(message)).body,
    );
    if (action == null || !mounted) return;
    await _onMessageAction(action, message);
  }

  Future<void> _onMessageAction(
    ConversationContextAction action,
    MessageModel message,
  ) async {
    final marks = ref.read(conversationMarksProvider.notifier);
    switch (action) {
      case ConversationContextAction.reply:
        if (_currentConversation?.canReply == false) {
          showConversationContextToast(
            context,
            'لا يمكن الرد في هذه المحادثة.',
          );
          return;
        }
        setState(() => _replyTo = message);
        _composerFocus.requestFocus();
      case ConversationContextAction.copy:
        await copyConversationContextText(
          context,
          _messageText(message),
          copiedMessage: 'تم نسخ الرسالة.',
        );
      case ConversationContextAction.pin:
        final pin = !ref
            .read(conversationMarksProvider)
            .isMessagePinned(message);
        await marks.toggleMessagePinned(message);
        if (!mounted) return;
        showConversationContextToast(
          context,
          pin ? 'تم تثبيت الرسالة.' : 'تم إلغاء تثبيت الرسالة.',
        );
      case ConversationContextAction.star:
        final star = !ref
            .read(conversationMarksProvider)
            .isMessageStarred(message);
        await marks.toggleMessageStarred(message);
        if (!mounted) return;
        showConversationContextToast(
          context,
          star ? 'تم تمييز الرسالة بنجمة.' : 'تمت إزالة النجمة.',
        );
      case ConversationContextAction.addToNote:
        final conversation = _currentConversation;
        if (conversation == null) return;
        await addConversationTextToNote(
          context: context,
          ref: ref,
          conversation: conversation,
          text: splitQuotedReply(_messageText(message)).body,
          onView: _openCustomerFile,
        );
      case ConversationContextAction.select:
        _toggleMessageSelection(message);
      case ConversationContextAction.report:
        await _reportConversation();
      case ConversationContextAction.delete:
        await _deleteMessages([message]);
    }
  }

  /// الإبلاغ يعلّم المحادثة «يحتاج مراجعة» عبر واجهة التصنيف الموجودة.
  Future<void> _reportConversation() async {
    final conversation = _currentConversation;
    if (conversation == null) return;
    if (!ref.read(authControllerProvider).isCompanyUser) {
      showConversationContextToast(
        context,
        'لا تملك صلاحية تنفيذ هذا الإجراء.',
      );
      return;
    }
    if (conversation.reviewStatus == 'needs_review') {
      showConversationContextToast(
        context,
        'تم الإبلاغ مسبقًا: المحادثة معلّمة «يحتاج مراجعة».',
      );
      return;
    }
    if (!await confirmConversationContextReport(context, forMessage: true) ||
        !mounted) {
      return;
    }
    final error = await ref
        .read(chatControllerProvider(widget.conversationId).notifier)
        .updateClassification(
          customerPriority:
              ConversationClassificationValues.isAllowedPriority(
                conversation.customerPriority,
              )
              ? conversation.customerPriority
              : ConversationClassificationValues.defaultPriority,
          paymentStatus:
              ConversationClassificationValues.isAllowedPayment(
                conversation.paymentStatus,
              )
              ? conversation.paymentStatus
              : ConversationClassificationValues.defaultPayment,
          reviewStatus: 'needs_review',
        );
    if (!mounted) return;
    showConversationContextToast(
      context,
      error ?? 'تم الإبلاغ. المحادثة الآن «يحتاج مراجعة».',
    );
  }

  Future<void> _deleteMessages(List<MessageModel> messages) async {
    if (messages.isEmpty) return;
    final confirmed = await confirmConversationContextDelete(
      context,
      forMessage: true,
      count: messages.length,
    );
    if (!confirmed || !mounted) return;
    await ref.read(conversationMarksProvider.notifier).hideMessages(messages);
    if (!mounted) return;
    final keys = messages.map(ConversationMarks.messageKey).toSet();
    final reply = _replyTo;
    setState(() {
      _selectedMessageKeys.removeAll(keys);
      if (reply != null && keys.contains(ConversationMarks.messageKey(reply))) {
        _replyTo = null;
      }
    });
    showConversationContextToast(
      context,
      messages.length > 1
          ? 'تم حذف الرسائل من هذا الجهاز.'
          : 'تم حذف الرسالة من هذا الجهاز.',
    );
  }

  Future<void> _copySelectedMessages() async {
    final selected = _selectedMessages();
    final text = selected
        .map(_messageText)
        .where((value) => value.isNotEmpty)
        .join('\n');
    _clearMessageSelection();
    await copyConversationContextText(
      context,
      text,
      copiedMessage: selected.length > 1
          ? 'تم نسخ الرسائل.'
          : 'تم نسخ الرسالة.',
    );
  }

  Future<void> _starSelectedMessages() async {
    final selected = _selectedMessages();
    if (selected.isEmpty) return;
    final marks = ref.read(conversationMarksProvider);
    final starAll = selected.any((m) => !marks.isMessageStarred(m));
    await ref
        .read(conversationMarksProvider.notifier)
        .setMessagesStarred(selected, starred: starAll);
    if (!mounted) return;
    _clearMessageSelection();
    showConversationContextToast(
      context,
      starAll ? 'تم تمييز الرسائل بنجمة.' : 'تمت إزالة النجمة.',
    );
  }

  void _showPresentEmployeesSheet(
    BuildContext context,
    List<PresentEmployeeModel> employees,
  ) {
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: HasimTokens.cardBg(context),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text(
                      'الموظفون داخل المحادثة',
                      style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                    ),
                    Text(
                      '${employees.length} متصل',
                      style: TextStyle(
                        fontSize: 12,
                        color: HasimTokens.mutedColor(context),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                const Divider(height: 1),
                const SizedBox(height: 8),
                for (final emp in employees)
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: CircleAvatar(
                      radius: 16,
                      backgroundColor: HasimTokens.chatGreen.withValues(alpha: 0.15),
                      child: Text(
                        emp.initials,
                        style: const TextStyle(
                          color: HasimTokens.chatGreen,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                    title: Row(
                      children: [
                        Text(
                          emp.name,
                          style: const TextStyle(
                            fontWeight: FontWeight.w600,
                            fontSize: 13,
                          ),
                        ),
                        if (emp.isAssigned) ...[
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 6,
                              vertical: 2,
                            ),
                            decoration: BoxDecoration(
                              color: Colors.blue.shade50,
                              borderRadius: BorderRadius.circular(6),
                            ),
                            child: Text(
                              'المسؤول المعين',
                              style: TextStyle(
                                color: Colors.blue.shade800,
                                fontSize: 9.5,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ),
                        ],
                      ],
                    ),
                    trailing: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Container(
                          width: 6,
                          height: 6,
                          decoration: BoxDecoration(
                            color: emp.state == 'typing'
                                ? Colors.amber.shade600
                                : (emp.state == 'sending'
                                    ? Colors.blue.shade600
                                    : HasimTokens.chatGreen),
                            shape: BoxShape.circle,
                          ),
                        ),
                        const SizedBox(width: 5),
                        Text(
                          emp.state == 'typing'
                              ? 'يكتب الآن'
                              : (emp.state == 'sending' ? 'يرسل...' : 'يشاهد'),
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w500,
                            color: emp.state == 'typing'
                                ? Colors.amber.shade800
                                : HasimTokens.chatGreen,
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        );
      },
    );
  }

  PreferredSizeWidget _messageSelectionAppBar(Color headerBg) {
    return AppBar(
      key: const Key('chat-selection-appbar'),
      automaticallyImplyLeading: false,
      centerTitle: false,
      backgroundColor: headerBg,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      scrolledUnderElevation: 0,
      shape: Border(bottom: BorderSide(color: HasimTokens.lineColor(context))),
      leading: IconButton(
        key: const Key('chat-selection-close'),
        tooltip: 'إلغاء التحديد',
        onPressed: _clearMessageSelection,
        icon: const Icon(Icons.close_rounded),
      ),
      title: Text(
        '${_selectedMessageKeys.length}',
        key: const Key('chat-selection-count'),
        style: Theme.of(
          context,
        ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700),
      ),
      actions: [
        IconButton(
          key: const Key('chat-selection-copy'),
          tooltip: 'نسخ',
          onPressed: _copySelectedMessages,
          icon: const Icon(Icons.copy_rounded),
        ),
        IconButton(
          key: const Key('chat-selection-star'),
          tooltip: 'تمييز بنجمة',
          onPressed: _starSelectedMessages,
          icon: const Icon(Icons.star_outline_rounded),
        ),
        IconButton(
          key: const Key('chat-selection-delete'),
          tooltip: 'حذف',
          onPressed: () => _deleteMessages(_selectedMessages()),
          icon: const Icon(Icons.delete_outline_rounded),
        ),
        const SizedBox(width: 8),
      ],
    );
  }

  Widget _pinnedMessageBar(MessageModel message) {
    return Material(
      key: const Key('chat-pinned-bar'),
      color: HasimTokens.cardBg(context),
      child: DecoratedBox(
        decoration: BoxDecoration(
          border: Border(
            bottom: BorderSide(color: HasimTokens.lineColor(context)),
          ),
        ),
        child: Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 2, 4, 2),
          child: Row(
            children: [
              const Icon(
                Icons.push_pin,
                size: 18,
                color: HasimTokens.chatGreen,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  splitQuotedReply(_messageText(message)).body,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: HasimTokens.inkColor(context),
                  ),
                ),
              ),
              IconButton(
                key: const Key('chat-pinned-unpin'),
                tooltip: 'إلغاء التثبيت',
                visualDensity: VisualDensity.compact,
                onPressed: () => ref
                    .read(conversationMarksProvider.notifier)
                    .toggleMessagePinned(message),
                icon: Icon(
                  Icons.close_rounded,
                  size: 18,
                  color: HasimTokens.mutedColor(context),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  static const _voiceMax = Duration(minutes: 5);

  Future<void> _startVoiceRecording() async {
    if (_recordingVoice) return;
    final currentConversation =
        ref.read(chatControllerProvider(widget.conversationId)).conversation ??
        widget.initialConversation;
    if (currentConversation?.canReply == false) {
      return;
    }
    _composerFocus.unfocus();
    setState(() {
      _emojiPickerOpen = false;
      _attachMenuOpen = false;
    });
    try {
      _voiceRecorder ??= ChatVoiceRecorder();
      final allowed = await _voiceRecorder!.ensurePermission();
      if (!mounted) return;
      if (!allowed) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('يلزم السماح بالميكروفون لتسجيل رسالة صوتية.'),
          ),
        );
        return;
      }
      await _voiceRecorder!.start();
      if (!mounted) return;
      await _voiceAmplitudeSub?.cancel();
      _voiceAmplitudeSub = _voiceRecorder!
          .amplitudeStream(const Duration(milliseconds: 100))
          .listen((value) {
            if (!mounted) return;
            final normalized = ((value + 60) / 60).clamp(0.0, 1.0);
            setState(() => _voiceAmplitude = normalized.toDouble());
          });
      _voiceTick?.cancel();
      setState(() {
        _recordingVoice = true;
        _voicePaused = false;
        _voiceAmplitude = 0.35;
        _voiceElapsed = Duration.zero;
      });
      _voiceTick = Timer.periodic(const Duration(seconds: 1), (timer) {
        if (!mounted) {
          timer.cancel();
          return;
        }
        if (_voicePaused) return;
        final next = _voiceElapsed + const Duration(seconds: 1);
        setState(() => _voiceElapsed = next);
        if (next >= _voiceMax) {
          unawaited(_confirmVoiceRecording());
        }
      });
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('تعذر بدء التسجيل الصوتي.')));
    }
  }

  Future<void> _cancelVoiceRecording() async {
    _voiceTick?.cancel();
    _voiceTick = null;
    await _voiceAmplitudeSub?.cancel();
    _voiceAmplitudeSub = null;
    try {
      await _voiceRecorder?.cancel();
    } catch (_) {}
    if (!mounted) return;
    setState(() {
      _recordingVoice = false;
      _voicePaused = false;
      _voiceAmplitude = 0.35;
      _voiceElapsed = Duration.zero;
    });
  }

  Future<void> _toggleVoicePause() async {
    final recorder = _voiceRecorder;
    if (!_recordingVoice || recorder == null) return;
    try {
      if (_voicePaused) {
        await recorder.resume();
      } else {
        await recorder.pause();
      }
      if (mounted) {
        setState(() => _voicePaused = !_voicePaused);
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تعذر إيقاف التسجيل مؤقتًا.')),
      );
    }
  }

  Future<void> _confirmVoiceRecording() async {
    if (!_recordingVoice) return;
    final elapsed = _voiceElapsed;
    _voiceTick?.cancel();
    _voiceTick = null;
    await _voiceAmplitudeSub?.cancel();
    _voiceAmplitudeSub = null;
    setState(() {
      _recordingVoice = false;
      _voicePaused = false;
      _voiceAmplitude = 0.35;
    });
    ChatVoiceNote? note;
    try {
      note = await _voiceRecorder?.stop();
    } catch (_) {
      note = null;
    }
    if (!mounted) return;
    setState(() {
      _voiceElapsed = Duration.zero;
    });
    if (elapsed < const Duration(milliseconds: 500) || note == null) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('التسجيل قصير جداً.')));
      return;
    }
    _stickToBottomNotifier.value = true;
    final err = await ref
        .read(chatControllerProvider(widget.conversationId).notifier)
        .sendVoiceNote(
          bytes: note.bytes,
          filename: note.filename,
          mime: note.mime,
        );
    if (!mounted) return;
    if (err != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            _attachmentErrorMessage(AppLocalizations.of(context), err),
          ),
        ),
      );
    }
  }

  void _openCustomerFile() {
    if (widget.embedded) {
      ref.read(desktopCustomerDrawerOpenProvider.notifier).state = true;
      return;
    }
    final customer =
        ref
            .read(chatControllerProvider(widget.conversationId))
            .conversation
            ?.customer ??
        widget.initialConversation?.customer;
    final customerId = customer?.id;
    if (customerId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('لا يوجد ملف عميل مرتبط بهذه المحادثة.')),
      );
      return;
    }
    context.push('/customers/$customerId', extra: customer);
  }

  Future<void> _onChatMenuSelected(
    BuildContext context,
    String value,
    ConversationModel? conversation,
  ) async {
    switch (value) {
      case 'add_contact':
        await _quickAddContact();
      case 'search':
        setState(() => _findingMessages = true);
      case 'themes':
        context.push('/chat-themes?conversation=${widget.conversationId}');
      case 'more':
        await _showMoreChatActions(context, conversation);
      case 'add_list':
      case 'media':
      case 'disappearing':
        _soon();
    }
  }

  void _soon() {
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(const SnackBar(content: Text('قريباً')));
  }

  Future<void> _showMoreChatActions(
    BuildContext context,
    ConversationModel? conversation,
  ) async {
    final l10n = AppLocalizations.of(context);
    final showSpecialInboxSections = ref
        .read(authControllerProvider)
        .canSeeSpecialInboxSections;
    final isCompanyUser = ref.read(authControllerProvider).isCompanyUser;
    final button =
        _chatMenuKey.currentContext?.findRenderObject() as RenderBox?;
    final overlay =
        Overlay.of(context).context.findRenderObject() as RenderBox?;
    final position = button == null || overlay == null
        ? const RelativeRect.fromLTRB(8, 56, 8, 0)
        : RelativeRect.fromRect(
            Rect.fromLTWH(
              button.localToGlobal(Offset.zero, ancestor: overlay).dx,
              button.localToGlobal(Offset.zero, ancestor: overlay).dy +
                  button.size.height,
              button.size.width,
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
        const PopupMenuItem(value: 'customer', child: Text('ملف العميل')),
        const PopupMenuItem(value: 'suggest', child: Text('اقتراح رد')),
        const PopupMenuItem(value: 'summarize', child: Text('تلخيص')),
        if (conversation != null && showSpecialInboxSections)
          PopupMenuItem(
            key: const Key('chat-action-archive'),
            value: 'archive',
            child: Text(
              conversation.archived
                  ? l10n.unarchiveConversation
                  : l10n.archiveConversation,
            ),
          ),
        if (conversation != null)
          PopupMenuItem(
            key: const Key('chat-action-mute'),
            value: 'mute',
            child: Text(
              conversation.muted
                  ? l10n.unmuteConversation
                  : l10n.muteConversation,
            ),
          ),
        if (conversation != null && showSpecialInboxSections)
          PopupMenuItem(
            key: const Key('chat-action-lock'),
            value: 'lock',
            child: Text(
              ref
                      .read(lockedConversationIdsProvider)
                      .contains(widget.conversationId)
                  ? 'إلغاء القفل'
                  : 'قفل الدردشة',
            ),
          ),
        if (conversation != null && isCompanyUser)
          PopupMenuItem(
            key: const Key('chat-action-classify'),
            value: 'classify',
            child: Text(
              'تصنيف المحادثة · ${ConversationClassificationValues.priorityLabel(conversation.customerPriority)}',
            ),
          ),
        if (conversation != null)
          PopupMenuItem(
            key: const Key('chat-action-toggle-status'),
            value: conversation.status == 'closed' ? 'reopen' : 'close',
            child: Text(
              conversation.status == 'closed'
                  ? 'إعادة فتح المحادثة'
                  : 'إغلاق المحادثة',
            ),
          ),
        if (conversation != null &&
            isCompanyUser &&
            ConversationHandoffValues.canMoveToTeamReview(conversation))
          const PopupMenuItem(
            key: Key('chat-action-move-to-team'),
            value: 'move_to_team',
            child: Text('تحويل إلى الفريق'),
          ),
        if (conversation != null &&
            isCompanyUser &&
            ConversationHandoffValues.canEndHandoff(conversation))
          const PopupMenuItem(
            key: Key('chat-action-end-handoff'),
            value: 'end_handoff',
            child: Text('إنهاء التحويل'),
          ),
        if (conversation != null &&
            ConversationHandoffValues.canTakeoverForActor(
              conversation,
              isWorker: !isCompanyUser,
              currentUserId: ref.read(authControllerProvider).user?.id,
            ))
          const PopupMenuItem(
            key: Key('chat-action-takeover'),
            value: 'takeover',
            child: Text('تولي الرد'),
          ),
        if (conversation != null &&
            ConversationHandoffValues.canResumeAiForActor(
              conversation,
              isWorker: !isCompanyUser,
              currentUserId: ref.read(authControllerProvider).user?.id,
            ))
          const PopupMenuItem(
            key: Key('chat-action-resume-ai'),
            value: 'resume_ai',
            child: Text('تحويل إلى الذكاء الاصطناعي'),
          ),
      ],
    );
    if (!mounted || selected == null) return;
    if (selected == 'customer') _openCustomerFile();
    if (selected == 'suggest') _suggest();
    if (selected == 'summarize') _summarize();
    if (selected == 'lock') {
      if (!mounted) return;
      final locked = ref
          .read(lockedConversationIdsProvider)
          .contains(widget.conversationId);
      if (locked) {
        await ref
            .read(chatLockControllerProvider.notifier)
            .setConversationLocked(widget.conversationId, locked: false);
      } else {
        if (!mounted) return;
        await lockConversation(this.context, ref, widget.conversationId);
      }
      return;
    }
    if (selected == 'archive' || selected == 'mute') {
      final current = ref
          .read(chatControllerProvider(widget.conversationId))
          .conversation;
      if (current == null) return;
      final notifier = ref.read(
        chatControllerProvider(widget.conversationId).notifier,
      );
      final error = selected == 'archive'
          ? await notifier.setArchived(archived: !current.archived)
          : await notifier.setMuted(muted: !current.muted);
      if (error == null || !mounted) return;
      final fallback = selected == 'archive'
          ? (current.archived ? l10n.unarchiveFailed : l10n.archiveFailed)
          : (current.muted ? l10n.unmuteFailed : l10n.muteFailed);
      ScaffoldMessenger.of(
        this.context,
      ).showSnackBar(SnackBar(content: Text(fallback)));
    }
    if (selected == 'classify' && conversation != null) {
      await _openClassification(conversation);
    }
    if (selected == 'close' || selected == 'reopen') {
      final notifier = ref.read(
        chatControllerProvider(widget.conversationId).notifier,
      );
      final error = selected == 'close'
          ? await notifier.closeConversation()
          : await notifier.reopenConversation();
      if (!mounted) return;
      if (error == null) {
        ScaffoldMessenger.of(this.context).showSnackBar(
          SnackBar(
            content: Text(selected == 'close'
                ? 'تم إغلاق المحادثة.'
                : 'تمت إعادة فتح المحادثة.'),
            backgroundColor: selected == 'close'
                ? HasimTokens.muted
                : HasimTokens.chatGreen,
          ),
        );
      } else {
        ScaffoldMessenger.of(this.context).showSnackBar(
          SnackBar(content: Text(error)),
        );
      }
    }
    if (selected == 'end_handoff' ||
        selected == 'move_to_team' ||
        selected == 'takeover' ||
        selected == 'resume_ai') {
      final notifier = ref.read(
        chatControllerProvider(widget.conversationId).notifier,
      );
      final wasMutating = ref
          .read(chatControllerProvider(widget.conversationId))
          .mutating;
      final Future<String?> request;
      if (selected == 'resume_ai') {
        request = notifier.resumeAi();
      } else if (selected == 'takeover') {
        request = notifier.takeoverHandoff();
      } else {
        request = notifier.endHandoff();
      }
      final error = await request;
      if (!mounted) return;
      if (error != null) {
        ScaffoldMessenger.of(
          this.context,
        ).showSnackBar(SnackBar(content: Text(error)));
        return;
      }
      if (wasMutating) return;
      ScaffoldMessenger.of(
        this.context,
      ).showSnackBar(const SnackBar(content: Text('تم تحديث حالة التحويل')));
    }
  }

  Future<void> _openClassification(ConversationModel conversation) async {
    if (!ref.read(authControllerProvider).isCompanyUser) return;
    final notifier = ref.read(
      chatControllerProvider(widget.conversationId).notifier,
    );
    await showConversationClassificationSheet(
      context: context,
      conversation: conversation,
      submitting: ref
          .read(chatControllerProvider(widget.conversationId))
          .mutating,
      onSave:
          ({
            required customerPriority,
            required paymentStatus,
            required reviewStatus,
          }) async {
            final error = await notifier.updateClassification(
              customerPriority: customerPriority,
              paymentStatus: paymentStatus,
              reviewStatus: reviewStatus,
            );
            if (error == null && mounted) {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('تم تحديث تصنيف المحادثة')),
              );
            }
            return error;
          },
    );
  }

  void _onAttachPressed() {
    if (!HasimLayout.isCompact(context)) {
      _toggleAttachMenu();
      return;
    }
    if (!mounted) return;
    final open = !_attachMenuOpen;
    setState(() {
      _attachMenuOpen = open;
      if (open) _emojiPickerOpen = false;
    });
    if (open) _composerFocus.requestFocus();
  }

  Future<void> _openCamera() async {
    await _pickAttachment('camera');
  }

  void _toggleAttachMenu() {
    if (!mounted) return;
    setState(() {
      _attachMenuOpen = !_attachMenuOpen;
      if (_attachMenuOpen) _emojiPickerOpen = false;
    });
  }

  Future<void> _onAttachMenuSelected(String action) async {
    setState(() => _attachMenuOpen = false);
    const comingSoon = {
      'contact',
      'poll',
      'event',
      'sticker',
      'quick_replies',
      'location',
    };
    if (comingSoon.contains(action)) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('قريباً')));
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
    final files = <File>[];
    switch (action) {
      case 'photos_videos':
        try {
          final pickedList = await ImagePicker().pickMultiImage(
            imageQuality: 85,
          );
          if (pickedList.isNotEmpty) {
            files.addAll(pickedList.map((x) => File(x.path)));
          } else {
            final media = await ImagePicker().pickMedia(imageQuality: 85);
            if (media != null) files.add(File(media.path));
          }
        } catch (_) {
          final media = await ImagePicker().pickMedia(imageQuality: 85);
          if (media != null) files.add(File(media.path));
        }
      case 'gallery':
        try {
          final pickedList = await ImagePicker().pickMultiImage(
            imageQuality: 85,
          );
          if (pickedList.isNotEmpty) {
            files.addAll(pickedList.map((x) => File(x.path)));
          } else {
            final picked = await ImagePicker().pickImage(
              source: ImageSource.gallery,
              imageQuality: 85,
            );
            if (picked != null) files.add(File(picked.path));
          }
        } catch (_) {
          final picked = await ImagePicker().pickImage(
            source: ImageSource.gallery,
            imageQuality: 85,
          );
          if (picked != null) files.add(File(picked.path));
        }
      case 'camera':
        try {
          final picked = await ImagePicker().pickImage(
            source: ImageSource.camera,
            imageQuality: 85,
          );
          if (picked != null) files.add(File(picked.path));
        } catch (_) {
          if (!mounted) return;
          ScaffoldMessenger.of(
            context,
          ).showSnackBar(const SnackBar(content: Text('تعذر فتح الكاميرا.')));
          return;
        }
      case 'video':
        final result = await FilePicker.platform.pickFiles(
          type: FileType.custom,
          allowedExtensions: const ['mp4', 'webm'],
          allowMultiple: true,
        );
        if (result != null) {
          files.addAll(result.paths.whereType<String>().map((p) => File(p)));
        }
      case 'audio':
        final result = await FilePicker.platform.pickFiles(
          type: FileType.custom,
          allowedExtensions: const ['mp3', 'mpeg', 'wav', 'webm', 'm4a', 'aac'],
          allowMultiple: true,
        );
        if (result != null) {
          files.addAll(result.paths.whereType<String>().map((p) => File(p)));
        }
      case 'file':
        final result = await FilePicker.platform.pickFiles(
          type: FileType.custom,
          allowedExtensions: ChatAttachmentPolicy.allowedExtensions.toList()
            ..sort(),
          allowMultiple: true,
        );
        if (result != null) {
          files.addAll(result.paths.whereType<String>().map((p) => File(p)));
        }
    }
    if (files.isEmpty || !mounted) return;

    // WhatsApp-style Media Preview before sending
    final previewResult = await ChatMediaPreviewDialog.show(
      context,
      files: files,
    );
    if (previewResult == null || previewResult.files.isEmpty || !mounted) {
      return;
    }

    _stickToBottomNotifier.value = true;
    final err = await ref
        .read(chatControllerProvider(widget.conversationId).notifier)
        .sendMultipleAttachments(
          previewResult.files,
          caption: previewResult.caption,
        );
    if (!mounted) return;
    if (err != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(_attachmentErrorMessage(l10n, err))),
      );
    }
    _keepComposerFocus();
  }

  String _attachmentErrorMessage(AppLocalizations l10n, String code) {
    if (RegExp(r'[\u0600-\u06FF]').hasMatch(code)) {
      return code;
    }
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

  double _phoneEmojiDock(BuildContext context) {
    if (!HasimLayout.isCompact(context) || !_emojiPickerOpen) return 0;
    return ChatEmojiPickerPanel.dockedHeight +
        MediaQuery.paddingOf(context).bottom;
  }

  void _onEmojiPressed() {
    if (!HasimLayout.isCompact(context)) {
      _toggleEmojiPicker();
      return;
    }
    if (!mounted) return;
    final open = !_emojiPickerOpen;
    if (open) {
      _composerFocus.unfocus();
    } else {
      _composerFocus.requestFocus();
    }
    setState(() {
      _emojiPickerOpen = open;
      if (open) _attachMenuOpen = false;
    });
  }

  void _toggleEmojiPicker() {
    if (!mounted) return;
    setState(() {
      _emojiPickerOpen = !_emojiPickerOpen;
      if (_emojiPickerOpen) _attachMenuOpen = false;
    });
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

  double _emojiPickerLeft(double panelWidth) =>
      _anchorMenuLeft(_emojiButtonKey, panelWidth);

  Future<void> _suggest() async {
    final generation = ++_suggestGeneration;
    final conversationId = widget.conversationId;
    final composerAtRequest = _controller.text;
    final text = await ref
        .read(chatControllerProvider(conversationId).notifier)
        .suggestReply();
    if (!mounted) return;
    if (!shouldApplyAiSuggestion(
      requestConversationId: conversationId,
      currentConversationId: widget.conversationId,
      requestGeneration: generation,
      currentGeneration: _suggestGeneration,
      composerAtRequest: composerAtRequest,
      composerNow: _controller.text,
      suggestion: text,
    )) {
      return;
    }
    final draft = text!.trim().replaceAll(RegExp(r'\s*\n+\s*'), ' ');
    _controller.value = TextEditingValue(
      text: draft,
      selection: TextSelection.collapsed(offset: draft.length),
    );
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(const SnackBar(content: Text('تم إدراج اقتراح الرد.')));
  }

  Future<void> _summarize() async {
    final text = await ref
        .read(chatControllerProvider(widget.conversationId).notifier)
        .summarize();
    if (!mounted || text == null) return;
    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('ملخص المحادثة'),
        content: SingleChildScrollView(
          child: Text(text, style: const TextStyle(height: 1.45)),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('إغلاق'),
          ),
        ],
      ),
    );
  }

  Future<void> _quickAddContact() async {
    final customer = ref
        .read(chatControllerProvider(widget.conversationId))
        .conversation
        ?.customer;
    final email = customer?.email?.trim();
    if (email == null || email.isEmpty) return;
    try {
      final existing = await ref
          .read(contactRepositoryProvider)
          .findByEmail(email);
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
      final created = await ref
          .read(contactRepositoryProvider)
          .create(
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
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(e.userMessage)));
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text('تعذر الإضافة')));
      }
    }
  }

  Future<void> _openImage(String url) async {
    final state = ref.read(chatControllerProvider(widget.conversationId));
    final allImages = <MessageAttachmentModel>[];
    int targetIndex = 0;
    for (final m in state.messages) {
      for (final a in m.attachments) {
        if (a.kind == 'image' && a.displayUrl != null) {
          if (a.displayUrl == url ||
              a.previewUrl == url ||
              a.downloadUrl == url) {
            targetIndex = allImages.length;
          }
          allImages.add(a);
        }
      }
    }
    if (allImages.isEmpty) {
      allImages.add(
        MessageAttachmentModel(
          id: 0,
          kind: 'image',
          originalName: 'صورة',
          downloadUrl: url,
          previewUrl: url,
        ),
      );
    }
    await ChatMediaViewerDialog.show(
      context,
      attachments: allImages,
      initialIndex: targetIndex,
      senderName:
          state.conversation?.customer?.name ?? state.conversation?.title,
    );
  }

  static bool _sameSenderGroup(MessageModel? a, MessageModel? b) {
    if (a == null || b == null) return false;
    if (a.isInternalNote || b.isInternalNote) return false;
    if (a.localFailed || b.localFailed) return false;
    if (a.direction != b.direction) return false;
    if (a.isFromAi != b.isFromAi) return false;
    if (a.participantType != b.participantType) return false;
    if (a.senderDisplayName != b.senderDisplayName) return false;
    if (a.createdAt != null && b.createdAt != null) {
      return b.createdAt!.difference(a.createdAt!).inMinutes.abs() <= 3;
    }
    return true;
  }

  @override
  Widget build(BuildContext context) {
    final locked = ref
        .watch(lockedConversationIdsProvider)
        .contains(widget.conversationId);
    final chatsUnlocked = ref.watch(
      chatLockControllerProvider.select((lock) => lock.chatsUnlocked),
    );
    if (locked && !chatsUnlocked) {
      return LockedContentGate(
        title: 'دردشة مقفلة',
        subtitle: 'لا يُعرض محتوى هذه الدردشة قبل المصادقة بالبصمة أو رمز PIN.',
        onUnlock: () => openLockedChats(context, ref),
      );
    }

    ref.listen(chatControllerProvider(widget.conversationId), (previous, next) {
      if (previous?.sending == true && !next.sending) {
        _keepComposerFocus();
      }
      if (next.sendFailureSeq != (previous?.sendFailureSeq ?? 0) &&
          next.lastSendFailureMessage != null &&
          next.lastSendFailureMessage!.isNotEmpty &&
          mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(next.lastSendFailureMessage!),
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
      if (next.messages.isEmpty) return;

      if (!_openedAtBottom && next.messages.isNotEmpty) {
        _openedAtBottom = true;
        _stickToBottomNotifier.value = true;
        _scrollToLatest();
        return;
      }

      final prevLast = previous?.messages.lastOrNull;
      final nextLast = next.messages.last;
      final appendedAtEnd =
          prevLast == null ||
          prevLast.id != nextLast.id ||
          (prevLast.clientId != nextLast.clientId &&
              nextLast.clientId != null);

      if (appendedAtEnd) {
        if (_stickToBottomNotifier.value || nextLast.localPending) {
          _scrollToLatest();
        } else if (!nextLast.isOutbound) {
          _unseenBelowNotifier.value = _unseenBelowNotifier.value + 1;
        }
      }
    });

    final l10n = AppLocalizations.of(context);
    final selectingMessages = _selectedMessageKeys.isNotEmpty;
    final compact = HasimLayout.isCompact(context);
    final attachDock =
        compact && _attachMenuOpen ? ChatAttachmentGridPanel.heightFor(context) : 0.0;
    final emojiDock = _phoneEmojiDock(context);
    final bottomOverlaysHeight = attachDock + emojiDock;
    final composerPad = _composerHeight + 8 + bottomOverlaysHeight;
    final replyTo = _replyTo;
    final headerBg = HasimTokens.cardBg(context);

    final scaffold = Scaffold(
      backgroundColor: HasimTokens.chatCanvas(context),
      appBar: selectingMessages
          ? _messageSelectionAppBar(headerBg)
          : _ChatHeaderAppBar(
              conversationId: widget.conversationId,
              embedded: widget.embedded,
              initialConversation: widget.initialConversation,
              findingMessages: _findingMessages,
              messageSearch: _messageSearch,
              chatMenuKey: _chatMenuKey,
              onSearchChanged: (v) => _searchQueryNotifier.value = v.trim(),
              onCloseSearch: () {
                _messageSearch.clear();
                _searchQueryNotifier.value = '';
                setState(() => _findingMessages = false);
              },
              onOpenCustomerFile: _openCustomerFile,
              onOpenCustomerDrawer: () =>
                  ref.read(desktopCustomerDrawerOpenProvider.notifier).state = true,
              onShowPresentEmployees: (employees) =>
                  _showPresentEmployeesSheet(context, employees),
              onMenuSelected: (value, conv) =>
                  _onChatMenuSelected(context, value, conv),
            ),
      body: Stack(
        children: [
          Positioned.fill(
            child: ChatWallpaper(conversationId: widget.conversationId),
          ),
          Column(
            children: [
              _ChatProgressBanners(conversationId: widget.conversationId),
              _ChatResponderBannerSection(
                conversationId: widget.conversationId,
                initialConversation: widget.initialConversation,
                compact: compact,
                onTakeover: _handleTakeoverAction,
                onMoveToTeam: _handleMoveToTeamAction,
                onResumeAi: _handleResumeAiAction,
              ),
              _ChatPinnedMessageSection(
                conversationId: widget.conversationId,
                buildBar: _pinnedMessageBar,
              ),
              Expanded(
                child: _ChatMessageListSection(
                  conversationId: widget.conversationId,
                  scrollController: _scroll,
                  searchQueryNotifier: _searchQueryNotifier,
                  bottomPadding: composerPad,
                  selectingMessages: selectingMessages,
                  selectedMessageKeys: _selectedMessageKeys,
                  onRefresh: _refreshMessages,
                  onImageTap: _openImage,
                  onOpenFailed: () {
                    if (!mounted) return;
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(l10n.chatAttachmentOpenFailed)),
                    );
                  },
                  onToggleSelected: _toggleMessageSelection,
                  onContextMenu: _showMessageMenu,
                  onRetryFailed: (message) => ref
                      .read(chatControllerProvider(widget.conversationId).notifier)
                      .retryFailed(message),
                ),
              ),
            ],
          ),
          _ChatTypingIndicatorOverlay(
            conversationId: widget.conversationId,
            bottomOffset: _composerHeight + 12 + bottomOverlaysHeight,
          ),
          _ChatComposerSection(
            conversationId: widget.conversationId,
            initialConversation: widget.initialConversation,
            controller: _controller,
            focusNode: _composerFocus,
            emojiButtonKey: _emojiButtonKey,
            attachButtonKey: _attachButtonKey,
            recording: _recordingVoice,
            recordElapsed: _voiceElapsed,
            emojiAsKeyboard: compact && _emojiPickerOpen,
            bottomSafe: !(bottomOverlaysHeight > 0),
            bottomOffset: bottomOverlaysHeight,
            onSend: _send,
            onAttach: _onAttachPressed,
            onCamera: _openCamera,
            onEmoji: _onEmojiPressed,
            onRecord: _startVoiceRecording,
            onCancelRecord: _cancelVoiceRecording,
            onConfirmRecord: _confirmVoiceRecording,
            onTogglePause: _toggleVoicePause,
            voicePaused: _voicePaused,
            voiceAmplitude: _voiceAmplitude,
            replyTitle: replyTo == null ? null : _replyTitle(replyTo),
            replyPreview:
                replyTo == null ? null : splitQuotedReply(_messageText(replyTo)).body,
            onCancelReply: () => setState(() => _replyTo = null),
          ),
          _ChatScrollToBottomOverlay(
            conversationId: widget.conversationId,
            stickToBottomNotifier: _stickToBottomNotifier,
            unseenBelowNotifier: _unseenBelowNotifier,
            endOffset: compact ? 12 : 20,
            bottomOffset: _composerHeight + 10 + bottomOverlaysHeight,
            onTap: _onScrollToBottomTap,
          ),
          if (_attachMenuOpen && compact)
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: ChatAttachmentGridPanel(onSelected: _onAttachMenuSelected),
            ),
          if (_attachMenuOpen && !compact)
            Builder(
              builder: (context) {
                final left = _anchorMenuLeft(
                  _attachButtonKey,
                  ChatAttachmentMenuPanel.panelWidth,
                );
                final arrowOffset = _attachMenuArrowOffset(left);
                final media = MediaQuery.of(context);
                final available =
                    media.size.height -
                    media.padding.top -
                    kToolbarHeight -
                    media.viewInsets.bottom -
                    ChatComposer.floatingOuterHeight -
                    16;
                return Positioned(
                  left: left,
                  bottom: ChatComposer.floatingOuterHeight + 4,
                  child: ChatAttachmentMenuPanel(
                    arrowOffset: arrowOffset,
                    maxHeight: available,
                    onSelected: _onAttachMenuSelected,
                  ),
                );
              },
            ),
          if (_emojiPickerOpen && compact)
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              height: emojiDock,
              child: ChatEmojiPickerPanel(
                docked: true,
                onEmojiSelected: _insertEmojiCharacter,
                onClose: () {
                  setState(() => _emojiPickerOpen = false);
                  _composerFocus.requestFocus();
                },
              ),
            ),
          if (_emojiPickerOpen && !compact)
            Builder(
              builder: (context) {
                final screenW = MediaQuery.sizeOf(context).width;
                final panelWidth = (screenW - 24).clamp(
                  280.0,
                  ChatEmojiPickerPanel.panelMaxWidth,
                );
                final left = _emojiPickerLeft(panelWidth);
                final media = MediaQuery.of(context);
                final available =
                    media.size.height -
                    media.padding.top -
                    kToolbarHeight -
                    media.viewInsets.bottom -
                    ChatComposer.floatingOuterHeight -
                    16;
                final height = available < ChatEmojiPickerPanel.panelHeight
                    ? available
                    : ChatEmojiPickerPanel.panelHeight;
                return Positioned(
                  left: left,
                  width: panelWidth,
                  bottom: ChatComposer.floatingOuterHeight + 8,
                  height: height.clamp(180.0, ChatEmojiPickerPanel.panelHeight),
                  child: ChatEmojiPickerPanel(
                    onEmojiSelected: _insertEmojiCharacter,
                  ),
                );
              },
            ),
        ],
      ),
    );

    if (!selectingMessages || widget.embedded) {
      return scaffold;
    }
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _clearMessageSelection();
      },
      child: scaffold,
    );
  }

  Future<void> _handleTakeoverAction() async {
    final err = await ref
        .read(chatControllerProvider(widget.conversationId).notifier)
        .takeoverHandoff();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(err ?? 'تم تولي الرد على المحادثة بنجاح')),
    );
  }

  Future<void> _handleMoveToTeamAction() async {
    final err = await ref
        .read(chatControllerProvider(widget.conversationId).notifier)
        .requestHandoff();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(err ?? 'تم تحويل المحادثة إلى الفريق')),
    );
  }

  Future<void> _handleResumeAiAction() async {
    final err = await ref
        .read(chatControllerProvider(widget.conversationId).notifier)
        .resumeAi();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(err ?? 'تم تحويل المحادثة إلى الذكاء الاصطناعي')),
    );
  }
}

class _ChatHeaderAppBar extends ConsumerWidget implements PreferredSizeWidget {
  const _ChatHeaderAppBar({
    required this.conversationId,
    required this.embedded,
    required this.initialConversation,
    required this.findingMessages,
    required this.messageSearch,
    required this.chatMenuKey,
    required this.onSearchChanged,
    required this.onCloseSearch,
    required this.onOpenCustomerFile,
    required this.onOpenCustomerDrawer,
    required this.onShowPresentEmployees,
    required this.onMenuSelected,
  });

  final int conversationId;
  final bool embedded;
  final ConversationModel? initialConversation;
  final bool findingMessages;
  final TextEditingController messageSearch;
  final GlobalKey chatMenuKey;
  final ValueChanged<String> onSearchChanged;
  final VoidCallback onCloseSearch;
  final VoidCallback onOpenCustomerFile;
  final VoidCallback onOpenCustomerDrawer;
  final ValueChanged<List<PresentEmployeeModel>> onShowPresentEmployees;
  final void Function(String value, ConversationModel? conversation) onMenuSelected;

  @override
  Size get preferredSize => Size.fromHeight(kToolbarHeight + (findingMessages ? 52 : 0));

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final conversation = ref.watch(
      chatControllerProvider(conversationId).select((s) => s.conversation),
    ) ?? initialConversation;
    final presentEmployees = ref.watch(
      chatControllerProvider(conversationId).select((s) => s.presentEmployees),
    );
    final mutating = ref.watch(
      chatControllerProvider(conversationId).select((s) => s.mutating),
    );

    final title = conversation?.title ?? 'محادثة';
    final initial = title.isNotEmpty ? title.characters.first : '#';
    final headerBg = HasimTokens.cardBg(context);

    final titleWidget = InkWell(
      key: Key(embedded ? 'desktop-customer-header' : 'chat-customer-header'),
      onTap: onOpenCustomerFile,
      borderRadius: BorderRadius.circular(8),
      child: Row(
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
      ),
    );

    return AppBar(
      automaticallyImplyLeading: !embedded,
      centerTitle: false,
      backgroundColor: headerBg,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      scrolledUnderElevation: 0,
      titleSpacing: embedded ? 16 : 0,
      shape: Border(bottom: BorderSide(color: HasimTokens.lineColor(context))),
      bottom: findingMessages
          ? PreferredSize(
              preferredSize: const Size.fromHeight(52),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
                child: TextField(
                  controller: messageSearch,
                  autofocus: true,
                  onChanged: onSearchChanged,
                  decoration: InputDecoration(
                    hintText: 'بحث في هذه المحادثة',
                    isDense: true,
                    suffixIcon: IconButton(
                      onPressed: onCloseSearch,
                      icon: const Icon(Icons.close),
                    ),
                  ),
                ),
              ),
            )
          : null,
      title: titleWidget,
      actions: [
        if (embedded)
          IconButton(
            key: const Key('desktop-customer-info'),
            tooltip: 'معلومات العميل',
            onPressed: onOpenCustomerDrawer,
            icon: Icon(
              Icons.info_outline_rounded,
              color: HasimTokens.mutedColor(context),
            ),
          ),
        if (presentEmployees.isNotEmpty)
          Padding(
            padding: const EdgeInsetsDirectional.only(end: 4),
            child: InkWell(
              key: const Key('chat-present-employees-button'),
              onTap: () => onShowPresentEmployees(presentEmployees),
              borderRadius: BorderRadius.circular(12),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 4),
                margin: const EdgeInsets.symmetric(vertical: 10),
                decoration: BoxDecoration(
                  color: HasimTokens.chatGreen.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(
                    color: HasimTokens.chatGreen.withValues(alpha: 0.3),
                  ),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Container(
                      width: 6,
                      height: 6,
                      decoration: const BoxDecoration(
                        color: HasimTokens.chatGreen,
                        shape: BoxShape.circle,
                      ),
                    ),
                    const SizedBox(width: 4),
                    Text(
                      'الموظفون (${presentEmployees.length})',
                      style: const TextStyle(
                        fontSize: 10.5,
                        fontWeight: FontWeight.bold,
                        color: HasimTokens.chatGreen,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        PopupMenuButton<String>(
          key: chatMenuKey,
          enabled: !mutating,
          icon: Icon(
            Icons.more_vert_rounded,
            color: HasimTokens.mutedColor(context),
          ),
          offset: const Offset(0, 44),
          color: headerBg,
          elevation: 8,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          onSelected: (v) => onMenuSelected(v, conversation),
          itemBuilder: (_) => const [
            PopupMenuItem(value: 'add_contact', child: Text('إضافة إلى جهات الاتصال')),
            PopupMenuItem(value: 'search', child: Text('بحث')),
            PopupMenuItem(value: 'add_list', child: Text('إضافة إلى القائمة')),
            PopupMenuItem(value: 'media', child: Text('وسائط وروابط ومستندات')),
            PopupMenuItem(value: 'disappearing', child: Text('الرسائل ذاتية الاختفاء')),
            PopupMenuItem(key: Key('chat-action-themes'), value: 'themes', child: Text('سمة الدردشة')),
            PopupMenuItem(
              value: 'more',
              child: Row(
                children: [
                  Expanded(child: Text('المزيد')),
                  Icon(Icons.chevron_left, size: 18),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(width: 8),
      ],
    );
  }
}

class _ChatProgressBanners extends ConsumerWidget {
  const _ChatProgressBanners({required this.conversationId});

  final int conversationId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final loadingOlder = ref.watch(
      chatControllerProvider(conversationId).select((s) => s.loadingOlder),
    );
    final busy = ref.watch(
      chatControllerProvider(conversationId).select((s) => s.aiBusy || s.mutating),
    );
    final reconnecting = (ref.watch(realtimeConnectionStateProvider).valueOrNull ??
            ref.watch(realtimeServiceProvider).currentConnectionState) ==
        RealtimeConnectionState.reconnecting;

    if (!loadingOlder && !busy && !reconnecting) {
      return const SizedBox.shrink();
    }
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (loadingOlder) const LinearProgressIndicator(minHeight: 2),
        if (busy) const LinearProgressIndicator(minHeight: 2),
        if (reconnecting)
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 5),
            color: HasimTokens.warningBannerBg(context),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                SizedBox(
                  width: 11,
                  height: 11,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    valueColor: AlwaysStoppedAnimation<Color>(
                      HasimTokens.warningBannerInk(context),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Flexible(
                  child: Text(
                    'جاري إعادة الاتصال بالتحديث المباشر...',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w600,
                      color: HasimTokens.warningBannerInk(context),
                    ),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}

class _ChatResponderBannerSection extends ConsumerWidget {
  const _ChatResponderBannerSection({
    required this.conversationId,
    required this.initialConversation,
    required this.compact,
    required this.onTakeover,
    required this.onMoveToTeam,
    required this.onResumeAi,
  });

  final int conversationId;
  final ConversationModel? initialConversation;
  final bool compact;
  final VoidCallback onTakeover;
  final VoidCallback onMoveToTeam;
  final VoidCallback onResumeAi;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final conversation = ref.watch(
      chatControllerProvider(conversationId).select((s) => s.conversation),
    ) ?? initialConversation;
    if (conversation == null) {
      return const SizedBox.shrink();
    }
    final isWorker = ref.watch(
      authControllerProvider.select((s) => s.isIndependentWorker),
    );
    final currentUserId = ref.watch(
      authControllerProvider.select((s) => s.user?.id),
    );
    final isMutating = ref.watch(
      chatControllerProvider(conversationId).select((s) => s.mutating),
    );

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (!compact && conversation.isHumanHandoffActive)
          ColoredBox(
            color: HasimTokens.warningBannerBg(context),
            child: SizedBox(
              width: double.infinity,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                child: Text(
                  'تحتاج مراجعة من الفريق',
                  key: const Key('chat-team-review'),
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: HasimTokens.warningBannerInk(context),
                    fontWeight: FontWeight.w700,
                    fontSize: 13,
                  ),
                ),
              ),
            ),
          ),
        _LiveResponderHeaderBar(
          conversation: conversation,
          isWorker: isWorker,
          currentUserId: currentUserId,
          isMutating: isMutating,
          onTakeover: onTakeover,
          onMoveToTeam: onMoveToTeam,
          onResumeAi: onResumeAi,
        ),
      ],
    );
  }
}

class _ChatPinnedMessageSection extends ConsumerWidget {
  const _ChatPinnedMessageSection({
    required this.conversationId,
    required this.buildBar,
  });

  final int conversationId;
  final Widget Function(MessageModel message) buildBar;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final hasPinned = ref.watch(
      conversationMarksProvider.select((m) => m.pinnedMessageKeys.isNotEmpty),
    );
    if (!hasPinned) return const SizedBox.shrink();

    final marks = ref.watch(conversationMarksProvider);
    final messages = ref.watch(
      chatControllerProvider(conversationId).select((s) => s.messages),
    );
    for (final message in messages.reversed) {
      if (marks.isMessagePinned(message) && !marks.isMessageHidden(message)) {
        return buildBar(message);
      }
    }
    return const SizedBox.shrink();
  }
}

class _ChatTypingIndicatorOverlay extends ConsumerWidget {
  const _ChatTypingIndicatorOverlay({
    required this.conversationId,
    required this.bottomOffset,
  });

  final int conversationId;
  final double bottomOffset;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final typingIndicator = ref.watch(
      chatControllerProvider(conversationId).select((s) => s.typingIndicator),
    );
    if (typingIndicator == null) return const SizedBox.shrink();

    return Positioned(
      left: 16,
      bottom: bottomOffset,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          color: HasimTokens.floatingPillBg(context),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: HasimTokens.floatingPillBorder(context)),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(
              Icons.more_horiz_rounded,
              size: 15,
              color: HasimTokens.accent,
            ),
            const SizedBox(width: 6),
            Text(
              typingIndicator,
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w500,
                color: HasimTokens.mutedColor(context),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ChatComposerSection extends ConsumerWidget {
  const _ChatComposerSection({
    required this.conversationId,
    required this.initialConversation,
    required this.controller,
    required this.focusNode,
    required this.emojiButtonKey,
    required this.attachButtonKey,
    required this.recording,
    required this.recordElapsed,
    required this.emojiAsKeyboard,
    required this.bottomSafe,
    required this.bottomOffset,
    required this.onSend,
    required this.onAttach,
    required this.onCamera,
    required this.onEmoji,
    required this.onRecord,
    required this.onCancelRecord,
    required this.onConfirmRecord,
    required this.onTogglePause,
    required this.voicePaused,
    required this.voiceAmplitude,
    this.replyTitle,
    this.replyPreview,
    this.onCancelReply,
  });

  final int conversationId;
  final ConversationModel? initialConversation;
  final TextEditingController controller;
  final FocusNode focusNode;
  final GlobalKey emojiButtonKey;
  final GlobalKey attachButtonKey;
  final bool recording;
  final Duration recordElapsed;
  final bool emojiAsKeyboard;
  final bool bottomSafe;
  final double bottomOffset;
  final VoidCallback onSend;
  final VoidCallback onAttach;
  final VoidCallback onCamera;
  final VoidCallback onEmoji;
  final VoidCallback onRecord;
  final VoidCallback onCancelRecord;
  final VoidCallback onConfirmRecord;
  final VoidCallback onTogglePause;
  final bool voicePaused;
  final double voiceAmplitude;
  final String? replyTitle;
  final String? replyPreview;
  final VoidCallback? onCancelReply;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final canReply = ref.watch(
      chatControllerProvider(conversationId).select(
        (s) => (s.conversation ?? initialConversation)?.canReply != false,
      ),
    );
    if (!canReply) {
      return Positioned(
        left: 0,
        right: 0,
        bottom: 0,
        child: SafeArea(
          child: Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
            decoration: BoxDecoration(
              color: HasimTokens.cardBg(context),
              border: Border(
                top: BorderSide(
                  color: HasimTokens.lineColor(context),
                  width: 0.8,
                ),
              ),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(
                  Icons.lock_outline_rounded,
                  size: 16,
                  color: HasimTokens.mutedColor(context),
                ),
                const SizedBox(width: 8),
                Flexible(
                  child: Text(
                    'لا تملك صلاحية الرد على هذه المحادثة',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: HasimTokens.mutedColor(context),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    }

    final sending = ref.watch(
      chatControllerProvider(conversationId).select((s) => s.sending),
    );
    return Positioned(
      left: 0,
      right: 0,
      bottom: bottomOffset,
      child: ChatComposer(
        controller: controller,
        focusNode: focusNode,
        emojiButtonKey: emojiButtonKey,
        attachButtonKey: attachButtonKey,
        sendEnabled: !sending,
        recording: recording,
        recordElapsed: recordElapsed,
        emojiAsKeyboard: emojiAsKeyboard,
        bottomSafe: bottomSafe,
        onSend: onSend,
        onAttach: onAttach,
        onCamera: onCamera,
        onEmoji: onEmoji,
        onRecord: onRecord,
        onCancelRecord: onCancelRecord,
        onConfirmRecord: onConfirmRecord,
        onTogglePause: onTogglePause,
        voicePaused: voicePaused,
        voiceAmplitude: voiceAmplitude,
        replyTitle: replyTitle,
        replyPreview: replyPreview,
        onCancelReply: onCancelReply,
      ),
    );
  }
}

class _ChatScrollToBottomOverlay extends ConsumerWidget {
  const _ChatScrollToBottomOverlay({
    required this.conversationId,
    required this.stickToBottomNotifier,
    required this.unseenBelowNotifier,
    required this.endOffset,
    required this.bottomOffset,
    required this.onTap,
  });

  final int conversationId;
  final ValueNotifier<bool> stickToBottomNotifier;
  final ValueNotifier<int> unseenBelowNotifier;
  final double endOffset;
  final double bottomOffset;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final isEmpty = ref.watch(
      chatControllerProvider(conversationId).select((s) => s.messages.isEmpty),
    );
    if (isEmpty) return const SizedBox.shrink();

    return ValueListenableBuilder<bool>(
      valueListenable: stickToBottomNotifier,
      builder: (_, stickToBottom, _) {
        if (stickToBottom) return const SizedBox.shrink();
        return PositionedDirectional(
          key: const Key('chat-scroll-to-bottom'),
          end: endOffset,
          bottom: bottomOffset,
          child: Material(
            elevation: 2,
            shadowColor: Colors.black26,
            color: HasimTokens.cardBg(context),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(24),
              side: BorderSide(color: HasimTokens.lineColor(context)),
            ),
            child: InkWell(
              onTap: onTap,
              borderRadius: BorderRadius.circular(24),
              child: Padding(
                padding: const EdgeInsets.all(8),
                child: ValueListenableBuilder<int>(
                  valueListenable: unseenBelowNotifier,
                  builder: (_, unseenCount, _) {
                    if (unseenCount <= 0) {
                      return const Icon(
                        Icons.keyboard_arrow_down_rounded,
                        size: 26,
                        color: HasimTokens.chatGreen,
                      );
                    }
                    return Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 6,
                            vertical: 2,
                          ),
                          decoration: BoxDecoration(
                            color: HasimTokens.chatGreen,
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: Text(
                            unseenCount > 99 ? '99+' : '$unseenCount',
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 11,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                        const SizedBox(width: 2),
                        const Icon(
                          Icons.keyboard_arrow_down_rounded,
                          size: 24,
                          color: HasimTokens.chatGreen,
                        ),
                      ],
                    );
                  },
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _ChatMessageListSection extends ConsumerStatefulWidget {
  const _ChatMessageListSection({
    required this.conversationId,
    required this.scrollController,
    required this.searchQueryNotifier,
    required this.bottomPadding,
    required this.selectingMessages,
    required this.selectedMessageKeys,
    required this.onRefresh,
    required this.onImageTap,
    required this.onOpenFailed,
    required this.onToggleSelected,
    required this.onContextMenu,
    required this.onRetryFailed,
  });

  final int conversationId;
  final ScrollController scrollController;
  final ValueNotifier<String> searchQueryNotifier;
  final double bottomPadding;
  final bool selectingMessages;
  final Set<String> selectedMessageKeys;
  final VoidCallback onRefresh;
  final void Function(String url) onImageTap;
  final VoidCallback onOpenFailed;
  final void Function(MessageModel message) onToggleSelected;
  final void Function(MessageModel message, Offset position) onContextMenu;
  final void Function(MessageModel message) onRetryFailed;

  @override
  ConsumerState<_ChatMessageListSection> createState() =>
      _ChatMessageListSectionState();
}

class _ChatMessageListSectionState extends ConsumerState<_ChatMessageListSection> {
  List<MessageModel>? _cachedRowsSource;
  String? _cachedRowsQuery;
  Set<String>? _cachedHiddenKeys;
  List<_ChatRow> _cachedRows = const [];
  final Map<String, int> _cachedKeyToForwardIndex = <String, int>{};
  final Map<int, int> _cachedMessageIdxToRowIdx = <int, int>{};
  DateTime? _cachedLastDay;

  static String _rowKeyFor(_ChatRow row) {
    if (row.date != null) {
      return 'chat-date-${row.date!.millisecondsSinceEpoch}';
    }
    final m = row.message!;
    final base = m.id > 0 ? 's-${m.id}' : 'c-${m.clientId ?? m.id}';
    return 'chat-msg-$base';
  }

  void _rebuildAllRows(List<MessageModel> visibleMessages) {
    final rows = <_ChatRow>[];
    _cachedKeyToForwardIndex.clear();
    _cachedMessageIdxToRowIdx.clear();
    DateTime? lastDay;
    for (var i = 0; i < visibleMessages.length; i++) {
      final message = visibleMessages[i];
      final created = message.createdAt?.toLocal();
      if (created != null) {
        final day = DateTime(created.year, created.month, created.day);
        if (lastDay == null || day != lastDay) {
          final dateRow = _ChatRow.date(day);
          _cachedKeyToForwardIndex[_rowKeyFor(dateRow)] = rows.length;
          rows.add(dateRow);
          lastDay = day;
        }
      }
      final msgRow = _ChatRow.message(message);
      _cachedMessageIdxToRowIdx[i] = rows.length;
      _cachedKeyToForwardIndex[_rowKeyFor(msgRow)] = rows.length;
      rows.add(msgRow);
    }
    _cachedLastDay = lastDay;
    _cachedRows = rows;
  }

  List<_ChatRow> _rowsFor(
    List<MessageModel> messages,
    String query,
    Set<String> hiddenKeys,
  ) {
    if (identical(_cachedRowsSource, messages) &&
        _cachedRowsQuery == query &&
        identical(_cachedHiddenKeys, hiddenKeys)) {
      return _cachedRows;
    }

    final canIncremental =
        query.isEmpty &&
        hiddenKeys.isEmpty &&
        _cachedRowsQuery == '' &&
        (_cachedHiddenKeys?.isEmpty ?? false) &&
        _cachedRowsSource != null;

    if (canIncremental) {
      final prev = _cachedRowsSource!;
      // Fast O(1) tail-append path
      if (messages.length == prev.length + 1 &&
          prev.isNotEmpty &&
          identical(messages[prev.length - 1], prev.last) &&
          identical(messages.first, prev.first)) {
        final lastMsg = messages.last;
        final created = lastMsg.createdAt?.toLocal();
        if (created != null) {
          final day = DateTime(created.year, created.month, created.day);
          if (_cachedLastDay == null || day != _cachedLastDay) {
            final dateRow = _ChatRow.date(day);
            _cachedKeyToForwardIndex[_rowKeyFor(dateRow)] = _cachedRows.length;
            _cachedRows.add(dateRow);
            _cachedLastDay = day;
          }
        }
        final msgRow = _ChatRow.message(lastMsg);
        _cachedMessageIdxToRowIdx[messages.length - 1] = _cachedRows.length;
        _cachedKeyToForwardIndex[_rowKeyFor(msgRow)] = _cachedRows.length;
        _cachedRows.add(msgRow);
        _cachedRowsSource = messages;
        return _cachedRows;
      }

      // Fast O(N) pointer equality check -> O(1) single-row update (e.g. status tick)
      if (messages.length == prev.length && messages.isNotEmpty) {
        var diffCount = 0;
        var diffIdx = -1;
        for (var i = messages.length - 1; i >= 0; i--) {
          if (!identical(messages[i], prev[i])) {
            diffCount++;
            diffIdx = i;
            if (diffCount > 1) break;
          }
        }
        if (diffCount == 0) {
          _cachedRowsSource = messages;
          return _cachedRows;
        }
        if (diffCount == 1 &&
            diffIdx >= 0 &&
            messages[diffIdx].createdAt == prev[diffIdx].createdAt) {
          final rowIdx = _cachedMessageIdxToRowIdx[diffIdx];
          if (rowIdx != null) {
            _cachedKeyToForwardIndex.remove(_rowKeyFor(_cachedRows[rowIdx]));
            final updatedRow = _ChatRow.message(messages[diffIdx]);
            _cachedRows[rowIdx] = updatedRow;
            _cachedKeyToForwardIndex[_rowKeyFor(updatedRow)] = rowIdx;
            _cachedRowsSource = messages;
            return _cachedRows;
          }
        }
      }
    }

    final visibleMessages = query.isEmpty && hiddenKeys.isEmpty
        ? messages
        : messages
              .where(
                (m) =>
                    (query.isEmpty || m.content.contains(query)) &&
                    !hiddenKeys.contains(ConversationMarks.messageKey(m)),
              )
              .toList();

    _cachedRowsSource = messages;
    _cachedRowsQuery = query;
    _cachedHiddenKeys = hiddenKeys;
    _rebuildAllRows(visibleMessages);
    return _cachedRows;
  }

  @override
  Widget build(BuildContext context) {
    final loading = ref.watch(
      chatControllerProvider(widget.conversationId).select((s) => s.loading),
    );
    final messages = ref.watch(
      chatControllerProvider(widget.conversationId).select((s) => s.messages),
    );
    final error = ref.watch(
      chatControllerProvider(widget.conversationId).select((s) => s.error),
    );
    final pinnedKeys = ref.watch(
      conversationMarksProvider.select((m) => m.pinnedMessageKeys),
    );
    final starredKeys = ref.watch(
      conversationMarksProvider.select((m) => m.starredMessageKeys),
    );
    final hiddenKeys = ref.watch(
      conversationMarksProvider.select((m) => m.hiddenMessageKeys),
    );
    final sharedBubble = ref.watch(chatBubbleColorControllerProvider);
    final bubbleOverride = ref.watch(
      conversationThemeProvider(
        widget.conversationId,
      ).select((t) => t.bubbleColorId),
    );
    final activeBubbleColorId = bubbleOverride ?? sharedBubble;
    final mediaHeaders = ref.watch(mediaAuthHeadersProvider).valueOrNull;

    if (loading && messages.isEmpty) {
      return const SkeletonList();
    }

    return AsyncBody(
      loading: false,
      error: error != null && messages.isEmpty ? error : null,
      isEmpty: !loading && messages.isEmpty,
      emptyTitle: 'ابدأ المحادثة',
      emptySubtitle: 'لا توجد رسائل بعد.',
      onRetry: widget.onRefresh,
      child: ValueListenableBuilder<String>(
        valueListenable: widget.searchQueryNotifier,
        builder: (context, query, _) {
          final rows = _rowsFor(messages, query, hiddenKeys);
          return LayoutBuilder(
            builder: (context, constraints) {
              final compact = HasimLayout.isCompact(context);
              final bubbleCap = constraints.maxWidth * (compact ? 0.78 : 0.72);
              final renderStyle = ChatBubbleRenderStyle.resolve(
                context,
                maxWidthOverride: bubbleCap,
                bubbleColorId: activeBubbleColorId,
                mediaHeaders: mediaHeaders,
              );

              return RepaintBoundary(
                child: ListView.builder(
                  controller: widget.scrollController,
                  reverse: true,
                  addAutomaticKeepAlives: false,
                  addRepaintBoundaries: true,
                  findChildIndexCallback: (Key key) {
                    if (key is ValueKey<String>) {
                      final forward = _cachedKeyToForwardIndex[key.value];
                      if (forward == null) return null;
                      return rows.length - 1 - forward;
                    }
                    return null;
                  },
                  scrollCacheExtent: const ScrollCacheExtent.pixels(380),
                  padding: EdgeInsets.fromLTRB(
                    compact ? 10 : 16,
                    8,
                    compact ? 10 : 16,
                    widget.bottomPadding,
                  ),
                  itemCount: rows.length,
                  itemBuilder: (context, index) {
                    final rowIdx = rows.length - 1 - index;
                    final row = rows[rowIdx];
                    final keyStr = _rowKeyFor(row);
                    if (row.date != null) {
                      return ChatDateChip(
                        key: ValueKey(keyStr),
                        date: row.date!,
                      );
                    }
                    final prevRow = rowIdx > 0 ? rows[rowIdx - 1] : null;
                    final nextRow = rowIdx + 1 < rows.length
                        ? rows[rowIdx + 1]
                        : null;
                    final m = row.message!;
                    final groupedPrev = _ChatScreenState._sameSenderGroup(
                      prevRow?.message,
                      m,
                    );
                    final groupedNext = _ChatScreenState._sameSenderGroup(
                      m,
                      nextRow?.message,
                    );
                    final markKey = ConversationMarks.messageKey(m);
                    return _MemoizedMessageTile(
                      key: ValueKey(keyStr),
                      message: m,
                      maxWidth: bubbleCap,
                      bubbleColorId: activeBubbleColorId,
                      renderStyle: renderStyle,
                      groupedPrev: groupedPrev,
                      groupedNext: groupedNext,
                      pinned: pinnedKeys.contains(markKey),
                      starred: starredKeys.contains(markKey),
                      selected: widget.selectedMessageKeys.contains(markKey),
                      selectionMode: widget.selectingMessages,
                      onRetry: m.outboundPhase == OutboundPhase.failed
                          ? () => widget.onRetryFailed(m)
                          : null,
                      onImageTap: widget.onImageTap,
                      onMediaUrlExpired: widget.onRefresh,
                      onOpenFailed: widget.onOpenFailed,
                      onToggleSelected: () => widget.onToggleSelected(m),
                      onContextMenu: (pos) => widget.onContextMenu(m, pos),
                    );
                  },
                ),
              );
            },
          );
        },
      ),
    );
  }
}

class _MemoizedMessageTile extends StatefulWidget {
  const _MemoizedMessageTile({
    super.key,
    required this.message,
    required this.maxWidth,
    required this.bubbleColorId,
    required this.renderStyle,
    required this.groupedPrev,
    required this.groupedNext,
    required this.pinned,
    required this.starred,
    required this.selected,
    required this.selectionMode,
    required this.onRetry,
    required this.onImageTap,
    required this.onMediaUrlExpired,
    required this.onOpenFailed,
    required this.onToggleSelected,
    required this.onContextMenu,
  });

  final MessageModel message;
  final double maxWidth;
  final String? bubbleColorId;
  final ChatBubbleRenderStyle renderStyle;
  final bool groupedPrev;
  final bool groupedNext;
  final bool pinned;
  final bool starred;
  final bool selected;
  final bool selectionMode;
  final VoidCallback? onRetry;
  final void Function(String url) onImageTap;
  final VoidCallback onMediaUrlExpired;
  final VoidCallback onOpenFailed;
  final VoidCallback onToggleSelected;
  final void Function(Offset position) onContextMenu;

  @override
  State<_MemoizedMessageTile> createState() => _MemoizedMessageTileState();
}

class _MemoizedMessageTileState extends State<_MemoizedMessageTile> {
  Widget? _cached;

  static bool _sameMessage(MessageModel a, MessageModel b) {
    return identical(a, b) ||
        (a == b &&
            a.localUploading == b.localUploading &&
            a.localAttachmentPath == b.localAttachmentPath &&
            a.userName == b.userName &&
            a.customerName == b.customerName);
  }

  @override
  void didUpdateWidget(covariant _MemoizedMessageTile old) {
    super.didUpdateWidget(old);
    if (!_sameMessage(old.message, widget.message) ||
        old.maxWidth != widget.maxWidth ||
        old.bubbleColorId != widget.bubbleColorId ||
        old.renderStyle != widget.renderStyle ||
        old.groupedPrev != widget.groupedPrev ||
        old.groupedNext != widget.groupedNext ||
        old.pinned != widget.pinned ||
        old.starred != widget.starred ||
        old.selected != widget.selected ||
        old.selectionMode != widget.selectionMode) {
      _cached = null;
    }
  }

  @override
  Widget build(BuildContext context) {
    return _cached ??= SizedBox(
      width: double.infinity,
      child: ChatMessageBubble(
        message: widget.message,
        maxWidth: widget.maxWidth,
        bubbleColorId: widget.bubbleColorId,
        renderStyle: widget.renderStyle,
        groupedWithPrevious: widget.groupedPrev,
        groupedWithNext: widget.groupedNext,
        onRetry: widget.onRetry,
        onImageTap: widget.onImageTap,
        onMediaUrlExpired: widget.onMediaUrlExpired,
        onOpenFailed: widget.onOpenFailed,
        pinned: widget.pinned,
        starred: widget.starred,
        selected: widget.selected,
        selectionMode: widget.selectionMode,
        onToggleSelected: widget.onToggleSelected,
        onContextMenu: widget.onContextMenu,
      ),
    );
  }
}

class _ChatRow {
  const _ChatRow._({this.message, this.date});

  factory _ChatRow.message(MessageModel message) =>
      _ChatRow._(message: message);

  factory _ChatRow.date(DateTime date) => _ChatRow._(date: date);

  final MessageModel? message;
  final DateTime? date;
}

class _LiveResponderHeaderBar extends StatelessWidget {
  const _LiveResponderHeaderBar({
    required this.conversation,
    this.isWorker = false,
    this.currentUserId,
    this.isMutating = false,
    required this.onTakeover,
    required this.onMoveToTeam,
    required this.onResumeAi,
  });

  final ConversationModel conversation;
  final bool isWorker;
  final int? currentUserId;
  final bool isMutating;
  final VoidCallback onTakeover;
  final VoidCallback onMoveToTeam;
  final VoidCallback onResumeAi;

  @override
  Widget build(BuildContext context) {
    final dark = HasimTokens.isDark(context);
    final responder = conversation.effectiveResponder;

    final Color bgColor;
    final Color borderColor;
    final Color textColor;
    final Color dotColor;
    final IconData iconData;

    switch (responder.status) {
      case 'ai_active':
        bgColor = dark ? const Color(0xFF064E3B) : const Color(0xFFE8FAF6);
        borderColor = dark ? const Color(0xFF047857) : const Color(0xFFC9F0E8);
        textColor = dark ? const Color(0xFF6EE7B7) : const Color(0xFF067E6B);
        dotColor = const Color(0xFF10B981);
        iconData = Icons.auto_awesome_rounded;
      case 'human_active':
        bgColor = dark ? const Color(0xFF1E3A8A) : const Color(0xFFEFF6FF);
        borderColor = dark ? const Color(0xFF1D4ED8) : const Color(0xFFBFDBFE);
        textColor = dark ? const Color(0xFF93C5FD) : const Color(0xFF1D4ED8);
        dotColor = const Color(0xFF3B82F6);
        iconData = Icons.person_rounded;
      case 'human_needed':
        bgColor = dark ? const Color(0xFF78350F) : const Color(0xFFFFFBEB);
        borderColor = dark ? const Color(0xFFB45309) : const Color(0xFFFDE68A);
        textColor = dark ? const Color(0xFFFDE68A) : const Color(0xFFB45309);
        dotColor = const Color(0xFFF59E0B);
        iconData = Icons.support_agent_rounded;
      default:
        bgColor = dark ? const Color(0xFF1E293B) : const Color(0xFFF8FAFC);
        borderColor = dark ? const Color(0xFF334155) : const Color(0xFFE2E8F0);
        textColor = dark ? const Color(0xFF94A3B8) : const Color(0xFF475569);
        dotColor = const Color(0xFF94A3B8);
        iconData = Icons.schedule_rounded;
    }

    final assignedName = conversation.assignedUserName;
    final String titleText;
    final String pillLabel;
    switch (responder.status) {
      case 'ai_active':
        titleText = '${responder.name} يتولى الرد على هذه المحادثة';
        pillLabel = 'مشغل AI';
      case 'human_active':
        titleText = responder.isTyping
            ? '${responder.name} يكتب رداً الآن...'
            : (assignedName != null && assignedName.isNotEmpty
                ? 'الموظف المسؤول: $assignedName'
                : 'الموظف المسؤول: ${responder.name}');
        pillLabel = responder.isTyping ? responder.label : 'الرد بواسطة الموظف';
      case 'human_needed':
        titleText = 'المحادثة بانتظار استلام من أحد موظفي الفريق';
        pillLabel = responder.label;
      case 'waiting':
        titleText = (assignedName != null && assignedName.isNotEmpty)
            ? 'الموظف المسؤول: $assignedName'
            : 'المحادثة متوقفة مؤقتاً بانتظار الرد';
        pillLabel = responder.label;
      default:
        titleText = 'المحادثة مغلقة أو غير متصلة';
        pillLabel = responder.label;
    }

    final canModifyActions =
        ConversationHandoffValues.isOpen(conversation) &&
        (!isWorker || conversation.canReply);
    final canTakeover =
        canModifyActions &&
        ConversationHandoffValues.canTakeoverForActor(
          conversation,
          isWorker: isWorker,
          currentUserId: currentUserId,
        );
    final canMoveToTeam =
        canModifyActions &&
        !isWorker &&
        (responder.status == 'human_active' ||
            (canTakeover && responder.status == 'ai_active'));
    final canResumeAi =
        canModifyActions &&
        ConversationHandoffValues.canResumeAiForActor(
          conversation,
          isWorker: isWorker,
          currentUserId: currentUserId,
        );

    final actionButtons = <Widget>[
      if (canTakeover)
        _buildActionButton(
          buttonKey: const Key('responder-takeover-btn'),
          label: 'تولي الرد',
          backgroundColor: const Color(0xFF2563EB),
          showSpinner: isMutating,
          onPressed: isMutating ? null : onTakeover,
        ),
      if (canMoveToTeam)
        _buildActionButton(
          label: 'تحويل للفريق',
          backgroundColor: const Color(0xFFD97706),
          onPressed: isMutating ? null : onMoveToTeam,
        ),
      if (canResumeAi)
        _buildActionButton(
          buttonKey: const Key('responder-resume-ai-btn'),
          label: 'تحويل إلى الذكاء الاصطناعي',
          backgroundColor: const Color(0xFF067E6B),
          onPressed: isMutating ? null : onResumeAi,
        ),
    ];

    final statusIcon = Container(
      width: 28,
      height: 28,
      decoration: BoxDecoration(
        color: dotColor.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Icon(iconData, size: 16, color: dotColor),
    );

    final statusDetails = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          titleText,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
            fontWeight: FontWeight.w700,
            fontSize: 11.5,
            color: textColor,
          ),
        ),
        const SizedBox(height: 1),
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 5,
              height: 5,
              decoration: BoxDecoration(
                color: dotColor,
                shape: BoxShape.circle,
              ),
            ),
            const SizedBox(width: 4),
            Flexible(
              child: Text(
                pillLabel,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                  fontSize: 9.5,
                  fontWeight: FontWeight.w600,
                  color: textColor.withValues(alpha: 0.85),
                ),
              ),
            ),
            if (responder.assignedAtHuman != null &&
                responder.assignedAtHuman!.isNotEmpty) ...[
              const SizedBox(width: 6),
              Flexible(
                child: Text(
                  '• ${responder.assignedAtHuman}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                    fontSize: 9.5,
                    color: textColor.withValues(alpha: 0.75),
                  ),
                ),
              ),
            ],
          ],
        ),
      ],
    );

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(
        color: bgColor,
        border: Border(bottom: BorderSide(color: borderColor, width: 0.8)),
      ),
      child: LayoutBuilder(
        builder: (context, constraints) {
          if (actionButtons.isEmpty) {
            return Row(
              children: [
                statusIcon,
                const SizedBox(width: 8),
                Expanded(child: statusDetails),
              ],
            );
          }
          if (constraints.maxWidth >= 600) {
            return Row(
              children: [
                statusIcon,
                const SizedBox(width: 8),
                Expanded(child: statusDetails),
                const SizedBox(width: 8),
                Flexible(
                  child: Wrap(
                    spacing: 6,
                    runSpacing: 4,
                    alignment: WrapAlignment.end,
                    children: actionButtons,
                  ),
                ),
              ],
            );
          }
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                children: [
                  statusIcon,
                  const SizedBox(width: 8),
                  Expanded(child: statusDetails),
                ],
              ),
              const SizedBox(height: 5),
              Align(
                alignment: AlignmentDirectional.centerEnd,
                child: Wrap(
                  spacing: 6,
                  runSpacing: 4,
                  alignment: WrapAlignment.end,
                  children: actionButtons,
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _buildActionButton({
    Key? buttonKey,
    required String label,
    required Color backgroundColor,
    required VoidCallback? onPressed,
    bool showSpinner = false,
  }) {
    return TextButton(
      key: buttonKey,
      onPressed: onPressed,
      style: TextButton.styleFrom(
        visualDensity: VisualDensity.compact,
        minimumSize: const Size(0, 28),
        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        backgroundColor: backgroundColor,
        foregroundColor: Colors.white,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(7)),
      ),
      child: showSpinner
          ? const SizedBox(
              width: 12,
              height: 12,
              child: CircularProgressIndicator(
                strokeWidth: 2,
                color: Colors.white,
              ),
            )
          : Text(
              label,
              style: const TextStyle(
                fontSize: 10.5,
                fontWeight: FontWeight.w700,
              ),
            ),
    );
  }
}
