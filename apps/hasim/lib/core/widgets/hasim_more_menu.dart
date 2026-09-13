import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

/// عناصر قائمة ⋮ الأصلية في HASIM — الشكل فقط من المرجع، بدون عناصر مستوردة.
const hasimMoreMenuItems =
    <({String id, String label, IconData icon, bool danger})>[
      (
        id: 'contacts',
        label: 'جهات الاتصال',
        icon: Icons.contacts_outlined,
        danger: false,
      ),
      (
        id: 'channels',
        label: 'القنوات',
        icon: Icons.hub_outlined,
        danger: false,
      ),
      (
        id: 'plans',
        label: 'الباقة والاستخدام',
        icon: Icons.workspace_premium_outlined,
        danger: false,
      ),
      (
        id: 'settings',
        label: 'الإعدادات',
        icon: Icons.settings_outlined,
        danger: false,
      ),
      (
        id: 'security',
        label: 'الأمان والجلسات',
        icon: Icons.security_outlined,
        danger: false,
      ),
      (
        id: 'notifications',
        label: 'الإشعارات',
        icon: Icons.notifications_outlined,
        danger: false,
      ),
      (
        id: 'workspace',
        label: 'تبديل مساحة العمل',
        icon: Icons.swap_horiz,
        danger: false,
      ),
      (id: 'logout', label: 'تسجيل الخروج', icon: Icons.logout, danger: true),
    ];

/// قائمة «المزيد» العائمة من زر ⋮ في الهيدر (ليست Bottom Sheet).
Future<void> showHasimMoreMenu(BuildContext context, WidgetRef ref) async {
  final value = await _showHasimMorePopup(context);
  if (value == null || !context.mounted) return;
  switch (value) {
    case 'contacts':
      context.push('/contacts');
    case 'channels':
      context.push('/channels');
    case 'plans':
      context.push('/plans');
    case 'settings':
      context.push('/settings');
    case 'security':
      context.push('/more/security');
    case 'notifications':
      context.push('/notifications');
    case 'workspace':
      context.push('/workspaces');
    case 'logout':
      await ref.read(authControllerProvider.notifier).logout();
      if (context.mounted) context.go('/login');
  }
}

Future<String?> _showHasimMorePopup(BuildContext anchorContext) {
  final overlay = Overlay.of(
    anchorContext,
    rootOverlay: true,
  ).context.findRenderObject();
  final box = anchorContext.findRenderObject();
  if (overlay is! RenderBox || box is! RenderBox) {
    return Future.value(null);
  }

  final origin = box.localToGlobal(Offset.zero, ancestor: overlay);
  final buttonSize = box.size;
  final screen = overlay.size;
  const menuWidth = 248.0;
  const estimatedHeight = 420.0;
  const margin = 10.0;

  var left = margin;
  var top = origin.dy + buttonSize.height - 2;
  if (left + menuWidth > screen.width - margin) {
    left = screen.width - menuWidth - margin;
  }
  final maxHeight = screen.height - top - margin;
  if (top + estimatedHeight > screen.height - margin &&
      estimatedHeight < screen.height - 2 * margin) {
    top = (origin.dy - estimatedHeight + 8).clamp(
      margin,
      screen.height - estimatedHeight - margin,
    );
  }

  return showGeneralDialog<String>(
    context: anchorContext,
    useRootNavigator: true,
    barrierDismissible: true,
    barrierLabel: 'إغلاق القائمة',
    barrierColor: const Color(0x14000000),
    transitionDuration: const Duration(milliseconds: 160),
    pageBuilder: (ctx, animation, secondary) {
      return Stack(
        children: [
          Positioned(
            top: top,
            left: left,
            child: _HasimMorePopupCard(
              maxHeight: maxHeight.clamp(180, screen.height * 0.72),
              onSelect: (id) => Navigator.of(ctx).pop(id),
            ),
          ),
        ],
      );
    },
    transitionBuilder: (context, animation, secondary, child) {
      final curved = CurvedAnimation(
        parent: animation,
        curve: Curves.easeOutCubic,
      );
      return FadeTransition(
        opacity: curved,
        child: ScaleTransition(
          alignment: Alignment.topLeft,
          scale: Tween<double>(begin: 0.96, end: 1).animate(curved),
          child: child,
        ),
      );
    },
  );
}

class _HasimMorePopupCard extends StatelessWidget {
  const _HasimMorePopupCard({required this.onSelect, required this.maxHeight});

  final ValueChanged<String> onSelect;
  final double maxHeight;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDark = theme.brightness == Brightness.dark;
    final fg = isDark ? Colors.white : const Color(0xFF1A2B28);
    final regular = hasimMoreMenuItems.where((e) => !e.danger).toList();
    final danger = hasimMoreMenuItems.where((e) => e.danger).toList();

    return Directionality(
      textDirection: TextDirection.rtl,
      child: Material(
        color: Colors.transparent,
        child: SizedBox(
          width: 248,
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: isDark ? const Color(0xFF15201D) : Colors.white,
              borderRadius: BorderRadius.circular(20),
              border: Border.all(
                color: isDark
                    ? const Color(0xFF1F2E2A)
                    : const Color(0xFFE7F0ED),
              ),
              boxShadow: const [
                BoxShadow(
                  color: Color(0x14000000),
                  blurRadius: 18,
                  offset: Offset(0, 8),
                ),
              ],
            ),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(20),
              child: ConstrainedBox(
                constraints: BoxConstraints(maxHeight: maxHeight),
                child: SingleChildScrollView(
                  padding: const EdgeInsets.symmetric(
                    vertical: 6,
                    horizontal: 4,
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      for (final item in regular)
                        _HasimMoreMenuRow(
                          icon: item.icon,
                          label: item.label,
                          color: fg,
                          onTap: () => onSelect(item.id),
                        ),
                      if (danger.isNotEmpty) ...[
                        const Divider(height: 12, indent: 12, endIndent: 12),
                        for (final item in danger)
                          _HasimMoreMenuRow(
                            icon: item.icon,
                            label: item.label,
                            color: Theme.of(context).colorScheme.error,
                            onTap: () => onSelect(item.id),
                          ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _HasimMoreMenuRow extends StatelessWidget {
  const _HasimMoreMenuRow({
    required this.icon,
    required this.label,
    required this.color,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      splashColor: AppTheme.brand.withValues(alpha: 0.10),
      highlightColor: AppTheme.brand.withValues(alpha: 0.06),
      child: Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(14, 11, 16, 11),
        child: Row(
          children: [
            Icon(icon, size: 22, color: color.withValues(alpha: 0.82)),
            const SizedBox(width: 14),
            Expanded(
              child: Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.start,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w600,
                  fontSize: 14,
                  color: color,
                  height: 1.25,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
