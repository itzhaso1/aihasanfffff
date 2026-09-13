import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/widgets/async_body.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
import 'package:hasim/router/notification_deep_link.dart';

class NotificationsScreen extends ConsumerStatefulWidget {
  const NotificationsScreen({super.key});
  @override
  ConsumerState<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends ConsumerState<NotificationsScreen> {
  List<AppNotificationModel> _items = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final items = await ref.read(notificationRepositoryProvider).list();
      setState(() {
        _items = items;
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (_) {
      setState(() {
        _error = 'تعذر تحميل الإشعارات.';
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('الإشعارات'),
        actions: [
          TextButton(
            onPressed: () async {
              await ref.read(notificationRepositoryProvider).markAllRead();
              await _load();
            },
            child: const Text('قراءة الكل'),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: AsyncBody(
          loading: _loading && _items.isEmpty,
          error: _error,
          isEmpty: !_loading && _items.isEmpty,
          emptyTitle: 'لا إشعارات',
          onRetry: _load,
          child: ListView.separated(
            itemCount: _items.length,
            separatorBuilder: (_, _) => const Divider(height: 1),
            itemBuilder: (context, i) {
              final n = _items[i];
              return ListTile(
                leading: Icon(
                  n.isRead ? Icons.notifications_none : Icons.notifications_active,
                  color: Theme.of(context).colorScheme.primary,
                ),
                title: Text(n.title, style: TextStyle(fontWeight: n.isRead ? FontWeight.w500 : FontWeight.w800)),
                subtitle: Text(n.body, maxLines: 2, overflow: TextOverflow.ellipsis),
                onTap: () async {
                  await ref.read(notificationRepositoryProvider).markRead(n.id);
                  if (!context.mounted) return;
                  final stories = ref.read(storiesControllerProvider);
                  await openNotificationDeepLink(context, n, stories: stories);
                  if (!context.mounted) return;
                  await _load();
                },
              );
            },
          ),
        ),
      ),
    );
  }
}
