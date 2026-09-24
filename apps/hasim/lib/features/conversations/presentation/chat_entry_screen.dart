import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/widgets/hasim_adaptive_scaffold.dart';
import 'package:hasim/core/widgets/hasim_bottom_nav.dart';
import 'package:hasim/features/conversations/presentation/chat_screen.dart';
import 'package:hasim/features/conversations/presentation/conversations_screen.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_selection.dart';
import 'package:hasim/features/home/providers/home_controller.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
import 'package:hasim/router/hasim_nav.dart';

/// مدخل مسار المحادثة: موبايل = شاشة كاملة، سطح المكتب = يعيد الاختيار إلى التخطيط الثلاثي.
class ChatEntryScreen extends ConsumerStatefulWidget {
  const ChatEntryScreen({super.key, required this.conversationId});

  final int conversationId;

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
      ref.read(desktopSelectedConversationIdProvider.notifier).state = widget.conversationId;
      context.go('/conversations');
    });
  }

  @override
  Widget build(BuildContext context) {
    if (HasimLayout.useConversationSplit(context)) {
      final snap = ref.watch(homeControllerProvider).snapshot;
      final stories = ref.watch(storiesControllerProvider);
      return HasimAdaptiveScaffold(
        selectedIndex: HasimNav.conversationsIndex,
        onDestinationSelected: (index) => HasimAdaptiveScaffold.goBranch(context, index),
        items: hasimBottomNavItems(
          chatBadge: snap?.unreadConversations ?? 0,
          emailBadge: snap?.unreadEmail ?? 0,
          updatesBadge: stories.unviewedCount,
        ),
        body: ConversationsScreen(initialSelectedId: widget.conversationId),
      );
    }

    return ChatScreen(conversationId: widget.conversationId);
  }
}
