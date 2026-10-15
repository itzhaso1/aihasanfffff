import 'dart:async';
import 'dart:io';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/network/media_auth_headers.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
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
import 'package:hasim/features/conversations/presentation/widgets/chat_message_bubble.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_wallpaper.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_channel_avatar.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_classification_sheet.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_selection.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/privacy/presentation/locked_chats_navigation.dart';
import 'package:hasim/features/privacy/presentation/pin_prompt.dart';
import 'package:hasim/features/privacy/providers/chat_lock_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
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
  bool _stickToBottom = true;
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

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    final initial = widget.initialConversation;
    if (initial != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        ref
            .read(chatControllerProvider(widget.conversationId).notifier)
            .seedConversation(initial);
      });
    }
  }

  @override
  void didUpdateWidget(covariant ChatScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.conversationId != widget.conversationId) {
      _suggestGeneration++;
      _openedAtBottom = false;
      _stickToBottom = true;
      _emojiPickerOpen = false;
      _attachMenuOpen = false;
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
    if (atLatest != _stickToBottom) {
      setState(() => _stickToBottom = atLatest);
    }
  }

  void _onScrollToBottomTap() {
    _scrollToLatest(frames: 2);
    setState(() => _stickToBottom = true);
  }

  @override
  void dispose() {
    _voiceTick?.cancel();
    _voiceAmplitudeSub?.cancel();
    unawaited(_voiceRecorder?.cancel());
    unawaited(_voiceRecorder?.dispose());
    _scroll.removeListener(_onScroll);
    _controller.dispose();
    _messageSearch.dispose();
    _composerFocus.dispose();
    _scroll.dispose();
    super.dispose();
  }

  void _scrollToLatest({int frames = 1, bool reveal = false}) {
    void tick(int remaining) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        if (_scroll.hasClients) {
          _scroll.jumpTo(0);
        }
        if (remaining > 0) {
          tick(remaining - 1);
        } else if (reveal) {
          setState(() => _stickToBottom = true);
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

  DateTime? _lastSendAt;
  String? _lastSendText;
  List<MessageModel>? _cachedRowsSource;
  String? _cachedRowsQuery;
  List<_ChatRow> _cachedRows = const [];

  Future<void> _send() async {
    final currentConversation =
        ref.read(chatControllerProvider(widget.conversationId)).conversation ??
        widget.initialConversation;
    if (currentConversation?.canReply == false) {
      return;
    }
    final text = _controller.text;
    if (text.trim().isEmpty) return;
    final now = DateTime.now();
    if (_lastSendText == text &&
        _lastSendAt != null &&
        now.difference(_lastSendAt!) < const Duration(milliseconds: 400)) {
      return;
    }
    _lastSendAt = now;
    _lastSendText = text;
    _controller.clear();
    _keepComposerFocus();
    _stickToBottom = true;
    final accepted = await ref
        .read(chatControllerProvider(widget.conversationId).notifier)
        .send(text);
    if (!mounted) return;
    if (!accepted) {
      _controller.value = TextEditingValue(
        text: text,
        selection: TextSelection.collapsed(offset: text.length),
      );
      final message =
          ref.read(chatControllerProvider(widget.conversationId)).error ??
          'تعذر إرسال الرسالة.';
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(message)));
    }
    _keepComposerFocus();
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
    _stickToBottom = true;
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
        if (conversation != null &&
            isCompanyUser &&
            ConversationHandoffValues.canEndHandoff(conversation))
          const PopupMenuItem(
            key: Key('chat-action-end-handoff'),
            value: 'end_handoff',
            child: Text('إنهاء التحويل'),
          ),
        if (conversation != null &&
            isCompanyUser &&
            ConversationHandoffValues.canResumeAi(conversation))
          const PopupMenuItem(
            key: Key('chat-action-resume-ai'),
            value: 'resume_ai',
            child: Text('تحويل إلى AI'),
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
    if (selected == 'end_handoff' || selected == 'resume_ai') {
      final notifier = ref.read(
        chatControllerProvider(widget.conversationId).notifier,
      );
      final wasMutating = ref
          .read(chatControllerProvider(widget.conversationId))
          .mutating;
      final Future<String?> request;
      if (selected == 'resume_ai') {
        request = notifier.resumeAi();
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
    File? file;
    switch (action) {
      case 'photos_videos':
        final media = await ImagePicker().pickMedia(imageQuality: 85);
        if (media != null) file = File(media.path);
      case 'gallery':
        final picked = await ImagePicker().pickImage(
          source: ImageSource.gallery,
          imageQuality: 85,
        );
        if (picked != null) file = File(picked.path);
      case 'camera':
        try {
          final picked = await ImagePicker().pickImage(
            source: ImageSource.camera,
            imageQuality: 85,
          );
          if (picked != null) file = File(picked.path);
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
          allowedExtensions: ChatAttachmentPolicy.allowedExtensions.toList()
            ..sort(),
        );
        final path = result?.files.single.path;
        if (path != null) file = File(path);
    }
    if (file == null || !mounted) return;
    _stickToBottom = true;
    final err = await ref
        .read(chatControllerProvider(widget.conversationId).notifier)
        .sendAttachment(file);
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
    final headers = await ref.read(mediaAuthHeadersProvider.future);
    if (!mounted) return;
    showDialog<void>(
      context: context,
      builder: (context) => Dialog(
        insetPadding: const EdgeInsets.all(16),
        child: InteractiveViewer(
          child: CachedNetworkImage(
            imageUrl: url,
            httpHeaders: headers,
            fit: BoxFit.contain,
          ),
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

  List<_ChatRow> _rowsFor(List<MessageModel> messages, String query) {
    if (identical(_cachedRowsSource, messages) && _cachedRowsQuery == query) {
      return _cachedRows;
    }
    final visibleMessages = query.isEmpty
        ? messages
        : messages.where((m) => m.content.contains(query)).toList();
    _cachedRowsSource = messages;
    _cachedRowsQuery = query;
    _cachedRows = _rows(visibleMessages);
    return _cachedRows;
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
      if (next.messages.isEmpty) return;

      final prevLen = previous?.messages.length ?? 0;
      final grew = next.messages.length > prevLen;
      if (grew && _stickToBottom) {
        _scrollToLatest(frames: 1);
      }

      if (!_openedAtBottom && next.messages.isNotEmpty) {
        _openedAtBottom = true;
        _stickToBottom = true;
        _scrollToLatest(frames: 2);
      }
    });

    final state = ref.watch(chatControllerProvider(widget.conversationId));
    final l10n = AppLocalizations.of(context);
    final title = state.conversation?.title ?? 'محادثة';
    final query = _messageSearch.text.trim();
    final rows = _rowsFor(state.messages, query);
    final conversation = state.conversation ?? widget.initialConversation;
    final initial = title.isNotEmpty ? title.characters.first : '#';
    final headerBg = HasimTokens.cardBg(context);

    Widget titleBlock() {
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
                    style: const TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w700,
                    ),
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
      return InkWell(
        key: Key(
          widget.embedded ? 'desktop-customer-header' : 'chat-customer-header',
        ),
        onTap: _openCustomerFile,
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
        shape: Border(
          bottom: BorderSide(color: HasimTokens.lineColor(context)),
        ),
        bottom: _findingMessages
            ? PreferredSize(
                preferredSize: const Size.fromHeight(52),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
                  child: TextField(
                    controller: _messageSearch,
                    autofocus: true,
                    onChanged: (_) => setState(() {}),
                    decoration: InputDecoration(
                      hintText: 'بحث في هذه المحادثة',
                      isDense: true,
                      suffixIcon: IconButton(
                        onPressed: () {
                          _messageSearch.clear();
                          setState(() => _findingMessages = false);
                        },
                        icon: const Icon(Icons.close),
                      ),
                    ),
                  ),
                ),
              )
            : null,
        title: titleBlock(),
        actions: [
          if (widget.embedded)
            IconButton(
              key: const Key('desktop-customer-info'),
              tooltip: 'معلومات العميل',
              onPressed: () =>
                  ref.read(desktopCustomerDrawerOpenProvider.notifier).state =
                      true,
              icon: Icon(
                Icons.info_outline_rounded,
                color: HasimTokens.mutedColor(context),
              ),
            ),
          PopupMenuButton<String>(
            key: _chatMenuKey,
            enabled: !state.mutating,
            icon: Icon(
              Icons.more_vert_rounded,
              color: HasimTokens.mutedColor(context),
            ),
            offset: const Offset(0, 44),
            color: HasimTokens.cardBg(context),
            elevation: 8,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(16),
            ),
            onSelected: (v) => _onChatMenuSelected(context, v, conversation),
            itemBuilder: (_) => [
              const PopupMenuItem(
                value: 'add_contact',
                child: Text('إضافة إلى جهات الاتصال'),
              ),
              const PopupMenuItem(value: 'search', child: Text('بحث')),
              const PopupMenuItem(
                value: 'add_list',
                child: Text('إضافة إلى القائمة'),
              ),
              const PopupMenuItem(
                value: 'media',
                child: Text('وسائط وروابط ومستندات'),
              ),
              const PopupMenuItem(
                value: 'disappearing',
                child: Text('الرسائل ذاتية الاختفاء'),
              ),
              const PopupMenuItem(
                key: Key('chat-action-themes'),
                value: 'themes',
                child: Text('سمة الدردشة'),
              ),
              const PopupMenuItem(
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
      ),
      body: Stack(
        children: [
          Positioned.fill(
            child: ChatWallpaper(conversationId: widget.conversationId),
          ),
          Column(
            children: [
              if (state.loadingOlder)
                const LinearProgressIndicator(minHeight: 2),
              if (state.aiBusy || state.mutating)
                const LinearProgressIndicator(minHeight: 2),
              Expanded(
                child: state.loading && state.messages.isEmpty
                    ? const SkeletonList()
                    : AsyncBody(
                        loading: false,
                        error: state.error != null && state.messages.isEmpty
                            ? state.error
                            : null,
                        isEmpty: !state.loading && state.messages.isEmpty,
                        emptyTitle: 'ابدأ المحادثة',
                        emptySubtitle: 'لا توجد رسائل بعد.',
                        onRetry: () => ref
                            .read(
                              chatControllerProvider(
                                widget.conversationId,
                              ).notifier,
                            )
                            .refresh(),
                        child: LayoutBuilder(
                          builder: (context, constraints) {
                            final compact = HasimLayout.isCompact(context);
                            final bubbleCap =
                                constraints.maxWidth * (compact ? 0.78 : 0.72);
                            final attachDock = compact && _attachMenuOpen
                                ? ChatAttachmentGridPanel.heightFor(context)
                                : 0.0;
                            final emojiDock = _phoneEmojiDock(context);
                            final composerPad =
                                ChatComposer.floatingOuterHeight +
                                8 +
                                attachDock +
                                emojiDock;
                            final list = ListView.builder(
                              controller: _scroll,
                              reverse: true,
                              padding: EdgeInsets.fromLTRB(
                                compact ? 10 : 16,
                                8,
                                compact ? 10 : 16,
                                composerPad,
                              ),
                              itemCount: rows.length,
                              itemBuilder: (context, index) {
                                final row = rows[rows.length - 1 - index];
                                if (row.date != null) {
                                  return ChatDateChip(
                                    key: ValueKey(
                                      'chat-date-${row.date!.millisecondsSinceEpoch}',
                                    ),
                                    date: row.date!,
                                  );
                                }
                                final m = row.message!;
                                return RepaintBoundary(
                                  key: ValueKey(
                                    'chat-message-${m.id}-${m.clientId ?? ''}',
                                  ),
                                  child: SizedBox(
                                    width: double.infinity,
                                    child: ChatMessageBubble(
                                      message: m,
                                      maxWidth: bubbleCap,
                                      onRetry: m.localFailed
                                          ? () => ref
                                                .read(
                                                  chatControllerProvider(
                                                    widget.conversationId,
                                                  ).notifier,
                                                )
                                                .retryFailed(m)
                                          : null,
                                      onImageTap: _openImage,
                                      onMediaUrlExpired: _refreshMessages,
                                      onOpenFailed: () {
                                        if (!mounted) return;
                                        ScaffoldMessenger.of(
                                          context,
                                        ).showSnackBar(
                                          SnackBar(
                                            content: Text(
                                              l10n.chatAttachmentOpenFailed,
                                            ),
                                          ),
                                        );
                                      },
                                    ),
                                  ),
                                );
                              },
                            );
                            return list;
                          },
                        ),
                      ),
              ),
            ],
          ),
          if (conversation?.canReply != false)
            Positioned(
              left: 0,
              right: 0,
              bottom:
                  (HasimLayout.isCompact(context) && _attachMenuOpen
                      ? ChatAttachmentGridPanel.heightFor(context)
                      : 0) +
                  _phoneEmojiDock(context),
              child: ChatComposer(
                controller: _controller,
                focusNode: _composerFocus,
                emojiButtonKey: _emojiButtonKey,
                attachButtonKey: _attachButtonKey,
                sendEnabled: !state.sending,
                recording: _recordingVoice,
                recordElapsed: _voiceElapsed,
                emojiAsKeyboard:
                    HasimLayout.isCompact(context) && _emojiPickerOpen,
                bottomSafe:
                    !((HasimLayout.isCompact(context) && _attachMenuOpen) ||
                        _phoneEmojiDock(context) > 0),
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
              ),
            ),
          if (!_stickToBottom &&
              rows.isNotEmpty &&
              !(state.loading && state.messages.isEmpty))
            PositionedDirectional(
              key: const Key('chat-scroll-to-bottom'),
              end: HasimLayout.isCompact(context) ? 12 : 20,
              bottom:
                  ChatComposer.floatingOuterHeight +
                  10 +
                  (HasimLayout.isCompact(context) && _attachMenuOpen
                      ? ChatAttachmentGridPanel.heightFor(context)
                      : 0) +
                  _phoneEmojiDock(context),
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
          if (_attachMenuOpen && HasimLayout.isCompact(context))
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: ChatAttachmentGridPanel(onSelected: _onAttachMenuSelected),
            ),
          if (_attachMenuOpen && !HasimLayout.isCompact(context))
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
          if (_emojiPickerOpen && HasimLayout.isCompact(context))
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              height: _phoneEmojiDock(context),
              child: ChatEmojiPickerPanel(
                docked: true,
                onEmojiSelected: _insertEmojiCharacter,
                onClose: () {
                  setState(() => _emojiPickerOpen = false);
                  _composerFocus.requestFocus();
                },
              ),
            ),
          if (_emojiPickerOpen && !HasimLayout.isCompact(context))
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
