import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';

class ContactsListScreen extends ConsumerStatefulWidget {
  const ContactsListScreen({super.key});

  @override
  ConsumerState<ContactsListScreen> createState() => _ContactsListScreenState();
}

class _ContactsListScreenState extends ConsumerState<ContactsListScreen> {
  final _search = TextEditingController();
  final _scroll = ScrollController();
  List<EmailContactModel> _items = [];
  String? _nextCursor;
  bool _loading = true;
  bool _loadingMore = false;
  bool _favoritesOnly = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    _load(reset: true);
  }

  @override
  void dispose() {
    _search.dispose();
    _scroll.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (!_scroll.hasClients || _loadingMore || _nextCursor == null) return;
    if (_scroll.position.pixels > _scroll.position.maxScrollExtent - 120) {
      _load(reset: false);
    }
  }

  Future<void> _load({required bool reset}) async {
    if (reset) {
      setState(() {
        _loading = true;
        _error = null;
        _nextCursor = null;
      });
    } else {
      setState(() => _loadingMore = true);
    }
    try {
      final result = await ref.read(contactRepositoryProvider).list(
            cursor: reset ? null : _nextCursor,
            search: _search.text.trim(),
            favorite: _favoritesOnly ? true : null,
          );
      if (!mounted) return;
      setState(() {
        _items = reset ? result.items : [..._items, ...result.items];
        _nextCursor = result.nextCursor;
        _loading = false;
        _loadingMore = false;
        _error = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
        _loadingMore = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = 'تعذر تحميل جهات الاتصال.';
        _loading = false;
        _loadingMore = false;
      });
    }
  }

  Future<void> _delete(EmailContactModel c) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('حذف جهة الاتصال؟'),
        content: Text('سيتم حذف ${c.name} نهائياً.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('حذف')),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await ref.read(contactRepositoryProvider).delete(c.id);
      if (mounted) {
        setState(() => _items = _items.where((e) => e.id != c.id).toList());
      }
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('جهات الاتصال'),
        actions: [
          IconButton(
            tooltip: 'المفضلة فقط',
            onPressed: () {
              setState(() => _favoritesOnly = !_favoritesOnly);
              _load(reset: true);
            },
            icon: Icon(_favoritesOnly ? Icons.star : Icons.star_border),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: () async {
          final changed = await context.push<bool>('/contacts/form');
          if (changed == true) _load(reset: true);
        },
        child: const Icon(Icons.person_add_alt_1),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: TextField(
              controller: _search,
              decoration: InputDecoration(
                hintText: 'بحث بالاسم أو البريد',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: _search.text.isEmpty
                    ? null
                    : IconButton(
                        icon: const Icon(Icons.clear),
                        onPressed: () {
                          _search.clear();
                          _load(reset: true);
                        },
                      ),
              ),
              onChanged: (_) => setState(() {}),
              onSubmitted: (_) => _load(reset: true),
              textInputAction: TextInputAction.search,
            ),
          ),
          Expanded(
            child: _loading && _items.isEmpty
                ? const SkeletonList()
                : _error != null && _items.isEmpty
                    ? EmptyState(
                        title: 'تعذر التحميل',
                        subtitle: _error,
                        actionLabel: 'إعادة المحاولة',
                        onAction: () => _load(reset: true),
                      )
                    : _items.isEmpty
                        ? EmptyState(
                            title: 'لا توجد جهات اتصال',
                            subtitle: _favoritesOnly
                                ? 'لا توجد مفضلات مطابقة.'
                                : 'أضف جهة اتصال للبدء بإرسال الحملات.',
                            icon: Icons.contacts_outlined,
                            actionLabel: 'إضافة',
                            onAction: () => context.push('/contacts/form'),
                          )
                        : RefreshIndicator(
                            onRefresh: () => _load(reset: true),
                            child: ListView.builder(
                              controller: _scroll,
                              itemCount: _items.length + (_loadingMore ? 1 : 0),
                              itemBuilder: (context, index) {
                                if (index >= _items.length) {
                                  return const Padding(
                                    padding: EdgeInsets.all(16),
                                    child: Center(child: CircularProgressIndicator()),
                                  );
                                }
                                final c = _items[index];
                                return Dismissible(
                                  key: ValueKey(c.id),
                                  direction: DismissDirection.endToStart,
                                  confirmDismiss: (_) async {
                                    await _delete(c);
                                    return false;
                                  },
                                  background: Container(
                                    color: Colors.red.shade400,
                                    alignment: Alignment.centerLeft,
                                    padding: const EdgeInsets.symmetric(horizontal: 20),
                                    child: const Icon(Icons.delete, color: Colors.white),
                                  ),
                                  child: ListTile(
                                    leading: CircleAvatar(
                                      child: Text(c.name.isNotEmpty ? c.name[0] : '?'),
                                    ),
                                    title: Text(c.name),
                                    subtitle: Text(c.email, textDirection: TextDirection.ltr),
                                    trailing: Row(
                                      mainAxisSize: MainAxisSize.min,
                                      children: [
                                        if (c.isFavorite)
                                          Icon(Icons.star, size: 18, color: Colors.amber.shade700),
                                        PopupMenuButton<String>(
                                          onSelected: (v) async {
                                            if (v == 'edit') {
                                              final changed = await context.push<bool>(
                                                '/contacts/form',
                                                extra: c,
                                              );
                                              if (changed == true) _load(reset: true);
                                            } else if (v == 'delete') {
                                              await _delete(c);
                                            } else if (v == 'favorite') {
                                              try {
                                                final updated = await ref
                                                    .read(contactRepositoryProvider)
                                                    .toggleFavorite(c.id);
                                                setState(() {
                                                  _items = [
                                                    for (final e in _items)
                                                      if (e.id == c.id) updated else e,
                                                  ];
                                                });
                                              } on ApiException catch (e) {
                                                if (context.mounted) {
                                                  ScaffoldMessenger.of(context)
                                                      .showSnackBar(SnackBar(content: Text(e.message)));
                                                }
                                              }
                                            }
                                          },
                                          itemBuilder: (_) => [
                                            const PopupMenuItem(value: 'edit', child: Text('تعديل')),
                                            PopupMenuItem(
                                              value: 'favorite',
                                              child: Text(c.isFavorite ? 'إزالة من المفضلة' : 'إضافة للمفضلة'),
                                            ),
                                            const PopupMenuItem(value: 'delete', child: Text('حذف')),
                                          ],
                                        ),
                                      ],
                                    ),
                                    onTap: () async {
                                      await context.push('/contacts/${c.id}');
                                      _load(reset: true);
                                    },
                                  ),
                                );
                              },
                            ),
                          ),
          ),
        ],
      ),
    );
  }
}
