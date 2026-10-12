import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/utils/greeting.dart';
import 'package:hasim/core/utils/relative_time.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/home/providers/home_controller.dart';
import 'package:hasim/features/privacy/providers/chat_lock_controller.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    final home = ref.watch(homeControllerProvider);
    final snap = home.snapshot;
    final greeting = greetingFor();

    return Scaffold(
      appBar: AppBar(
        title: const Text('نشاط اليوم'),
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          await ref.read(homeControllerProvider.notifier).refresh();
          await ref.read(storiesControllerProvider.notifier).refresh();
        },
        child: home.loading && snap == null
            ? const SkeletonCards()
            : ListView(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
                children: [
                  if (home.error != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Text(home.error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                    ),
                  Text(
                    '$greeting ${auth.user?.name ?? ''}',
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    auth.workspace?.name ?? 'حاسم',
                    style: TextStyle(color: Colors.grey.shade700),
                  ),
                  const SizedBox(height: 16),
                  Wrap(
                    spacing: 10,
                    runSpacing: 10,
                    children: [
                      _StatChip(
                        title: 'محادثات',
                        value: '${snap?.unreadConversations ?? 0}',
                        icon: Icons.chat_bubble_outline,
                        onTap: () => context.go('/conversations'),
                      ),
                      _StatChip(
                        title: 'بريد',
                        value: '${snap?.unreadEmail ?? 0}',
                        icon: Icons.mail_outline,
                        onTap: () => context.go('/email'),
                      ),
                      _StatChip(
                        title: 'إشعارات',
                        value: '${snap?.unreadNotifications ?? 0}',
                        icon: Icons.notifications_active_outlined,
                        onTap: () => context.push('/notifications'),
                      ),
                    ],
                  ),
                  const SizedBox(height: 22),
                  Text('إجراءات سريعة', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 10),
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: [
                        _QuickAction(label: 'محادثات', icon: Icons.forum_outlined, onTap: () => context.go('/conversations')),
                        _QuickAction(label: 'بريد جديد', icon: Icons.edit_outlined, onTap: () => context.push('/email/compose')),
                        _QuickAction(label: 'جهات الاتصال', icon: Icons.contacts_outlined, onTap: () => context.push('/contacts')),
                        _QuickAction(label: 'تحديث', icon: Icons.auto_stories_outlined, onTap: () => context.push('/stories/create')),
                        _QuickAction(label: 'القنوات', icon: Icons.hub_outlined, onTap: () => context.push('/channels')),
                      ],
                    ),
                  ),
                  const SizedBox(height: 22),
                  Text('أحدث المحادثات', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 8),
                  if (home.recentConversations.isEmpty)
                    Text('لا توجد محادثات حديثة', style: TextStyle(color: Colors.grey.shade600))
                  else
                    ...home.recentConversations
                        .where((c) => !ref.watch(lockedConversationIdsProvider).contains(c.id))
                        .map(
                      (c) => ListTile(
                        contentPadding: EdgeInsets.zero,
                        onTap: () => context.push('/conversations/${c.id}'),
                        leading: CircleAvatar(
                          backgroundColor: Theme.of(context).colorScheme.primary.withValues(alpha: 0.12),
                          child: Text(c.title.isNotEmpty ? c.title[0] : '#'),
                        ),
                        title: Text(c.title, maxLines: 1, overflow: TextOverflow.ellipsis),
                        subtitle: Text(c.preview, maxLines: 1, overflow: TextOverflow.ellipsis),
                        trailing: Text(relativeTimeAr(c.lastMessageAt), style: TextStyle(fontSize: 12, color: Colors.grey.shade600)),
                      ),
                    ),
                ],
              ),
      ),
    );
  }
}

class _StatChip extends StatelessWidget {
  const _StatChip({
    required this.title,
    required this.value,
    required this.icon,
    required this.onTap,
  });

  final String title;
  final String value;
  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final primary = Theme.of(context).colorScheme.primary;
    return Material(
      color: Theme.of(context).cardTheme.color ?? Colors.white,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: Container(
          width: 150,
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: Colors.black.withValues(alpha: 0.05)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, color: primary, size: 22),
              const SizedBox(height: 10),
              Text(value, style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800, color: primary)),
              Text(title, style: TextStyle(fontSize: 12, color: Colors.grey.shade700)),
            ],
          ),
        ),
      ),
    );
  }
}

class _QuickAction extends StatelessWidget {
  const _QuickAction({required this.label, required this.icon, required this.onTap});
  final String label;
  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsetsDirectional.only(end: 8),
      child: ActionChip(
        avatar: Icon(icon, size: 18),
        label: Text(label),
        onPressed: onTap,
      ),
    );
  }
}
