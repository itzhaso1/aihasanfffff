import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/utils/relative_time.dart';
import 'package:hasim/core/widgets/async_body.dart';
import 'package:hasim/core/widgets/hasim_shell_header.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/conversations/presentation/widgets/conversation_filter_chip.dart';
import 'package:hasim/features/conversations/presentation/widgets/inbox_empty_illustration.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/home/providers/home_controller.dart';

/// الشاشة الأولى بعد الدخول — محادثات فقط (بحث + فلاتر + قائمة).
class ConversationsScreen extends ConsumerStatefulWidget {
  const ConversationsScreen({super.key});
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
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(conversationsControllerProvider);
    final notifier = ref.read(conversationsControllerProvider.notifier);
    final theme = Theme.of(context);
    final mint = Color(AppConfig.brand.surface);

    return Scaffold(
      backgroundColor: mint,
      appBar: HasimShellHeader(backgroundColor: mint),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(28),
                boxShadow: const [
                  BoxShadow(
                    color: Color(0x14000000),
                    blurRadius: 10,
                    offset: Offset(0, 2),
                  ),
                ],
              ),
              child: TextField(
                controller: _search,
                textInputAction: TextInputAction.search,
                decoration: InputDecoration(
                  hintText: 'ابحث في المحادثات',
                  hintStyle: TextStyle(
                    color: theme.colorScheme.onSurface.withValues(alpha: 0.38),
                    fontWeight: FontWeight.w500,
                  ),
                  prefixIcon: Icon(
                    Icons.search_rounded,
                    color: theme.colorScheme.onSurface.withValues(alpha: 0.45),
                  ),
                  filled: true,
                  fillColor: Colors.white,
                  isDense: true,
                  contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(28),
                    borderSide: BorderSide.none,
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(28),
                    borderSide: BorderSide.none,
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(28),
                    borderSide: const BorderSide(color: AppTheme.brand, width: 1.2),
                  ),
                ),
                onSubmitted: notifier.setSearch,
                onChanged: (v) {
                  if (v.isEmpty) notifier.setSearch('');
                },
              ),
            ),
          ),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 4),
            child: Row(
              children: [
                for (final f in _filters) ...[
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
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(16, 6, 16, 8),
            child: Row(
              children: [
                for (final c in _channels) ...[
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
          Expanded(
            child: RefreshIndicator(
              color: AppTheme.brand,
              onRefresh: () async {
                await notifier.refresh();
                await ref.read(homeControllerProvider.notifier).refresh();
              },
              child: state.loading && state.items.isEmpty
                  ? const SkeletonList()
                  : AsyncBody(
                      loading: false,
                      error: state.error,
                      isEmpty: !state.loading && state.items.isEmpty,
                      emptyTitle: 'لا توجد محادثات حاليًا',
                      emptySubtitle: 'ستظهر هنا رسائل واتساب والويب والقنوات الموحدة.',
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
                        child: ListView.separated(
                          itemCount: state.items.length + (state.loadingMore ? 1 : 0),
                          separatorBuilder: (_, _) => Divider(
                            height: 1,
                            color: theme.dividerColor.withValues(alpha: 0.5),
                          ),
                          itemBuilder: (context, index) {
                            if (index >= state.items.length) {
                              return const Padding(
                                padding: EdgeInsets.all(16),
                                child: Center(child: CircularProgressIndicator()),
                              );
                            }
                            final c = state.items[index];
                            final time = relativeTimeAr(c.lastMessageAt);
                            final unread = c.unreadCount > 0;
                            return ListTile(
                              contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                              onTap: () => context.push('/conversations/${c.id}'),
                              leading: CircleAvatar(
                                backgroundColor: theme.colorScheme.primary.withValues(alpha: 0.15),
                                child: Text(
                                  (c.title.isNotEmpty ? c.title[0] : '#'),
                                  style: TextStyle(
                                    color: theme.colorScheme.primary,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ),
                              title: Row(
                                children: [
                                  Expanded(
                                    child: Text(
                                      c.title,
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: TextStyle(
                                        fontWeight: unread ? FontWeight.w800 : FontWeight.w600,
                                      ),
                                    ),
                                  ),
                                  Text(
                                    time,
                                    style: theme.textTheme.labelSmall?.copyWith(
                                      color: unread
                                          ? theme.colorScheme.primary
                                          : theme.colorScheme.onSurfaceVariant,
                                    ),
                                  ),
                                ],
                              ),
                              subtitle: Row(
                                children: [
                                  Icon(_channelIcon(c.channel), size: 14, color: theme.colorScheme.onSurfaceVariant),
                                  const SizedBox(width: 6),
                                  Expanded(
                                    child: Text(
                                      c.preview,
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: TextStyle(
                                        fontWeight: unread ? FontWeight.w600 : FontWeight.w400,
                                      ),
                                    ),
                                  ),
                                  if (unread)
                                    Padding(
                                      padding: const EdgeInsets.only(right: 4),
                                      child: CircleAvatar(
                                        radius: 11,
                                        backgroundColor: theme.colorScheme.primary,
                                        child: Text(
                                          '${c.unreadCount}',
                                          style: const TextStyle(color: Colors.white, fontSize: 11),
                                        ),
                                      ),
                                    ),
                                ],
                              ),
                            );
                          },
                        ),
                      ),
                    ),
            ),
          ),
        ],
      ),
    );
  }

  IconData _channelIcon(String channel) {
    return switch (channel) {
      'whatsapp' => Icons.chat,
      'email' => Icons.mail_outline,
      'web' => Icons.language,
      _ => Icons.forum_outlined,
    };
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
