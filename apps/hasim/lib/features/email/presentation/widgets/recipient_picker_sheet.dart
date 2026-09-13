import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';

class RecipientSelection {
  const RecipientSelection({
    this.contacts = const [],
    this.groups = const [],
    this.emails = const [],
    this.allContacts = false,
    this.allContactsCount = 0,
  });

  final List<EmailContactModel> contacts;
  final List<ContactGroupModel> groups;
  final List<String> emails;
  final bool allContacts;
  final int allContactsCount;

  int get estimatedCount {
    if (allContacts) return allContactsCount;
    final emailsSet = <String>{
      ...emails.map((e) => e.toLowerCase()),
      ...contacts.map((c) => c.email.toLowerCase()),
    };
    // Groups expand server-side; count their contacts_count as estimate.
    final groupEstimate = groups.fold<int>(0, (n, g) => n + g.contactsCount);
    return emailsSet.length + groupEstimate;
  }

  bool get isEmpty =>
      !allContacts && contacts.isEmpty && groups.isEmpty && emails.isEmpty;

  bool get isCampaign =>
      allContacts || groups.isNotEmpty || contacts.length > 1 || (contacts.length + emails.length) > 1;
}

class RecipientPickerSheet extends ConsumerStatefulWidget {
  const RecipientPickerSheet({super.key, this.initial});

  final RecipientSelection? initial;

  static Future<RecipientSelection?> show(BuildContext context, {RecipientSelection? initial}) {
    return showModalBottomSheet<RecipientSelection>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => SizedBox(
        height: MediaQuery.sizeOf(context).height * 0.88,
        child: RecipientPickerSheet(initial: initial),
      ),
    );
  }

  @override
  ConsumerState<RecipientPickerSheet> createState() => _RecipientPickerSheetState();
}

class _RecipientPickerSheetState extends ConsumerState<RecipientPickerSheet> {
  final _search = TextEditingController();
  final _manual = TextEditingController();
  final _selectedContacts = <int, EmailContactModel>{};
  final _selectedGroups = <int, ContactGroupModel>{};
  final _manualEmails = <String>{};
  bool _allContacts = false;
  int _allCount = 0;
  List<EmailContactModel> _contacts = [];
  List<ContactGroupModel> _groups = [];
  List<RecentRecipientModel> _recent = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    final initial = widget.initial;
    if (initial != null) {
      for (final c in initial.contacts) {
        _selectedContacts[c.id] = c;
      }
      for (final g in initial.groups) {
        _selectedGroups[g.id] = g;
      }
      _manualEmails.addAll(initial.emails);
      _allContacts = initial.allContacts;
      _allCount = initial.allContactsCount;
    }
    _bootstrap();
  }

  @override
  void dispose() {
    _search.dispose();
    _manual.dispose();
    super.dispose();
  }

  Future<void> _bootstrap() async {
    try {
      final contacts = await ref.read(contactRepositoryProvider).list(perPage: 50);
      final groups = await ref.read(contactGroupRepositoryProvider).list();
      List<RecentRecipientModel> recent = const [];
      try {
        recent = await ref.read(contactRepositoryProvider).recentRecipients();
      } catch (_) {}
      if (!mounted) return;
      setState(() {
        _contacts = contacts.items;
        _groups = groups;
        _recent = recent;
        _allCount = contacts.items.length; // approximate; refine below
        _loading = false;
      });
      // Best-effort total: paginate lightly for count when selecting all.
      var cursor = contacts.nextCursor;
      var total = contacts.items.length;
      while (cursor != null) {
        final page = await ref.read(contactRepositoryProvider).list(cursor: cursor, perPage: 50);
        total += page.items.length;
        cursor = page.nextCursor;
        if (!mounted) return;
      }
      setState(() => _allCount = total);
    } catch (_) {
      if (!mounted) return;
      setState(() => _loading = false);
    }
  }

  Future<void> _searchContacts(String q) async {
    try {
      final result = await ref.read(contactRepositoryProvider).list(search: q, perPage: 30);
      if (!mounted) return;
      setState(() => _contacts = result.items);
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _confirmAll() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('إرسال لجميع جهات الاتصال؟'),
        content: Text('سيتم جدولة حملة إلى حوالي $_allCount جهة اتصال.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('تأكيد')),
        ],
      ),
    );
    if (ok == true) {
      setState(() {
        _allContacts = true;
        _selectedContacts.clear();
        _selectedGroups.clear();
        _manualEmails.clear();
      });
    }
  }

  void _addManual() {
    final raw = _manual.text.trim();
    if (raw.isEmpty || !raw.contains('@')) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('أدخل بريداً صالحاً')));
      return;
    }
    setState(() {
      _manualEmails.add(raw);
      _manual.clear();
      _allContacts = false;
    });
  }

  RecipientSelection _buildSelection() => RecipientSelection(
        contacts: _selectedContacts.values.toList(),
        groups: _selectedGroups.values.toList(),
        emails: _manualEmails.toList(),
        allContacts: _allContacts,
        allContactsCount: _allCount,
      );

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 8, 0),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  'اختيار المستلمين',
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
                ),
              ),
              TextButton(
                onPressed: () => Navigator.pop(context, _buildSelection()),
                child: const Text('تم'),
              ),
            ],
          ),
        ),
        if (_loading)
          const Expanded(child: SkeletonList())
        else
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              children: [
                TextField(
                  controller: _search,
                  decoration: const InputDecoration(
                    hintText: 'بحث في جهات الاتصال',
                    prefixIcon: Icon(Icons.search),
                  ),
                  onSubmitted: _searchContacts,
                ),
                const SizedBox(height: 12),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(
                    Icons.select_all,
                    color: _allContacts ? Theme.of(context).colorScheme.primary : null,
                  ),
                  title: const Text('جميع جهات الاتصال'),
                  subtitle: Text('$_allCount جهة تقريباً'),
                  trailing: _allContacts ? const Icon(Icons.check_circle) : null,
                  onTap: _confirmAll,
                ),
                const Divider(),
                Text('المجموعات', style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                const SizedBox(height: 6),
                if (_groups.isEmpty)
                  Text('لا توجد مجموعات', style: TextStyle(color: Colors.grey.shade600))
                else
                  ..._groups.map((g) {
                    final selected = _selectedGroups.containsKey(g.id);
                    return CheckboxListTile(
                      contentPadding: EdgeInsets.zero,
                      value: selected,
                      onChanged: (v) {
                        setState(() {
                          _allContacts = false;
                          if (v == true) {
                            _selectedGroups[g.id] = g;
                          } else {
                            _selectedGroups.remove(g.id);
                          }
                        });
                      },
                      title: Text(g.name),
                      subtitle: Text('${g.contactsCount} جهة'),
                    );
                  }),
                const Divider(),
                Text('جهات الاتصال', style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                ..._contacts.map((c) {
                  final selected = _selectedContacts.containsKey(c.id);
                  return CheckboxListTile(
                    contentPadding: EdgeInsets.zero,
                    value: selected,
                    onChanged: (v) {
                      setState(() {
                        _allContacts = false;
                        if (v == true) {
                          _selectedContacts[c.id] = c;
                        } else {
                          _selectedContacts.remove(c.id);
                        }
                      });
                    },
                    title: Text(c.name),
                    subtitle: Text(c.email, textDirection: TextDirection.ltr),
                  );
                }),
                if (_recent.isNotEmpty) ...[
                  const Divider(),
                  Text('مستلمون أخيرون', style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                  ..._recent.map(
                    (r) => ListTile(
                      contentPadding: EdgeInsets.zero,
                      title: Text(r.name ?? r.email),
                      subtitle: Text(r.email, textDirection: TextDirection.ltr),
                      onTap: () => setState(() {
                        _allContacts = false;
                        _manualEmails.add(r.email);
                      }),
                    ),
                  ),
                ],
                const Divider(),
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: _manual,
                        decoration: const InputDecoration(labelText: 'بريد يدوي'),
                        textDirection: TextDirection.ltr,
                        textAlign: TextAlign.left,
                        onSubmitted: (_) => _addManual(),
                      ),
                    ),
                    IconButton(onPressed: _addManual, icon: const Icon(Icons.add)),
                  ],
                ),
                if (_manualEmails.isNotEmpty)
                  Wrap(
                    spacing: 6,
                    children: [
                      for (final e in _manualEmails)
                        InputChip(
                          label: Text(e, textDirection: TextDirection.ltr),
                          onDeleted: () => setState(() => _manualEmails.remove(e)),
                        ),
                    ],
                  ),
              ],
            ),
          ),
      ],
    );
  }
}
