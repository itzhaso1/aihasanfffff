import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/email/presentation/email_compose_screen.dart';

class ContactDetailScreen extends ConsumerStatefulWidget {
  const ContactDetailScreen({super.key, required this.id});

  final int id;

  @override
  ConsumerState<ContactDetailScreen> createState() => _ContactDetailScreenState();
}

class _ContactDetailScreenState extends ConsumerState<ContactDetailScreen> {
  EmailContactModel? _contact;
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
      final c = await ref.read(contactRepositoryProvider).show(widget.id);
      if (!mounted) return;
      setState(() {
        _contact = c;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = 'تعذر فتح جهة الاتصال.';
        _loading = false;
      });
    }
  }

  Future<void> _toggleFavorite() async {
    try {
      final updated = await ref.read(contactRepositoryProvider).toggleFavorite(widget.id);
      if (mounted) setState(() => _contact = updated);
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = _contact;
    return Scaffold(
      appBar: AppBar(
        title: const Text('تفاصيل جهة الاتصال'),
        actions: [
          if (c != null)
            IconButton(
              onPressed: _toggleFavorite,
              icon: Icon(c.isFavorite ? Icons.star : Icons.star_border),
            ),
          if (c != null)
            IconButton(
              onPressed: () async {
                final changed = await context.push<bool>('/contacts/form', extra: c);
                if (changed == true) _load();
              },
              icon: const Icon(Icons.edit_outlined),
            ),
        ],
      ),
      body: _loading
          ? const SkeletonList(itemCount: 4)
          : _error != null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(_error!),
                      TextButton(onPressed: _load, child: const Text('إعادة المحاولة')),
                    ],
                  ),
                )
              : ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: CircleAvatar(radius: 28, child: Text(c!.name.isNotEmpty ? c.name[0] : '?')),
                      title: Text(c.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                      subtitle: Text(c.email, textDirection: TextDirection.ltr),
                    ),
                    const Divider(height: 28),
                    if (c.phone != null && c.phone!.isNotEmpty) _row('الهاتف', c.phone!),
                    if (c.company != null && c.company!.isNotEmpty) _row('الشركة', c.company!),
                    if (c.jobTitle != null && c.jobTitle!.isNotEmpty) _row('المسمى', c.jobTitle!),
                    if (c.notes != null && c.notes!.isNotEmpty) _row('ملاحظات', c.notes!),
                    const SizedBox(height: 12),
                    Text('المجموعات', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                    const SizedBox(height: 8),
                    if (c.groups.isEmpty)
                      Text('غير منضم لأي مجموعة', style: TextStyle(color: Colors.grey.shade600))
                    else
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [for (final g in c.groups) Chip(label: Text(g.name))],
                      ),
                    const SizedBox(height: 24),
                    FilledButton.icon(
                      onPressed: () {
                        Navigator.of(context).push(
                          MaterialPageRoute<void>(
                            builder: (_) => EmailComposeScreen(prefillTo: c.email),
                          ),
                        );
                      },
                      icon: const Icon(Icons.mail_outline),
                      label: const Text('إضافة من البريد'),
                    ),
                    TextButton(
                      onPressed: () => context.push('/contact-groups'),
                      child: const Text('إدارة المجموعات'),
                    ),
                  ],
                ),
    );
  }

  Widget _row(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(width: 90, child: Text(label, style: TextStyle(color: Colors.grey.shade700))),
          Expanded(child: Text(value)),
        ],
      ),
    );
  }
}
