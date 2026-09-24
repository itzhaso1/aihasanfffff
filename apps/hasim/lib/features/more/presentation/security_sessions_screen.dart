import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/core/widgets/hasim_buttons.dart';
import 'package:hasim/core/widgets/hasim_card.dart';
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
    setState(() => _loading = true);
    try {
      final sessions = await ref.read(sessionRepositoryProvider).list();
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
        HasimCard(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: AppTheme.brand.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(Icons.notifications_active_outlined, color: AppTheme.brand),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'الإشعارات الفورية',
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w700,
                            color: HasimTokens.inkColor(context),
                          ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      push.statusLabel,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: HasimTokens.mutedColor(context),
                            fontWeight: FontWeight.w500,
                          ),
                    ),
                    const SizedBox(height: 10),
                    Text(
                      'استقبال الإشعارات الفورية على أجهزتك',
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color: HasimTokens.mutedColor(context),
                          ),
                    ),
                  ],
                ),
              ),
              Switch.adaptive(value: false, onChanged: null),
            ],
          ),
        ),
        const SizedBox(height: HasimTokens.spaceMd),
        HasimCard(
          padding: const EdgeInsets.fromLTRB(20, 18, 20, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Icon(Icons.devices_other_outlined, color: HasimTokens.mutedColor(context), size: 22),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'الأجهزة المتصلة',
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w700,
                            color: HasimTokens.inkColor(context),
                          ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                'إدارة الأجهزة التي سجلت الدخول إلى حسابك',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(color: HasimTokens.mutedColor(context)),
              ),
              const SizedBox(height: 14),
              for (var i = 0; i < _sessions.length; i++) ...[
                if (i > 0) Divider(height: 1, color: HasimTokens.lineColor(context)),
                _DeviceRow(
                  session: _sessions[i],
                  icon: _deviceIcon(_sessions[i].deviceType),
                  onRevoke: _sessions[i].isCurrent
                      ? null
                      : () async {
                          await ref.read(sessionRepositoryProvider).revoke(_sessions[i].id);
                          await _load();
                        },
                ),
              ],
              const SizedBox(height: 8),
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: HasimTextLinkButton(
                  label: 'إنهاء الجلسات الأخرى',
                  onPressed: () async {
                    await ref.read(sessionRepositoryProvider).revokeOthers();
                    await _load();
                  },
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: HasimTokens.spaceMd),
        HasimCard(
          backgroundColor: HasimTokens.dangerSoft,
          borderColor: HasimTokens.danger.withValues(alpha: 0.22),
          showShadow: false,
          child: InkWell(
            onTap: () async {
              await ref.read(authControllerProvider.notifier).logout();
              if (context.mounted) context.go('/login');
            },
            borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
            child: Row(
              children: [
                Icon(Icons.logout_rounded, color: HasimTokens.danger),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'تسجيل الخروج',
                        style: Theme.of(context).textTheme.titleMedium?.copyWith(
                              fontWeight: FontWeight.w700,
                              color: HasimTokens.danger,
                            ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'تسجيل الخروج من حسابك على هذا الجهاز',
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: HasimTokens.danger.withValues(alpha: 0.85),
                            ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
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
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        children: [
          if (onRevoke != null)
            IconButton(
              tooltip: 'إنهاء الجلسة',
              onPressed: onRevoke,
              icon: Icon(Icons.logout_rounded, color: HasimTokens.mutedColor(context), size: 22),
            )
          else
            const SizedBox(width: 48),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                        fontWeight: FontWeight.w600,
                        color: HasimTokens.inkColor(context),
                      ),
                ),
                const SizedBox(height: 2),
                Text(
                  subtitle,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(color: HasimTokens.mutedColor(context)),
                ),
              ],
            ),
          ),
          if (session.isCurrent) const HasimBadge(label: 'هذا الجهاز'),
          const SizedBox(width: 8),
          Icon(icon, color: HasimTokens.mutedColor(context), size: 22),
        ],
      ),
    );
  }
}
