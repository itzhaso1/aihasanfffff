import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/hasim_search_bar.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/presentation/catalog_form_screen.dart';
import 'package:hasim/l10n/app_localizations.dart';

class CatalogListScreen extends ConsumerStatefulWidget {
  const CatalogListScreen({
    super.key,
    this.mode = 'catalog',
    this.embedded = false,
  });

  /// catalog | products
  final String mode;
  final bool embedded;

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
  ChatCatalogItemModel? _selected;
  bool _creating = false;
  String _statusFilter =
      'all'; // all | active | inactive | in_stock | out_of_stock

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
    final repo = ref.read(chatCatalogRepositoryProvider);
    final kind = widget.mode == 'products' ? 'product' : null;
    if (reset) {
      final cached = await repo.cachedItems(
        search: _search.text.trim(),
        itemKind: kind,
      );
      if (!mounted) return;
      final visible = cached.where(_matchesMode).toList();
      setState(() {
        _items = visible;
        _loading = visible.isEmpty;
        _error = null;
        _nextCursor = null;
      });
    } else {
      setState(() => _loadingMore = true);
    }
    try {
      final result = await ref
          .read(chatCatalogRepositoryProvider)
          .list(
            cursor: reset ? null : _nextCursor,
            search: _search.text.trim(),
            itemKind: widget.mode == 'products' ? 'product' : null,
          );
      if (!mounted) return;
      final visible = result.items.where(_matchesMode).toList();
      setState(() {
        _items = reset ? visible : [..._items, ...visible];
        _nextCursor = result.nextCursor;
        _loading = false;
        _loadingMore = false;
        _error = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = _items.isEmpty ? e.message : null;
        _loading = false;
        _loadingMore = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = _items.isEmpty ? 'catalogLoadFailed' : null;
        _loading = false;
        _loadingMore = false;
      });
    }
  }

  bool _matchesMode(ChatCatalogItemModel item) {
    return switch (widget.mode) {
      'products' => item.itemKind == 'product',
      _ => item.itemKind != 'service',
    };
  }

  List<ChatCatalogItemModel> get _filteredItems {
    return _items.where((item) {
      if (!_matchesMode(item)) return false;
      return switch (_statusFilter) {
        'active' => item.isActive,
        'inactive' => !item.isActive,
        'in_stock' => item.stock == null || item.stock! > 0,
        'out_of_stock' => item.stock != null && item.stock! <= 0,
        _ => true,
      };
    }).toList();
  }

  String get _title => 'المنتجات';

  bool get _editorOpen => _creating || _selected != null;

  void _openCreate() {
    setState(() {
      _selected = null;
      _creating = true;
    });
  }

  void _openItem(ChatCatalogItemModel item) {
    setState(() {
      _selected = item;
      _creating = false;
    });
  }

  void _openCategories() {
    context.push('/catalog/categories');
  }

  void _closeEditor() {
    setState(() {
      _selected = null;
      _creating = false;
    });
  }

  String _errorText(AppLocalizations l10n) {
    if (_error == null) return '';
    if (_error == 'catalogLoadFailed') return l10n.catalogLoadFailed;
    return _error!;
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    ref.listen<String>(authControllerProvider.select((s) => s.isolationKey), (
      previous,
      next,
    ) {
      if (previous != null && previous != next) _load(reset: true);
    });

    final filterChips = SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      child: Row(
        children: [
          _CatalogFilterChip(
            label: 'الكل (${_items.length})',
            selected: _statusFilter == 'all',
            onTap: () => setState(() => _statusFilter = 'all'),
          ),
          const SizedBox(width: 8),
          _CatalogFilterChip(
            label: 'النشطة',
            selected: _statusFilter == 'active',
            onTap: () => setState(() => _statusFilter = 'active'),
          ),
          const SizedBox(width: 8),
          _CatalogFilterChip(
            label: 'المعطلة',
            selected: _statusFilter == 'inactive',
            onTap: () => setState(() => _statusFilter = 'inactive'),
          ),
          const SizedBox(width: 8),
          _CatalogFilterChip(
            label: 'المتوفرة',
            selected: _statusFilter == 'in_stock',
            onTap: () => setState(() => _statusFilter = 'in_stock'),
          ),
          const SizedBox(width: 8),
          _CatalogFilterChip(
            label: 'نفد المخزون',
            selected: _statusFilter == 'out_of_stock',
            onTap: () => setState(() => _statusFilter = 'out_of_stock'),
          ),
        ],
      ),
    );

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: widget.embedded
          ? null
          : AppBar(
              title: Row(
                children: [
                  Text(
                    _title,
                    style: const TextStyle(fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 8,
                      vertical: 2,
                    ),
                    decoration: BoxDecoration(
                      color: HasimTokens.chatGreen.withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Text(
                      '${_items.length}',
                      style: const TextStyle(
                        color: HasimTokens.chatGreen,
                        fontSize: 12,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                ],
              ),
              backgroundColor: HasimTokens.cardBg(context),
              surfaceTintColor: Colors.transparent,
              elevation: 0,
            ),
      body: LayoutBuilder(
        builder: (context, constraints) {
          final sideBySide = _editorOpen && constraints.maxWidth >= 720;
          final list = Column(
            children: [
              // Top Action Bar
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                child: Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    FilledButton.icon(
                      key: const Key('catalog-add-product'),
                      onPressed: _openCreate,
                      icon: const Icon(Icons.add_rounded, size: 18),
                      label: const Text('إضافة منتج'),
                      style: FilledButton.styleFrom(
                        backgroundColor: HasimTokens.chatGreen,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(
                          horizontal: 14,
                          vertical: 10,
                        ),
                      ),
                    ),
                    OutlinedButton.icon(
                      key: const Key('catalog-open-categories'),
                      onPressed: _openCategories,
                      icon: const Icon(Icons.category_outlined, size: 18),
                      label: Text(l10n.catalogCategoriesNav),
                      style: OutlinedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 12,
                          vertical: 10,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              // Search Bar
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 4),
                child: HasimSearchBar(
                  controller: _search,
                  hintText: l10n.catalogSearchHint,
                  flat: true,
                  onSubmitted: (_) => _load(reset: true),
                  onChanged: (val) {
                    if (val.isEmpty) _load(reset: true);
                  },
                  onClear: () {
                    _search.clear();
                    _load(reset: true);
                  },
                ),
              ),
              // Filter Chips
              if (_items.isNotEmpty) filterChips,
              Expanded(child: _body(l10n)),
            ],
          );
          if (!_editorOpen) return list;
          final editor = _editorPane(showClose: true);
          if (sideBySide) {
            return Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Expanded(flex: 4, child: list),
                const VerticalDivider(width: 1),
                Expanded(flex: 6, child: editor),
              ],
            );
          }
          return editor;
        },
      ),
    );
  }

  Widget _editorPane({required bool showClose}) {
    return Column(
      children: [
        if (showClose)
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: TextButton.icon(
              onPressed: _closeEditor,
              icon: const Icon(Icons.close_rounded, size: 18),
              label: const Text('إغلاق'),
            ),
          ),
        Expanded(
          child: CatalogFormScreen(
            key: ValueKey(
              _creating ? 'catalog-create' : 'catalog-edit-${_selected?.id}',
            ),
            item: _selected,
            embedded: true,
            onSaved: (saved) {
              setState(() {
                _selected = saved;
                _creating = false;
              });
              _load(reset: true);
            },
            onDeleted: (id) {
              setState(() {
                _items = _items.where((item) => item.id != id).toList();
                _selected = null;
                _creating = false;
              });
              _load(reset: true);
            },
          ),
        ),
      ],
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
    final displayItems = _filteredItems;
    if (displayItems.isEmpty) {
      if (_items.isEmpty) {
        return EmptyState(
          title: 'لا توجد منتجات',
          subtitle: 'أضف منتجاً ليظهر كمرجع للذكاء الاصطناعي.',
          icon: Icons.inventory_2_outlined,
          actionLabel: 'إضافة منتج',
          onAction: _openCreate,
        );
      }
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.search_off_rounded,
                size: 48,
                color: HasimTokens.mutedColor(context),
              ),
              const SizedBox(height: 12),
              const Text(
                'لا توجد منتجات تطابق الفلتر الحالي',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
              ),
              const SizedBox(height: 8),
              TextButton(
                onPressed: () => setState(() {
                  _statusFilter = 'all';
                  _search.clear();
                  _load(reset: true);
                }),
                child: const Text('عرض جميع المنتجات'),
              ),
            ],
          ),
        ),
      );
    }
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = HasimLayout.catalogGridCount(constraints.maxWidth);
        return RefreshIndicator(
          onRefresh: () => _load(reset: true),
          child: GridView.builder(
            controller: _scroll,
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: columns,
              mainAxisSpacing: 12,
              crossAxisSpacing: 12,
              childAspectRatio: columns <= 1 ? 1.35 : 0.70,
            ),
            itemCount: displayItems.length + (_loadingMore ? 1 : 0),
            itemBuilder: (context, index) {
              if (index >= displayItems.length) {
                return const Center(child: CircularProgressIndicator());
              }
              return _productCard(displayItems[index]);
            },
          ),
        );
      },
    );
  }

  Widget _productCard(ChatCatalogItemModel item) {
    final selected = !_creating && _selected?.id == item.id;
    final imageUrl = item.imageUrl;
    final dark = HasimTokens.isDark(context);
    final isOutOfStock = item.stock != null && item.stock! <= 0;

    return Material(
      color: selected
          ? HasimTokens.mintFill(context)
          : HasimTokens.cardBg(context),
      borderRadius: BorderRadius.circular(14),
      elevation: 0,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => _openItem(item),
        borderRadius: BorderRadius.circular(14),
        child: Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(14),
            border: Border.all(
              color: selected
                  ? HasimTokens.chatGreen
                  : HasimTokens.lineColor(context),
              width: selected ? 2 : 1,
            ),
            boxShadow: [
              BoxShadow(
                color: dark
                    ? Colors.black.withValues(alpha: 0.2)
                    : Colors.black.withValues(alpha: 0.04),
                blurRadius: 8,
                offset: const Offset(0, 2),
              ),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // Product Image container with Badges
              Expanded(
                flex: 5,
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    ColoredBox(
                      color: dark
                          ? HasimTokens.darkPage
                          : const Color(0xFFF7FCFB),
                      child: imageUrl == null || imageUrl.isEmpty
                          ? Center(
                              child: Icon(
                                Icons.inventory_2_outlined,
                                color: HasimTokens.mutedColor(context),
                                size: 36,
                              ),
                            )
                          : CachedNetworkImage(
                              imageUrl: imageUrl,
                              fit: BoxFit.cover,
                              memCacheWidth: 320,
                              memCacheHeight: 320,
                              placeholder: (_, _) => const Center(
                                child: SizedBox(
                                  width: 24,
                                  height: 24,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                  ),
                                ),
                              ),
                              errorWidget: (_, _, _) => Center(
                                child: Icon(
                                  Icons.inventory_2_outlined,
                                  color: HasimTokens.mutedColor(context),
                                  size: 36,
                                ),
                              ),
                            ),
                    ),
                    // Active / Inactive status badge
                    PositionedDirectional(
                      top: 8,
                      start: 8,
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 8,
                          vertical: 3,
                        ),
                        decoration: BoxDecoration(
                          color: item.isActive
                              ? const Color(0xFF16A34A).withValues(alpha: 0.92)
                              : const Color(0xFF64748B).withValues(alpha: 0.92),
                          borderRadius: BorderRadius.circular(8),
                          boxShadow: const [
                            BoxShadow(
                              color: Color(0x22000000),
                              blurRadius: 4,
                              offset: Offset(0, 1),
                            ),
                          ],
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Container(
                              width: 5,
                              height: 5,
                              decoration: const BoxDecoration(
                                color: Colors.white,
                                shape: BoxShape.circle,
                              ),
                            ),
                            const SizedBox(width: 4),
                            Text(
                              item.isActive ? 'نشط' : 'معطل',
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 10,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    // Stock badge (if tracked)
                    if (isOutOfStock ||
                        (item.stock != null && item.stock! > 0) ||
                        item.variants.isNotEmpty)
                      PositionedDirectional(
                        top: 8,
                        end: 8,
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 7,
                            vertical: 3,
                          ),
                          decoration: BoxDecoration(
                            color: isOutOfStock
                                ? const Color(
                                    0xFFDC2626,
                                  ).withValues(alpha: 0.92)
                                : Colors.black.withValues(alpha: 0.65),
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Text(
                            isOutOfStock
                                ? 'نفد المخزون'
                                : (item.variants.isNotEmpty
                                      ? '+${item.variants.length} خيارات'
                                      : 'المخزون: ${item.stock}'),
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 10,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
              ),

              // Details
              Expanded(
                flex: 4,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          if (item.category?.name.isNotEmpty == true) ...[
                            Text(
                              item.category!.name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                fontSize: 10,
                                fontWeight: FontWeight.w600,
                                color: HasimTokens.chatGreen,
                              ),
                            ),
                            const SizedBox(height: 2),
                          ],
                          Text(
                            item.name,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              fontWeight: FontWeight.w700,
                              fontSize: 13,
                              color: HasimTokens.inkColor(context),
                            ),
                          ),
                          if (item.sku != null && item.sku!.isNotEmpty)
                            Text(
                              '#${item.sku}',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                fontSize: 10,
                                color: HasimTokens.mutedColor(context),
                                fontFamily: 'monospace',
                              ),
                            ),
                        ],
                      ),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        crossAxisAlignment: CrossAxisAlignment.baseline,
                        textBaseline: TextBaseline.alphabetic,
                        children: [
                          Text(
                            '${formatCatalogMoney(item.price)} ${item.currency}',
                            style: const TextStyle(
                              fontSize: 13,
                              color: HasimTokens.chatGreen,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                          Icon(
                            Icons.edit_outlined,
                            size: 14,
                            color: HasimTokens.mutedColor(context),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CatalogFilterChip extends StatelessWidget {
  const _CatalogFilterChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final dark = HasimTokens.isDark(context);
    final bg = selected
        ? HasimTokens.chatGreen
        : (dark ? const Color(0xFF1E293B) : const Color(0xFFF1F5F9));
    final fg = selected
        ? Colors.white
        : (dark ? const Color(0xFFE2E8F0) : const Color(0xFF475569));
    final border = selected
        ? HasimTokens.chatGreen
        : (dark ? const Color(0xFF334155) : const Color(0xFFE2E8F0));

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 180),
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
          decoration: BoxDecoration(
            color: bg,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: border),
          ),
          child: Text(
            label,
            style: TextStyle(
              color: fg,
              fontSize: 12,
              fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
            ),
          ),
        ),
      ),
    );
  }
}
