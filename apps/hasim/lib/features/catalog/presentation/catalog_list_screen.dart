import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
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
      final cached = await repo.cachedItems(search: _search.text.trim(), itemKind: kind);
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
    return Scaffold(
      appBar: widget.embedded ? null : AppBar(title: Text(_title)),
      body: LayoutBuilder(
        builder: (context, constraints) {
          final sideBySide = _editorOpen && constraints.maxWidth >= 720;
          final list = Column(
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                child: Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    FilledButton.icon(
                      key: const Key('catalog-add-product'),
                      onPressed: _openCreate,
                      icon: const Icon(Icons.add),
                      label: const Text('إضافة منتج'),
                    ),
                    TextButton(
                      key: const Key('catalog-open-categories'),
                      onPressed: _openCategories,
                      child: Text(l10n.catalogCategoriesNav),
                    ),
                  ],
                ),
              ),
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
    if (_items.isEmpty) {
      return EmptyState(
        title: 'لا توجد منتجات',
        subtitle: 'أضف منتجاً ليظهر كمرجع للذكاء الاصطناعي.',
        icon: Icons.inventory_2_outlined,
        actionLabel: 'إضافة منتج',
        onAction: _openCreate,
      );
    }
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = HasimLayout.catalogGridCount(constraints.maxWidth);
        return RefreshIndicator(
          onRefresh: () => _load(reset: true),
          child: GridView.builder(
            controller: _scroll,
            padding: const EdgeInsets.fromLTRB(12, 4, 12, 16),
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: columns,
              mainAxisSpacing: 10,
              crossAxisSpacing: 10,
              childAspectRatio: HasimLayout.catalogCardAspectRatio(columns),
            ),
            itemCount: _items.length + (_loadingMore ? 1 : 0),
            itemBuilder: (context, index) {
              if (index >= _items.length) {
                return const Center(child: CircularProgressIndicator());
              }
              return _productCard(_items[index]);
            },
          ),
        );
      },
    );
  }

  Widget _productCard(ChatCatalogItemModel item) {
    final selected = !_creating && _selected?.id == item.id;
    final imageUrl = item.imageUrl;
    return Material(
      color: selected
          ? HasimTokens.mintFill(context)
          : HasimTokens.cardBg(context),
      borderRadius: BorderRadius.circular(10),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => _openItem(item),
        child: DecoratedBox(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(10),
            border: Border.all(
              color: selected
                  ? HasimTokens.chatGreen
                  : HasimTokens.lineColor(context),
            ),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: ColoredBox(
                  color: HasimTokens.isDark(context)
                      ? HasimTokens.darkPage
                      : const Color(0xFFF7FCFB),
                  child: imageUrl == null || imageUrl.isEmpty
                      ? Icon(
                          Icons.image_outlined,
                          color: HasimTokens.mutedColor(context),
                          size: 36,
                        )
                      : CachedNetworkImage(
                          imageUrl: imageUrl,
                          fit: BoxFit.cover,
                          placeholder: (_, _) => const Center(
                            child: SizedBox(
                              width: 22,
                              height: 22,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            ),
                          ),
                          errorWidget: (_, _, _) => Icon(
                            Icons.image_outlined,
                            color: HasimTokens.mutedColor(context),
                            size: 36,
                          ),
                        ),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(8, 8, 8, 10),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
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
                    const SizedBox(height: 2),
                    Text(
                      '${item.price} ${item.currency}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontSize: 12,
                        color: item.isActive
                            ? AppTheme.brand
                            : Colors.orange,
                        fontWeight: FontWeight.w600,
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
