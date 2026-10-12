import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/app_design.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/core/widgets/hasim_buttons.dart';
import 'package:hasim/core/widgets/hasim_settings_body.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

/// الأمان والجلسات — يُفتح من الإعدادات.
class SecuritySessionsScreen extends ConsumerStatefulWidget {
  const SecuritySessionsScreen({super.key, this.embedded = false});

  final bool embedded;

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
    final repo = ref.read(sessionRepositoryProvider);
    final cached = await repo.cached();
    if (!mounted) return;
    setState(() {
      _sessions = cached;
      _loading = cached.isEmpty;
    });
    try {
      final sessions = await repo.list();
      if (mounted) setState(() { _sessions = sessions; _loading = false; });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  IconData _deviceIcon(String? type) {
    final t = (type ?? '').toLowerCase();
    if (t.contains('mobile') || t.contains('phone') || t.contains('android') || t.contains('ios')) {
      return Icons.smartphone_outlined;
    }
    if (t.contains('cashier') || t.contains('pos')) {
      return Icons.point_of_sale_outlined;
    }
    return Icons.computer_outlined;
  }

  @override
  Widget build(BuildContext context) {
    ref.listen<String>(authControllerProvider.select((s) => s.isolationKey), (previous, next) {
      if (previous != null && previous != next) _load();
    });
    final push = ref.watch(pushServiceProvider);
    final compact = HasimLayout.isCompact(context);

    if (_loading) {
      final loading = const Center(child: CircularProgressIndicator(strokeWidth: 2));
      if (widget.embedded) return loading;
      if (compact) {
        return HasimMobileScaffold(title: 'الأمان والجلسات', body: loading);
      }
      return Scaffold(
        backgroundColor: HasimTokens.pageBg(context),
        appBar: AppBar(
          title: const Text('الأمان والجلسات'),
          backgroundColor: HasimTokens.pageBg(context),
          surfaceTintColor: Colors.transparent,
        ),
        body: loading,
      );
    }

    final body = HasimSettingsBody(
      mobileLayout: compact && !widget.embedded,
      children: [
        AppSettingsTile(
          icon: Icons.notifications_active_outlined,
          title: 'الإشعارات الفورية',
          subtitle: '${push.statusLabel}\nاستقبال الإشعارات الفورية على جهازك',
          trailing: const AppSwitch(value: false, onChanged: null),
        ),
        const SizedBox(height: 12),
        const AppSectionHeader(
          title: 'الأجهزة المتصلة',
          subtitle: 'إدارة الأجهزة التي سجلت الدخول إلى حسابك',
        ),
        for (final session in _sessions)
          _DeviceRow(
            session: session,
            icon: _deviceIcon(session.deviceType),
            onRevoke: session.isCurrent
                ? null
                : () async {
                    await ref.read(sessionRepositoryProvider).revoke(session.id);
                    await _load();
                  },
          ),
        Align(
          alignment: AlignmentDirectional.centerStart,
          child: HasimTextLinkButton(
            label: 'إنهاء الجلسات الأخرى',
            icon: Icons.devices_other_outlined,
            onPressed: () async {
              await ref.read(sessionRepositoryProvider).revokeOthers();
              await _load();
            },
          ),
        ),
        const SizedBox(height: 18),
        AppSettingsTile(
          icon: Icons.logout_rounded,
          title: 'تسجيل الخروج',
          subtitle: 'تسجيل الخروج من حسابك على هذا الجهاز',
          danger: true,
          onTap: () async {
            await ref.read(authControllerProvider.notifier).logout();
            if (context.mounted) context.go('/login');
          },
        ),
      ],
    );

    if (widget.embedded) return body;

    if (compact) {
      return HasimMobileScaffold(title: 'الأمان والجلسات', body: body);
    }

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: const Text('الأمان والجلسات'),
        backgroundColor: HasimTokens.pageBg(context),
        surfaceTintColor: Colors.transparent,
      ),
      body: body,
    );
  }
}

class _DeviceRow extends StatelessWidget {
  const _DeviceRow({
    required this.session,
    required this.icon,
    required this.onRevoke,
  });

  final DeviceSessionModel session;
  final IconData icon;
  final VoidCallback? onRevoke;

  @override
  Widget build(BuildContext context) {
    final title = session.deviceName ?? session.name;
    final subtitle = session.deviceType ?? 'جهاز';

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 12),
      child: Row(
        children: [
          AppIconContainer(icon: icon),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: AppTextStyles.body(color: HasimTokens.inkColor(context)),
                ),
                const SizedBox(height: 2),
                Text(
                  subtitle,
                  style: AppTextStyles.secondary(color: HasimTokens.mutedColor(context)),
                ),
              ],
            ),
          ),
          if (session.isCurrent) const HasimBadge(label: 'هذا الجهاز'),
          if (onRevoke != null)
            IconButton(
              tooltip: 'إنهاء الجلسة',
              onPressed: onRevoke,
              icon: Icon(Icons.close_rounded, color: HasimTokens.mutedColor(context), size: 20),
            ),
        ],
      ),
    );
  }
}
