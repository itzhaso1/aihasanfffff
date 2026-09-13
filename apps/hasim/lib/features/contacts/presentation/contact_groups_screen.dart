import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';

class ContactGroupsScreen extends ConsumerStatefulWidget {
  const ContactGroupsScreen({super.key});

  @override
  ConsumerState<ContactGroupsScreen> createState() => _ContactGroupsScreenState();
}

class _ContactGroupsScreenState extends ConsumerState<ContactGroupsScreen> {
  List<ContactGroupModel> _groups = [];
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
      final groups = await ref.read(contactGroupRepositoryProvider).list();
      if (!mounted) return;
      setState(() {
        _groups = groups;
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
        _error = 'تعذر تحميل المجموعات.';
        _loading = false;
      });
    }
  }

  Future<void> _openForm({ContactGroupModel? group}) async {
    final result = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (context) => Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
        child: _GroupFormSheet(group: group),
      ),
    );
    if (result == true) _load();
  }

  Future<void> _assignMembers(ContactGroupModel group) async {
    final changed = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => _AssignMembersScreen(group: group)),
    );
    if (changed == true) _load();
  }

  Future<void> _delete(ContactGroupModel g) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('حذف المجموعة؟'),
        content: Text('سيتم حذف «${g.name}».'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('حذف')),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await ref.read(contactGroupRepositoryProvider).delete(g.id);
      _load();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('مجموعات جهات الاتصال')),
      floatingActionButton: FloatingActionButton(
        onPressed: () => _openForm(),
        child: const Icon(Icons.group_add),
      ),
      body: _loading
          ? const SkeletonList()
          : _error != null
              ? EmptyState(
                  title: 'تعذر التحميل',
                  subtitle: _error,
                  actionLabel: 'إعادة المحاولة',
                  onAction: _load,
                )
              : _groups.isEmpty
                  ? EmptyState(
                      title: 'لا توجد مجموعات',
                      subtitle: 'أنشئ مجموعة لتنظيم جهات الاتصال وإرسال الحملات.',
                      icon: Icons.groups_outlined,
                      actionLabel: 'إنشاء مجموعة',
                      onAction: () => _openForm(),
                    )
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView.separated(
                        padding: const EdgeInsets.symmetric(vertical: 8),
                        itemCount: _groups.length,
                        separatorBuilder: (_, _) => const Divider(height: 1),
                        itemBuilder: (context, index) {
                          final g = _groups[index];
                          return ListTile(
                            leading: const CircleAvatar(child: Icon(Icons.group_outlined)),
                            title: Text(g.name),
                            subtitle: Text(
                              [
                                if (g.description != null && g.description!.isNotEmpty) g.description!,
                                '${g.contactsCount} جهة اتصال',
                              ].join(' · '),
                            ),
                            trailing: PopupMenuButton<String>(
                              onSelected: (v) {
                                if (v == 'edit') _openForm(group: g);
                                if (v == 'members') _assignMembers(g);
                                if (v == 'delete') _delete(g);
                              },
                              itemBuilder: (_) => const [
                                PopupMenuItem(value: 'members', child: Text('تعيين الأعضاء')),
                                PopupMenuItem(value: 'edit', child: Text('تعديل')),
                                PopupMenuItem(value: 'delete', child: Text('حذف')),
                              ],
                            ),
                            onTap: () => _assignMembers(g),
                          );
                        },
                      ),
                    ),
    );
  }
}

class _GroupFormSheet extends ConsumerStatefulWidget {
  const _GroupFormSheet({this.group});
  final ContactGroupModel? group;

  @override
  ConsumerState<_GroupFormSheet> createState() => _GroupFormSheetState();
}

class _GroupFormSheetState extends ConsumerState<_GroupFormSheet> {
  late final TextEditingController _name;
  late final TextEditingController _desc;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _name = TextEditingController(text: widget.group?.name ?? '');
    _desc = TextEditingController(text: widget.group?.description ?? '');
  }

  @override
  void dispose() {
    _name.dispose();
    _desc.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('اسم المجموعة مطلوب')));
      return;
    }
    setState(() => _saving = true);
    try {
      final repo = ref.read(contactGroupRepositoryProvider);
      if (widget.group == null) {
        await repo.create(name: _name.text.trim(), description: _desc.text.trim());
      } else {
        await repo.update(widget.group!.id, name: _name.text.trim(), description: _desc.text.trim());
      }
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              widget.group == null ? 'مجموعة جديدة' : 'تعديل المجموعة',
              style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 12),
            TextField(controller: _name, decoration: const InputDecoration(labelText: 'الاسم')),
            const SizedBox(height: 12),
            TextField(controller: _desc, decoration: const InputDecoration(labelText: 'الوصف'), maxLines: 2),
            const SizedBox(height: 16),
            FilledButton(onPressed: _saving ? null : _save, child: Text(_saving ? '...' : 'حفظ')),
          ],
        ),
      ),
    );
  }
}

class _AssignMembersScreen extends ConsumerStatefulWidget {
  const _AssignMembersScreen({required this.group});
  final ContactGroupModel group;

  @override
  ConsumerState<_AssignMembersScreen> createState() => _AssignMembersScreenState();
}

class _AssignMembersScreenState extends ConsumerState<_AssignMembersScreen> {
  final _selected = <int>{};
  List<EmailContactModel> _contacts = [];
  bool _loading = true;
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  Future<void> _bootstrap() async {
    try {
      final detail = await ref.read(contactRepositoryProvider).list(perPage: 50);
      // Prefer contacts already on group if API returned them; otherwise load all.
      final existing = widget.group.contacts.map((c) => c.id).toSet();
      if (existing.isEmpty) {
        // Fetch group list again won't include members; keep empty selection.
      }
      if (!mounted) return;
      setState(() {
        _contacts = detail.items;
        _selected.addAll(existing);
        // Also try to mark from contacts_count alone — no IDs available.
        _loading = false;
      });
      // Load more pages lightly
      var cursor = detail.nextCursor;
      while (cursor != null) {
        final page = await ref.read(contactRepositoryProvider).list(cursor: cursor, perPage: 50);
        cursor = page.nextCursor;
        if (!mounted) return;
        setState(() => _contacts = [..._contacts, ...page.items]);
      }
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = 'تعذر تحميل جهات الاتصال.';
        _loading = false;
      });
    }
  }

  Future<void> _save() async {
    setState(() => _saving = true);
    try {
      await ref.read(contactGroupRepositoryProvider).syncMembers(widget.group.id, _selected.toList());
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم تحديث الأعضاء')));
        Navigator.pop(context, true);
      }
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('أعضاء ${widget.group.name}'),
        actions: [
          TextButton(onPressed: _saving || _loading ? null : _save, child: Text(_saving ? '...' : 'حفظ')),
        ],
      ),
      body: _loading
          ? const SkeletonList()
          : _error != null
              ? EmptyState(title: _error!, actionLabel: 'رجوع', onAction: () => Navigator.pop(context))
              : Column(
                  children: [
                    if (widget.group.contactsCount > 0 && _selected.isEmpty)
                      Material(
                        color: Colors.amber.shade50,
                        child: const Padding(
                          padding: EdgeInsets.all(12),
                          child: Text(
                            'الحفظ يستبدل أعضاء المجموعة. لا توجد واجهة لجلب الأعضاء الحاليين بعد — أعد التحديد قبل الحفظ.',
                            style: TextStyle(height: 1.35, fontSize: 13),
                          ),
                        ),
                      ),
                    Expanded(
                      child: ListView.builder(
                        itemCount: _contacts.length,
                        itemBuilder: (context, index) {
                          final c = _contacts[index];
                          final checked = _selected.contains(c.id);
                          return CheckboxListTile(
                            value: checked,
                            onChanged: (v) {
                              setState(() {
                                if (v == true) {
                                  _selected.add(c.id);
                                } else {
                                  _selected.remove(c.id);
                                }
                              });
                            },
                            title: Text(c.name),
                            subtitle: Text(c.email, textDirection: TextDirection.ltr),
                          );
                        },
                      ),
                    ),
                  ],
                ),
    );
  }
}
