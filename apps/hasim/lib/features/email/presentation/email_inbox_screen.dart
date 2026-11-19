import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/email/providers/email_controller.dart';

class EmailInboxScreen extends ConsumerStatefulWidget {
  const EmailInboxScreen({super.key});

  @override
  ConsumerState<EmailInboxScreen> createState() => _EmailInboxScreenState();
}

class _EmailInboxScreenState extends ConsumerState<EmailInboxScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
    _tabController.addListener(() {
      if (_tabController.indexIsChanging) return;
      final folder = switch (_tabController.index) {
        1 => 'sent',
        2 => 'drafts',
        _ => 'inbox',
      };
      ref.read(emailFolderControllerProvider.notifier).switchFolder(folder);
    });
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(emailFolderControllerProvider);

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: const Text('البريد الإلكتروني', style: TextStyle(fontWeight: FontWeight.bold)),
        backgroundColor: HasimTokens.cardBg(context),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        actions: [
          IconButton(
            icon: const Icon(Icons.contacts_outlined),
            tooltip: 'جهات الاتصال',
            onPressed: () => context.push('/contacts'),
          ),
        ],
        bottom: TabBar(
          controller: _tabController,
          labelColor: HasimTokens.primary,
          indicatorColor: HasimTokens.primary,
          tabs: const [
            Tab(text: 'الوارد'),
            Tab(text: 'المرسل'),
            Tab(text: 'المسودات'),
          ],
        ),
      ),
      body: state.loading && state.messages.isEmpty
          ? const SkeletonList()
          : state.error != null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 24),
                        child: Text(
                          state.error!,
                          textAlign: TextAlign.center,
                          style: const TextStyle(color: Colors.grey),
                        ),
                      ),
                      const SizedBox(height: 12),
                      FilledButton(
                        onPressed: () =>
                            ref.read(emailFolderControllerProvider.notifier).init(),
                        child: const Text('إعادة المحاولة'),
                      ),
                    ],
                  ),
                )
              : RefreshIndicator(
                  onRefresh: () =>
                      ref.read(emailFolderControllerProvider.notifier).refresh(),
                  color: HasimTokens.chatGreen,
                  child: state.messages.isEmpty
                      ? const EmptyState(
                          title: 'لا توجد رسائل بريد في هذا المجلد',
                          subtitle: 'جميع الرسائل الجديدة والمحدثة ستظهر هنا.',
                        )
                      : ListView.separated(
                          padding: const EdgeInsets.symmetric(vertical: 8),
                          itemCount: state.messages.length,
                          separatorBuilder: (_, _) => Divider(
                            height: 1,
                            color: HasimTokens.lineColor(context),
                          ),
                          itemBuilder: (context, idx) {
                            final msg = state.messages[idx];
                            return ListTile(
                              tileColor: msg.isRead
                                  ? HasimTokens.cardBg(context)
                                  : HasimTokens.mintSoft.withValues(alpha: 0.35),
                              leading: CircleAvatar(
                                backgroundColor: HasimTokens.primary.withValues(alpha: 0.15),
                                child: Text(
                                  msg.sender.isNotEmpty
                                      ? msg.sender[0].toUpperCase()
                                      : 'E',
                                  style: const TextStyle(
                                    fontWeight: FontWeight.bold,
                                    color: HasimTokens.primary,
                                  ),
                                ),
                              ),
                              title: Row(
                                children: [
                                  Expanded(
                                    child: Text(
                                      msg.sender,
                                      style: TextStyle(
                                        fontWeight: msg.isRead
                                            ? FontWeight.normal
                                            : FontWeight.bold,
                                      ),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                  ),
                                  if (msg.createdAt != null)
                                    Text(
                                      msg.createdAt!.split('T').first,
                                      style: const TextStyle(
                                        fontSize: 11,
                                        color: Colors.grey,
                                      ),
                                    ),
                                ],
                              ),
                              subtitle: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    msg.displaySubject,
                                    style: TextStyle(
                                      fontWeight: msg.isRead
                                          ? FontWeight.w500
                                          : FontWeight.bold,
                                      color: Colors.black87,
                                    ),
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                  if (msg.preview != null &&
                                      msg.preview!.isNotEmpty)
                                    Text(
                                      msg.preview!,
                                      style: TextStyle(
                                        fontSize: 12,
                                        color: Colors.grey.shade600,
                                      ),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                ],
                              ),
                              trailing: IconButton(
                                icon: Icon(
                                  msg.isStarred
                                      ? Icons.star
                                      : Icons.star_border,
                                  color: msg.isStarred
                                      ? Colors.amber
                                      : Colors.grey.shade400,
                                ),
                                onPressed: () => ref
                                    .read(emailFolderControllerProvider.notifier)
                                    .toggleStar(msg.id),
                              ),
                              onTap: () {
                                ref
                                    .read(emailFolderControllerProvider.notifier)
                                    .markRead(msg.id);
                                context.push('/emails/${msg.id}', extra: msg);
                              },
                            );
                          },
                        ),
                ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: HasimTokens.chatGreen,
        onPressed: () => context.push('/emails/compose'),
        icon: const Icon(Icons.edit_outlined),
        label: const Text('إنشاء رسالة'),
      ),
    );
  }
}
