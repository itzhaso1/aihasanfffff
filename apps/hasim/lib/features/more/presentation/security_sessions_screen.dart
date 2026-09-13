import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

/// الأمان والجلسات — يُفتح من «المزيد».
class SecuritySessionsScreen extends ConsumerStatefulWidget {
  const SecuritySessionsScreen({super.key});

  @override
  ConsumerState<SecuritySessionsScreen> createState() => _SecuritySessionsScreenState();
}

class _SecuritySessionsScreenState extends ConsumerState<SecuritySessionsScreen> {
  List<DeviceSessionModel> _sessions = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final sessions = await ref.read(sessionRepositoryProvider).list();
      if (mounted) setState(() { _sessions = sessions; _loading = false; });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final push = ref.watch(pushServiceProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('الأمان والجلسات')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              children: [
                ListTile(
                  title: const Text('الإشعارات الفورية'),
                  subtitle: Text(push.statusLabel),
                ),
                const Divider(),
                const Padding(
                  padding: EdgeInsets.fromLTRB(16, 8, 16, 4),
                  child: Text('الأجهزة المتصلة', style: TextStyle(fontWeight: FontWeight.w700)),
                ),
                for (final s in _sessions)
                  ListTile(
                    title: Text(s.deviceName ?? s.name),
                    subtitle: Text(s.deviceType ?? 'جهاز'),
                    trailing: s.isCurrent
                        ? const Text('هذا الجهاز')
                        : IconButton(
                            tooltip: 'إنهاء الجلسة',
                            icon: const Icon(Icons.logout),
                            onPressed: () async {
                              await ref.read(sessionRepositoryProvider).revoke(s.id);
                              await _load();
                            },
                          ),
                  ),
                TextButton(
                  onPressed: () async {
                    await ref.read(sessionRepositoryProvider).revokeOthers();
                    await _load();
                  },
                  child: const Text('إنهاء الجلسات الأخرى'),
                ),
                const Divider(),
                ListTile(
                  leading: Icon(Icons.logout, color: Theme.of(context).colorScheme.error),
                  title: Text('تسجيل الخروج', style: TextStyle(color: Theme.of(context).colorScheme.error)),
                  onTap: () async {
                    await ref.read(authControllerProvider.notifier).logout();
                    if (context.mounted) context.go('/login');
                  },
                ),
              ],
            ),
    );
  }
}
