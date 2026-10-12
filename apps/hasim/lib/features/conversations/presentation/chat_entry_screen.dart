import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/features/conversations/presentation/chat_screen.dart';
import 'package:hasim/features/conversations/presentation/conversations_screen.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_selection.dart';

/// مدخل مسار المحادثة: موبايل = شاشة كاملة، سطح المكتب = يعيد الاختيار إلى التخطيط الثلاثي.
class ChatEntryScreen extends ConsumerStatefulWidget {
  const ChatEntryScreen({
    super.key,
    required this.conversationId,
    this.initialConversation,
  });

  final int conversationId;
  final ConversationModel? initialConversation;

  @override
  ConsumerState<ChatEntryScreen> createState() => _ChatEntryScreenState();
}

class _ChatEntryScreenState extends ConsumerState<ChatEntryScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      if (!HasimLayout.useConversationSplit(context)) return;
      ref.read(desktopSelectedConversationIdProvider.notifier).state =
          widget.conversationId;
      context.go('/conversations');
    });
  }

  @override
  Widget build(BuildContext context) {
    if (HasimLayout.useConversationSplit(context)) {
      return ConversationsScreen(initialSelectedId: widget.conversationId);
    }

    return ChatScreen(
      conversationId: widget.conversationId,
      initialConversation: widget.initialConversation,
    );
  }
}
