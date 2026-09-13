import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/utils/relative_time.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';

class CustomerProfileScreen extends ConsumerStatefulWidget {
  const CustomerProfileScreen({super.key, required this.customerId});
  final int customerId;

  @override
  ConsumerState<CustomerProfileScreen> createState() => _CustomerProfileScreenState();
}

class _CustomerProfileScreenState extends ConsumerState<CustomerProfileScreen> {
  CustomerModel? _customer;
  List<ConversationModel> _conversations = [];
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
      final repo = ref.read(customerRepositoryProvider);
      final customer = await repo.show(widget.customerId);
      final conversations = await repo.conversations(widget.customerId);
      if (!mounted) return;
      setState(() {
        _customer = customer;
        _conversations = conversations;
        _loading = false;
      });
    } catch (_) {
      setState(() {
        _error = 'تعذر تحميل ملف العميل.';
        _loading = false;
      });
    }
  }

  Future<void> _quickAddContact() async {
    final customer = _customer;
    final email = customer?.email?.trim();
    if (customer == null || email == null || email.isEmpty) return;
    try {
      final existing = await ref.read(contactRepositoryProvider).findByEmail(email);
      if (!mounted) return;
      if (existing.isNotEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: const Text('جهة الاتصال موجودة مسبقاً'),
            action: SnackBarAction(
              label: 'عرض',
              onPressed: () => context.push('/contacts/${existing.first.id}'),
            ),
          ),
        );
        return;
      }
      final created = await ref.read(contactRepositoryProvider).create(
            name: customer.name,
            email: email,
            phone: customer.phone,
          );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: const Text('تمت الإضافة إلى جهات الاتصال'),
          action: SnackBarAction(
            label: 'عرض',
            onPressed: () => context.push('/contacts/${created.id}'),
          ),
        ),
      );
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تعذر الإضافة')));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('ملف العميل')),
      body: _loading
          ? const SkeletonList()
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
                      leading: CircleAvatar(child: Text(_customer!.name.isNotEmpty ? _customer!.name[0] : '?')),
                      title: Text(_customer!.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                      subtitle: Text([
                        if (_customer!.phone != null) _customer!.phone!,
                        if (_customer!.email != null) _customer!.email!,
                      ].join(' · ')),
                    ),
                    if (_customer!.email != null && _customer!.email!.trim().isNotEmpty)
                      Align(
                        alignment: AlignmentDirectional.centerStart,
                        child: TextButton.icon(
                          onPressed: _quickAddContact,
                          icon: const Icon(Icons.person_add_alt),
                          label: const Text('إضافة لجهات الاتصال'),
                        ),
                      ),
                    const SizedBox(height: 16),
                    Text('المحادثات', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                    if (_conversations.isEmpty)
                      const Padding(padding: EdgeInsets.symmetric(vertical: 8), child: Text('لا توجد محادثات'))
                    else
                      ..._conversations.map(
                        (c) => ListTile(
                          contentPadding: EdgeInsets.zero,
                          title: Text(c.channelLabel),
                          subtitle: Text(c.preview, maxLines: 1, overflow: TextOverflow.ellipsis),
                          trailing: Text(relativeTimeAr(c.lastMessageAt)),
                          onTap: () => context.push('/conversations/${c.id}'),
                        ),
                      ),
                  ],
                ),
    );
  }
}
