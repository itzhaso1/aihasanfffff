import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_selection.dart';

/// خيارات الضغط المطوّل (الجوال) أو الزر الأيمن (الكمبيوتر) على محادثة أو رسالة.
enum ConversationContextAction {
  reply,
  copy,
  pin,
  star,
  addToNote,
  select,
  report,
  delete,
}

class ConversationContextMenuItem {
  const ConversationContextMenuItem({
    required this.action,
    required this.label,
    required this.icon,
    this.danger = false,
  });

  final ConversationContextAction action;
  final String label;
  final IconData icon;
  final bool danger;
}

/// الترتيب ثابت: رد، نسخ، تثبيت، تمييز بنجمة، إضافة النص إلى الملاحظة، تحديد، إبلاغ، حذف.
List<ConversationContextMenuItem> conversationContextMenuItems({
  required bool pinned,
  required bool starred,
}) {
  return [
    const ConversationContextMenuItem(
      action: ConversationContextAction.reply,
      label: 'رد',
      icon: Icons.reply_rounded,
    ),
    const ConversationContextMenuItem(
      action: ConversationContextAction.copy,
      label: 'نسخ',
      icon: Icons.copy_rounded,
    ),
    ConversationContextMenuItem(
      action: ConversationContextAction.pin,
      label: pinned ? 'إلغاء التثبيت' : 'تثبيت',
      icon: pinned ? Icons.push_pin : Icons.push_pin_outlined,
    ),
    ConversationContextMenuItem(
      action: ConversationContextAction.star,
      label: starred ? 'إزالة النجمة' : 'تمييز بنجمة',
      icon: starred ? Icons.star_rounded : Icons.star_outline_rounded,
    ),
    const ConversationContextMenuItem(
      action: ConversationContextAction.addToNote,
      label: 'إضافة النص إلى الملاحظة',
      icon: Icons.note_add_outlined,
    ),
    const ConversationContextMenuItem(
      action: ConversationContextAction.select,
      label: 'تحديد',
      icon: Icons.check_circle_outline_rounded,
    ),
    const ConversationContextMenuItem(
      action: ConversationContextAction.report,
      label: 'إبلاغ',
      icon: Icons.flag_outlined,
    ),
    const ConversationContextMenuItem(
      action: ConversationContextAction.delete,
      label: 'حذف',
      icon: Icons.delete_outline_rounded,
      danger: true,
    ),
  ];
}

/// على الجوال: لوحة سفلية. على الكمبيوتر: قائمة عائمة عند موضع المؤشر.
Future<ConversationContextAction?> showConversationContextMenu({
  required BuildContext context,
  Offset? globalPosition,
  bool pinned = false,
  bool starred = false,
  String? preview,
}) {
  final items = conversationContextMenuItems(pinned: pinned, starred: starred);
  if (HasimLayout.isCompact(context)) {
    return _showCompactMenu(context, items: items, preview: preview);
  }
  return _showDesktopMenu(
    context,
    items: items,
    globalPosition: globalPosition,
  );
}

Future<ConversationContextAction?> _showCompactMenu(
  BuildContext context, {
  required List<ConversationContextMenuItem> items,
  String? preview,
}) {
  final previewText = preview?.replaceAll(RegExp(r'\s+'), ' ').trim() ?? '';
  return showModalBottomSheet<ConversationContextAction>(
    context: context,
    useRootNavigator: true,
    isScrollControlled: true,
    backgroundColor: HasimTokens.cardBg(context),
    shape: RoundedRectangleBorder(
      borderRadius: HasimLayout.modalSheetRadius(context),
    ),
    builder: (ctx) {
      return SafeArea(
        child: ConstrainedBox(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.sizeOf(ctx).height * 0.8,
          ),
          child: Padding(
            key: const Key('conversation-context-menu'),
            padding: const EdgeInsets.fromLTRB(8, 10, 8, 8),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 36,
                  height: 4,
                  decoration: BoxDecoration(
                    color: HasimTokens.lineColor(ctx),
                    borderRadius: BorderRadius.circular(99),
                  ),
                ),
                if (previewText.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                    child: Text(
                      previewText,
                      key: const Key('conversation-context-preview'),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      textAlign: TextAlign.start,
                      style: Theme.of(ctx).textTheme.bodySmall?.copyWith(
                        color: HasimTokens.mutedColor(ctx),
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                const SizedBox(height: 6),
                Flexible(
                  child: SingleChildScrollView(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        for (final item in items) ...[
                          if (item.danger)
                            Divider(
                              height: 10,
                              indent: 12,
                              endIndent: 12,
                              color: HasimTokens.lineColor(ctx),
                            ),
                          _ConversationContextMenuRow(
                            item: item,
                            onTap: () => Navigator.pop(ctx, item.action),
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    },
  );
}

Future<ConversationContextAction?> _showDesktopMenu(
  BuildContext context, {
  required List<ConversationContextMenuItem> items,
  Offset? globalPosition,
}) {
  final overlay = Overlay.of(
    context,
    rootOverlay: true,
  ).context.findRenderObject();
  if (overlay is! RenderBox) {
    return Future.value(null);
  }
  final box = context.findRenderObject();
  final anchor =
      globalPosition ??
      (box is RenderBox && box.hasSize
          ? box.localToGlobal(Offset(box.size.width, box.size.height / 2))
          : const Offset(24, 72));
  final origin = overlay.globalToLocal(anchor);
  final screen = overlay.size;
  const menuWidth = _desktopMenuWidth;
  const estimatedHeight = 392.0;
  const margin = 10.0;

  // RTL: القائمة تفتح يسار المؤشر، وتنقلب يمينًا إن لم تتسع.
  var left = origin.dx - menuWidth;
  if (left < margin) {
    left = origin.dx;
  }
  left = left.clamp(margin, (screen.width - menuWidth - margin).clamp(margin, double.infinity));
  var top = origin.dy;
  if (top + estimatedHeight > screen.height - margin) {
    top = origin.dy - estimatedHeight;
  }
  top = top.clamp(margin, (screen.height - estimatedHeight - margin).clamp(margin, double.infinity));
  final maxHeight = math.max(160.0, screen.height - top - margin);

  return showGeneralDialog<ConversationContextAction>(
    context: context,
    useRootNavigator: true,
    barrierDismissible: true,
    barrierLabel: 'إغلاق القائمة',
    barrierColor: Colors.transparent,
    transitionDuration: const Duration(milliseconds: 120),
    pageBuilder: (ctx, animation, secondary) {
      return Stack(
        children: [
          Positioned(
            top: top,
            left: left,
            child: _ConversationContextPopupCard(
              items: items,
              maxHeight: maxHeight,
              onSelect: (action) => Navigator.of(ctx).pop(action),
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
          alignment: Alignment.topRight,
          scale: Tween<double>(begin: 0.96, end: 1).animate(curved),
          child: child,
        ),
      );
    },
  );
}

const double _desktopMenuWidth = 264;

class _ConversationContextPopupCard extends StatelessWidget {
  const _ConversationContextPopupCard({
    required this.items,
    required this.onSelect,
    required this.maxHeight,
  });

  final List<ConversationContextMenuItem> items;
  final ValueChanged<ConversationContextAction> onSelect;
  final double maxHeight;

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Material(
        key: const Key('conversation-context-menu'),
        color: Colors.transparent,
        child: SizedBox(
          width: _desktopMenuWidth,
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: HasimTokens.cardBg(context),
              borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
              border: Border.all(color: HasimTokens.lineColor(context)),
              boxShadow: const [
                BoxShadow(
                  color: Color(0x1F000000),
                  blurRadius: 20,
                  offset: Offset(0, 8),
                ),
              ],
            ),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
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
                      for (final item in items) ...[
                        if (item.danger)
                          Divider(
                            height: 10,
                            indent: 12,
                            endIndent: 12,
                            color: HasimTokens.lineColor(context),
                          ),
                        _ConversationContextMenuRow(
                          item: item,
                          dense: true,
                          onTap: () => onSelect(item.action),
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

class _ConversationContextMenuRow extends StatelessWidget {
  const _ConversationContextMenuRow({
    required this.item,
    required this.onTap,
    this.dense = false,
  });

  final ConversationContextMenuItem item;
  final VoidCallback onTap;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    final color = item.danger
        ? HasimTokens.danger
        : HasimTokens.inkColor(context);
    return InkWell(
      key: Key('conversation-context-${item.action.name}'),
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      splashColor: AppTheme.brand.withValues(alpha: 0.10),
      highlightColor: AppTheme.brand.withValues(alpha: 0.06),
      hoverColor: AppTheme.brand.withValues(alpha: 0.06),
      child: Padding(
        padding: EdgeInsetsDirectional.fromSTEB(14, dense ? 10 : 13, 16, dense ? 10 : 13),
        child: Row(
          children: [
            Icon(
              item.icon,
              size: dense ? 20 : 22,
              color: item.danger ? color : HasimTokens.mutedColor(context),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Text(
                item.label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.start,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w600,
                  fontSize: dense ? 14 : 15,
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

Future<void> copyConversationContextText(
  BuildContext context,
  String text, {
  String copiedMessage = 'تم نسخ النص.',
}) async {
  final value = text.trim();
  if (value.isEmpty) {
    showConversationContextToast(context, 'لا يوجد نص للنسخ.');
    return;
  }
  await Clipboard.setData(ClipboardData(text: value));
  if (context.mounted) {
    showConversationContextToast(context, copiedMessage);
  }
}

/// «حذف لدي»: لا توجد واجهة حذف في الخادم، فالحذف يخص هذا الجهاز فقط.
Future<bool> confirmConversationContextDelete(
  BuildContext context, {
  required bool forMessage,
  int count = 1,
}) async {
  final title = !forMessage
      ? 'حذف المحادثة؟'
      : (count > 1 ? 'حذف $count رسائل؟' : 'حذف الرسالة؟');
  final body = forMessage
      ? 'ستُحذف من هذا الجهاز فقط، ولن تُحذف لدى العميل.'
      : 'ستُحذف من قائمة المحادثات على هذا الجهاز فقط، وتعود تلقائيًا عند وصول رسالة جديدة.';
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      key: const Key('conversation-context-delete-dialog'),
      title: Text(title),
      content: Text(body),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(ctx, false),
          child: const Text('إلغاء'),
        ),
        FilledButton(
          key: const Key('conversation-context-delete-confirm'),
          style: FilledButton.styleFrom(backgroundColor: HasimTokens.danger),
          onPressed: () => Navigator.pop(ctx, true),
          child: const Text('حذف'),
        ),
      ],
    ),
  );
  return confirmed == true;
}

/// الإبلاغ يعلّم المحادثة «يحتاج مراجعة» عبر واجهة التصنيف الموجودة.
Future<bool> confirmConversationContextReport(
  BuildContext context, {
  required bool forMessage,
}) async {
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      key: const Key('conversation-context-report-dialog'),
      title: Text(forMessage ? 'الإبلاغ عن الرسالة؟' : 'الإبلاغ عن المحادثة؟'),
      content: const Text(
        'ستُعلَّم المحادثة «يحتاج مراجعة» ليطّلع عليها الفريق.',
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(ctx, false),
          child: const Text('إلغاء'),
        ),
        FilledButton(
          key: const Key('conversation-context-report-confirm'),
          onPressed: () => Navigator.pop(ctx, true),
          child: const Text('إبلاغ'),
        ),
      ],
    ),
  );
  return confirmed == true;
}

String appendConversationNote(String current, String text) {
  final snippet = text.trim();
  final existing = current.trim();
  if (snippet.isEmpty) {
    return existing;
  }
  if (existing.isEmpty) {
    return snippet;
  }
  return '$existing\n$snippet';
}

/// يضيف النص إلى ملاحظة العميل (نفس ملاحظة لوحة العميل) ويحدّث جهة الاتصال المطابقة.
Future<void> addConversationTextToNote({
  required BuildContext context,
  required WidgetRef ref,
  required ConversationModel conversation,
  required String text,
  VoidCallback? onView,
}) async {
  final snippet = text.trim();
  if (snippet.isEmpty) {
    showConversationContextToast(context, 'لا يوجد نص لإضافته إلى الملاحظة.');
    return;
  }
  final customerId = conversation.customer?.id;
  if (customerId == null) {
    showConversationContextToast(
      context,
      'لا يوجد ملف عميل مرتبط بهذه المحادثة.',
    );
    return;
  }
  final next = appendConversationNote(
    ref.read(desktopCustomerNotesProvider)[customerId] ?? '',
    snippet,
  );
  ref.read(desktopCustomerNotesProvider.notifier).setNote(customerId, next);
  if (context.mounted) {
    final messenger = ScaffoldMessenger.of(context);
    messenger.hideCurrentSnackBar();
    messenger.showSnackBar(
      SnackBar(
        content: const Text('تمت إضافة النص إلى الملاحظة.'),
        action: onView == null
            ? null
            : SnackBarAction(label: 'عرض', onPressed: onView),
      ),
    );
  }
  final email = conversation.customer?.email?.trim();
  if (email == null || email.isEmpty) {
    return;
  }
  try {
    final contacts = ref.read(contactRepositoryProvider);
    final matches = await contacts.findByEmail(email);
    if (matches.isNotEmpty) {
      await contacts.update(matches.first.id, notes: next);
    }
  } on ApiException catch (e) {
    if (context.mounted) {
      showConversationContextToast(context, e.userMessage);
    }
  } catch (_) {
    if (context.mounted) {
      showConversationContextToast(context, 'تعذر مزامنة الملاحظة مع جهة الاتصال.');
    }
  }
}

void showConversationContextToast(BuildContext context, String message) {
  final messenger = ScaffoldMessenger.of(context);
  messenger.hideCurrentSnackBar();
  messenger.showSnackBar(SnackBar(content: Text(message)));
}
