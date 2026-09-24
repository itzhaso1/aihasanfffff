import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/l10n/app_localizations.dart';

class CatalogListScreen extends ConsumerStatefulWidget {
  const CatalogListScreen({super.key});

  @override
  ConsumerState<CatalogListScreen> createState() => _CatalogListScreenState();
}

class _CatalogListScreenState extends ConsumerState<CatalogListScreen> {
  final _search = TextEditingController();
  final _scroll = ScrollController();
  List<ChatCatalogItemModel> _items = [];
  String? _nextCursor;
  bool _loading = true;
  bool _loadingMore = false;
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
      final result = await ref.read(chatCatalogRepositoryProvider).list(
            cursor: reset ? null : _nextCursor,
            search: _search.text.trim(),
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
        _error = 'catalogLoadFailed';
        _loading = false;
        _loadingMore = false;
      });
    }
  }

  String _errorText(AppLocalizations l10n) {
    if (_error == null) return '';
    if (_error == 'catalogLoadFailed') return l10n.catalogLoadFailed;
    return _error!;
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    ref.listen<String>(
      authControllerProvider.select((s) => s.isolationKey),
      (previous, next) {
        if (previous != null && previous != next) _load(reset: true);
      },
    );
    return Scaffold(
      appBar: AppBar(
        title: Text(l10n.catalogListTitle),
        actions: [
          IconButton(
            tooltip: l10n.catalogCategoriesNav,
            onPressed: () => context.push('/catalog/categories'),
            icon: const Icon(Icons.category_outlined),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: () async {
          final saved = await context.push<bool>('/catalog/form');
          if (saved == true) _load(reset: true);
        },
        child: const Icon(Icons.add),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: TextField(
              controller: _search,
              decoration: InputDecoration(
                hintText: l10n.catalogSearchHint,
                prefixIcon: const Icon(Icons.search),
              ),
              onSubmitted: (_) => _load(reset: true),
            ),
          ),
          Expanded(child: _body(l10n)),
        ],
      ),
    );
  }

  Widget _body(AppLocalizations l10n) {
    if (_loading) return const SkeletonList();
    if (_error != null) {
      return EmptyState(
        title: l10n.emptyTitle,
        subtitle: _errorText(l10n),
        actionLabel: l10n.retry,
        onAction: () => _load(reset: true),
      );
    }
    if (_items.isEmpty) {
      return const EmptyState(
        title: 'كتالوج الشات فارغ',
        subtitle: 'أضف منتجات أو خدمات خاصة بالشات والذكاء الاصطناعي.',
        icon: Icons.inventory_2_outlined,
      );
    }
    return RefreshIndicator(
      onRefresh: () => _load(reset: true),
      child: ListView.separated(
        controller: _scroll,
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 88),
        itemCount: _items.length + (_loadingMore ? 1 : 0),
        separatorBuilder: (_, _) => const SizedBox(height: 8),
        itemBuilder: (context, index) {
          if (index >= _items.length) {
            return const Center(child: Padding(padding: EdgeInsets.all(16), child: CircularProgressIndicator()));
          }
          final item = _items[index];
          return ListTile(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            tileColor: Theme.of(context).cardColor,
            title: Text(item.name),
            subtitle: Text(
              '${item.itemKind == 'service' ? l10n.catalogItemKindService : l10n.catalogItemKindProduct} · ${item.price} ${item.currency}',
            ),
            trailing: Icon(
              item.isActive ? Icons.check_circle_outline : Icons.pause_circle_outline,
              color: item.isActive ? AppTheme.brand : Colors.orange,
            ),
            onTap: () async {
              final saved = await context.push<bool>('/catalog/form', extra: item);
              if (saved == true) _load(reset: true);
            },
          );
        },
      ),
    );
  }
}
