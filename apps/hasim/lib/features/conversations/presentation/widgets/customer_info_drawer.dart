import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:intl/intl.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_selection.dart';
import 'package:url_launcher/url_launcher.dart';

/// درج معلومات العميل لسطح المكتب — يعيد استخدام بيانات المحادثة/العميل وجهات الاتصال الحالية.
class CustomerInfoDrawer extends ConsumerStatefulWidget {
  const CustomerInfoDrawer({
    super.key,
    required this.conversation,
    required this.onClose,
    this.asPage = false,
  });

  final ConversationModel conversation;
  final VoidCallback onClose;

  /// صفحة كاملة على الهاتف. نفس محتوى درج سطح المكتب بدون حدّ اللوحة الجانبية.
  final bool asPage;

  @override
  ConsumerState<CustomerInfoDrawer> createState() => _CustomerInfoDrawerState();
}

class _CustomerInfoDrawerState extends ConsumerState<CustomerInfoDrawer> {
  int _conversationCount = 1;
  bool _loadingExtras = true;
  DateTime? _joinedAt;

  CustomerModel? get _customer => widget.conversation.customer;

  int? get _customerId => _customer?.id;

  @override
  void initState() {
    super.initState();
    _loadExtras();
  }

  @override
  void didUpdateWidget(covariant CustomerInfoDrawer oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.conversation.id != widget.conversation.id ||
        oldWidget.conversation.customer?.id !=
            widget.conversation.customer?.id) {
      _loadExtras();
    }
  }

  Future<void> _loadExtras() async {
    final id = _customerId;
    setState(() {
      _loadingExtras = true;
      _joinedAt = null;
    });
    if (id == null) {
      setState(() {
        _conversationCount = 1;
        _joinedAt = null;
        _loadingExtras = false;
      });
      return;
    }
    try {
      final conversations = await ref
          .read(customerRepositoryProvider)
          .conversations(id);
      if (!mounted) return;
      setState(() {
        _conversationCount = conversations.isEmpty ? 1 : conversations.length;
        _loadingExtras = false;
      });
      final email = _customer?.email?.trim();
      if (email != null && email.isNotEmpty) {
        final matches = await ref
            .read(contactRepositoryProvider)
            .findByEmail(email);
        if (!mounted || matches.isEmpty) return;
        final contact = matches.first;
        setState(() => _joinedAt = contact.createdAt);
        final notes = contact.notes?.trim() ?? '';
        if (notes.isEmpty) return;
        final current = ref.read(desktopCustomerNotesProvider);
        if ((current[id] ?? '').isEmpty) {
          ref.read(desktopCustomerNotesProvider.notifier).state = {
            ...current,
            id: notes,
          };
        }
      }
    } catch (_) {
      if (mounted) setState(() => _loadingExtras = false);
    }
  }

  bool get _recentlyActive {
    final at = widget.conversation.lastMessageAt;
    if (at == null) return false;
    return DateTime.now().difference(at.toLocal()) <=
        const Duration(minutes: 15);
  }

  String get _lastContactLabel {
    final value = widget.conversation.lastMessageAt;
    if (value == null) return '—';
    final local = value.toLocal();
    final now = DateTime.now();
    final day = DateTime(local.year, local.month, local.day);
    final today = DateTime(now.year, now.month, now.day);
    final time = DateFormat('HH:mm').format(local);
    if (day == today) return 'اليوم، $time';
    if (day == today.subtract(const Duration(days: 1))) return 'أمس، $time';
    const months = [
      'يناير',
      'فبراير',
      'مارس',
      'أبريل',
      'مايو',
      'يونيو',
      'يوليو',
      'أغسطس',
      'سبتمبر',
      'أكتوبر',
      'نوفمبر',
      'ديسمبر',
    ];
    return '${local.day} ${months[local.month - 1]}';
  }

  String _formatDate(DateTime value) {
    const months = [
      'يناير',
      'فبراير',
      'مارس',
      'أبريل',
      'مايو',
      'يونيو',
      'يوليو',
      'أغسطس',
      'سبتمبر',
      'أكتوبر',
      'نوفمبر',
      'ديسمبر',
    ];
    final local = value.toLocal();
    return '${local.day} ${months[local.month - 1]} ${local.year}';
  }

  List<PopupMenuEntry<String>> _moreItems(CustomerModel? customer, int? id) => [
    const PopupMenuItem(value: 'contact', child: Text('إضافة لجهات الاتصال')),
    if (customer?.phone != null)
      const PopupMenuItem(value: 'copy_phone', child: Text('نسخ الرقم')),
    if (customer?.email != null)
      const PopupMenuItem(value: 'copy_email', child: Text('نسخ البريد')),
    if (id != null && !widget.asPage)
      const PopupMenuItem(value: 'profile', child: Text('ملف العميل الكامل')),
  ];

  void _onMore(String value, CustomerModel? customer, int? id) {
    if (value == 'contact') _addContact();
    if (value == 'copy_phone') _copy(customer?.phone, 'تم نسخ الرقم');
    if (value == 'copy_email') _copy(customer?.email, 'تم نسخ البريد');
    if (value == 'profile' && id != null) {
      context.push('/customers/$id', extra: customer);
    }
  }

  Future<void> _call() async {
    final phone = _customer?.phone?.trim();
    if (phone == null || phone.isEmpty) {
      _toast('لا يوجد رقم هاتف');
      return;
    }
    final uri = Uri(scheme: 'tel', path: phone);
    await launchUrl(uri);
  }

  Future<void> _copy(String? value, String ok) async {
    if (value == null || value.trim().isEmpty) return;
    await Clipboard.setData(ClipboardData(text: value.trim()));
    _toast(ok);
  }

  void _toast(String message) {
    if (!mounted) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _editNote() async {
    final id = _customerId;
    final current = id == null
        ? ''
        : (ref.read(desktopCustomerNotesProvider)[id] ?? '');
    final controller = TextEditingController(text: current);
    final next = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('ملاحظة'),
        content: TextField(
          controller: controller,
          maxLines: 4,
          decoration: const InputDecoration(hintText: 'اكتب ملاحظة عن العميل'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, controller.text.trim()),
            child: const Text('حفظ'),
          ),
        ],
      ),
    );
    controller.dispose();
    if (next == null || id == null || !mounted) return;
    ref.read(desktopCustomerNotesProvider.notifier).state = {
      ...ref.read(desktopCustomerNotesProvider),
      id: next,
    };
    final email = _customer?.email?.trim();
    if (email == null || email.isEmpty) return;
    try {
      final matches = await ref
          .read(contactRepositoryProvider)
          .findByEmail(email);
      if (matches.isNotEmpty) {
        await ref
            .read(contactRepositoryProvider)
            .update(matches.first.id, notes: next);
      }
    } on ApiException catch (e) {
      _toast(e.userMessage);
    } catch (_) {
      _toast('تعذر حفظ الملاحظات.');
    }
  }

  Future<void> _addTag() async {
    final id = _customerId;
    if (id == null) return;
    final controller = TextEditingController();
    final tag = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('إضافة وسم'),
        content: TextField(
          controller: controller,
          decoration: const InputDecoration(hintText: 'اسم الوسم'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, controller.text.trim()),
            child: const Text('إضافة'),
          ),
        ],
      ),
    );
    controller.dispose();
    if (tag == null || tag.isEmpty || !mounted) return;
    final current = [
      ...(ref.read(desktopCustomerTagsProvider)[id] ?? const <String>[]),
    ];
    if (!current.contains(tag)) current.add(tag);
    ref.read(desktopCustomerTagsProvider.notifier).state = {
      ...ref.read(desktopCustomerTagsProvider),
      id: current,
    };
  }

  Future<void> _addContact() async {
    final customer = _customer;
    final email = customer?.email?.trim();
    if (customer == null || email == null || email.isEmpty) {
      _toast('لا يوجد بريد لإضافته لجهات الاتصال');
      return;
    }
    try {
      final existing = await ref
          .read(contactRepositoryProvider)
          .findByEmail(email);
      if (!mounted) return;
      if (existing.isNotEmpty) {
        _toast('جهة الاتصال موجودة مسبقاً');
        return;
      }
      await ref
          .read(contactRepositoryProvider)
          .create(name: customer.name, email: email, phone: customer.phone);
      _toast('تمت الإضافة إلى جهات الاتصال');
    } on ApiException catch (e) {
      _toast(e.userMessage);
    } catch (_) {
      _toast('تعذر الإضافة');
    }
  }

  @override
  Widget build(BuildContext context) {
    final customer = _customer;
    final name = customer?.name.isNotEmpty == true
        ? customer!.name
        : widget.conversation.title;
    final initial = name.isNotEmpty ? name[0] : '?';
    final id = _customerId;
    final note = id == null
        ? ''
        : (ref.watch(desktopCustomerNotesProvider)[id] ?? '');
    final tags = id == null
        ? const <String>[]
        : (ref.watch(desktopCustomerTagsProvider)[id] ?? const <String>[]);
    final online = _recentlyActive;

    return Material(
      color: HasimTokens.cardBg(context),
      elevation: 0,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: HasimTokens.isDark(context)
              ? HasimTokens.darkPage
              : const Color(0xFFF5FBFA),
          border: widget.asPage
              ? null
              : Border(
                  right: BorderSide(color: HasimTokens.lineColor(context)),
                ),
          boxShadow: widget.asPage
              ? null
              : const [
                  BoxShadow(
                    color: Color(0x140F2A24),
                    blurRadius: 18,
                    offset: Offset(6, 0),
                  ),
                ],
        ),
        child: SafeArea(
          top: widget.asPage,
          bottom: widget.asPage,
          child: Column(
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(4, 8, 16, 4),
                child: Row(
                  children: [
                    const Expanded(
                      child: Text(
                        'معلومات العميل',
                        style: TextStyle(
                          fontWeight: FontWeight.w800,
                          fontSize: 16,
                        ),
                      ),
                    ),
                    IconButton(
                      key: const Key('customer-drawer-close'),
                      tooltip: 'إغلاق',
                      onPressed: widget.onClose,
                      icon: const Icon(Icons.close_rounded),
                    ),
                  ],
                ),
              ),
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
                  children: [
                    Center(
                      child: Stack(
                        children: [
                          CircleAvatar(
                            radius: 44,
                            backgroundColor: const Color(0xFFE8EEEF),
                            child: Text(
                              initial,
                              style: const TextStyle(
                                fontSize: 30,
                                fontWeight: FontWeight.w700,
                                color: Color(0xFF5B6B6E),
                              ),
                            ),
                          ),
                          Positioned(
                            left: 6,
                            bottom: 6,
                            child: Container(
                              width: 14,
                              height: 14,
                              decoration: BoxDecoration(
                                color: online
                                    ? const Color(0xFF22C55E)
                                    : const Color(0xFF94A3B8),
                                shape: BoxShape.circle,
                                border: Border.all(
                                  color: HasimTokens.isDark(context)
                                      ? HasimTokens.darkPage
                                      : Colors.white,
                                  width: 2,
                                ),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    Text(
                      name,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        fontSize: 20,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      online ? 'متصل الآن' : 'غير متصل',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: online
                            ? const Color(0xFF16A34A)
                            : HasimTokens.mutedColor(context),
                        fontWeight: FontWeight.w600,
                        fontSize: 13,
                      ),
                    ),
                    const SizedBox(height: 16),
                    if (customer?.phone?.isNotEmpty == true)
                      _InfoLine(
                        icon: Icons.phone_outlined,
                        value: customer!.phone!,
                      ),
                    if (customer?.email?.isNotEmpty == true)
                      _InfoLine(
                        icon: Icons.mail_outline,
                        value: customer!.email!,
                      ),
                    if (customer?.whatsapp?.isNotEmpty == true)
                      _InfoLine(
                        icon: Icons.chat_outlined,
                        value: customer!.whatsapp!,
                      ),
                    const SizedBox(height: 16),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceAround,
                      children: [
                        _ActionCircle(
                          icon: Icons.phone_outlined,
                          label: 'اتصال',
                          onTap: _call,
                        ),
                        _ActionCircle(
                          icon: Icons.sell_outlined,
                          label: 'وسم',
                          onTap: _addTag,
                        ),
                        _ActionCircle(
                          icon: Icons.sticky_note_2_outlined,
                          label: 'ملاحظة',
                          onTap: _editNote,
                        ),
                        PopupMenuButton<String>(
                          tooltip: 'المزيد',
                          padding: EdgeInsets.zero,
                          offset: const Offset(0, 52),
                          onSelected: (value) => _onMore(value, customer, id),
                          itemBuilder: (_) => _moreItems(customer, id),
                          child: const _ActionCircle(
                            icon: Icons.more_horiz_rounded,
                            label: 'المزيد',
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 20),
                    _SectionCard(
                      title: 'معلومات إضافية',
                      child: Column(
                        children: [
                          const _MetaRow(
                            label: 'اللغة',
                            value: 'العربية',
                            icon: Icons.language,
                          ),
                          if (_joinedAt != null)
                            _MetaRow(
                              label: 'تاريخ الانضمام',
                              value: _formatDate(_joinedAt!),
                              icon: Icons.calendar_today_outlined,
                            ),
                          _MetaRow(
                            label: 'عدد المحادثات',
                            value: _loadingExtras ? '…' : '$_conversationCount',
                            icon: Icons.forum_outlined,
                          ),
                          _MetaRow(
                            label: 'آخر تواصل',
                            value: _lastContactLabel,
                            icon: Icons.schedule_outlined,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    _SectionCard(
                      title: 'ملاحظات',
                      trailing: IconButton(
                        tooltip: 'تعديل الملاحظة',
                        onPressed: _editNote,
                        icon: const Icon(Icons.edit_outlined, size: 18),
                      ),
                      child: Text(
                        note.isEmpty ? 'لا توجد ملاحظات بعد.' : note,
                        style: TextStyle(
                          height: 1.5,
                          color: note.isEmpty
                              ? HasimTokens.mutedColor(context)
                              : HasimTokens.inkColor(context),
                        ),
                      ),
                    ),
                    const SizedBox(height: 12),
                    _SectionCard(
                      title: 'الوسوم',
                      child: Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          for (final tag in tags)
                            Chip(
                              label: Text(
                                tag,
                                style: TextStyle(
                                  fontWeight: FontWeight.w700,
                                  fontSize: 12,
                                  color: HasimTokens.inkColor(context),
                                ),
                              ),
                              backgroundColor: HasimTokens.mintFill(context),
                              side: BorderSide.none,
                              visualDensity: VisualDensity.compact,
                              materialTapTargetSize:
                                  MaterialTapTargetSize.shrinkWrap,
                            ),
                          ActionChip(
                            avatar: Icon(
                              Icons.add,
                              size: 16,
                              color: HasimTokens.chatGreen,
                            ),
                            label: Text(
                              'إضافة وسم',
                              style: TextStyle(
                                fontWeight: FontWeight.w700,
                                fontSize: 12,
                                color: HasimTokens.inkColor(context),
                              ),
                            ),
                            backgroundColor: HasimTokens.cardBg(context),
                            side: BorderSide(
                              color: HasimTokens.lineColor(context),
                            ),
                            onPressed: _addTag,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _InfoLine extends StatelessWidget {
  const _InfoLine({required this.icon, required this.value});

  final IconData icon;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        children: [
          Icon(icon, size: 18, color: HasimTokens.mutedColor(context)),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(
                fontWeight: FontWeight.w600,
                fontSize: 13.5,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ActionCircle extends StatelessWidget {
  const _ActionCircle({required this.icon, required this.label, this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final content = Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
      child: Column(
        children: [
          Container(
            width: 46,
            height: 46,
            decoration: BoxDecoration(
              color: HasimTokens.cardBg(context),
              shape: BoxShape.circle,
              border: Border.all(color: HasimTokens.lineColor(context)),
            ),
            child: Icon(icon, color: HasimTokens.chatGreen, size: 20),
          ),
          const SizedBox(height: 6),
          Text(
            label,
            style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700),
          ),
        ],
      ),
    );
    if (onTap == null) return content;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: content,
    );
  }
}

class _SectionCard extends StatelessWidget {
  const _SectionCard({required this.title, required this.child, this.trailing});

  final String title;
  final Widget child;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 8, 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    title,
                    style: const TextStyle(
                      fontWeight: FontWeight.w800,
                      fontSize: 14,
                    ),
                  ),
                ),
                ?trailing,
              ],
            ),
            const SizedBox(height: 10),
            child,
          ],
        ),
      ),
    );
  }
}

class _MetaRow extends StatelessWidget {
  const _MetaRow({
    required this.label,
    required this.value,
    required this.icon,
  });

  final String label;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Icon(icon, size: 16, color: HasimTokens.mutedColor(context)),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              label,
              style: TextStyle(
                color: HasimTokens.mutedColor(context),
                fontSize: 13,
              ),
            ),
          ),
          Text(
            value,
            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13),
          ),
        ],
      ),
    );
  }
}
