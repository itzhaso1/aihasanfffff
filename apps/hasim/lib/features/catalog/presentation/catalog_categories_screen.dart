import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/presentation/catalog_category_form_screen.dart';
import 'package:hasim/features/catalog/providers/chat_catalog_categories_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';

class CatalogCategoriesScreen extends ConsumerStatefulWidget {
  const CatalogCategoriesScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  ConsumerState<CatalogCategoriesScreen> createState() =>
      _CatalogCategoriesScreenState();
}

class _CatalogCategoriesScreenState
    extends ConsumerState<CatalogCategoriesScreen> {
  ChatCatalogCategoryModel? _selected;
  bool _creating = true;

  bool get _editorOpen => _creating || _selected != null;

  void _openCreate() {
    setState(() {
      _selected = null;
      _creating = true;
    });
  }

  void _openItem(ChatCatalogCategoryModel category) {
    setState(() {
      _selected = category;
      _creating = false;
    });
  }

  void _closeEditor() {
    setState(() {
      _selected = null;
      _creating = false;
    });
  }

  String _localizedError(AppLocalizations l10n, String? code) {
    if (code == null) return '';
    return switch (code) {
      'catalogCategoriesLoadFailed' => l10n.catalogCategoriesLoadFailed,
      'catalogCategorySaveFailed' => l10n.catalogCategorySaveFailed,
      'catalogCategoryDeleteFailed' => l10n.catalogCategoryDeleteFailed,
      _ => code,
    };
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final state = ref.watch(chatCatalogCategoriesControllerProvider);
    final notifier = ref.read(chatCatalogCategoriesControllerProvider.notifier);

    return Scaffold(
      backgroundColor: widget.embedded ? Colors.transparent : null,
      appBar: widget.embedded
          ? null
          : AppBar(
              title: Text(l10n.catalogCategoriesTitle),
              actions: [
                IconButton(
                  tooltip: l10n.retry,
                  onPressed: state.loading ? null : () => notifier.reload(),
                  icon: const Icon(Icons.refresh_rounded),
                ),
              ],
            ),
      floatingActionButton: _editorOpen
          ? null
          : FloatingActionButton(
              onPressed: state.saving ? null : _openCreate,
              child: const Icon(Icons.add),
            ),
      body: LayoutBuilder(
        builder: (context, constraints) {
          final sideBySide = _editorOpen && constraints.maxWidth >= 720;
          final list = _listPane(context, l10n, state, notifier);
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
          child: CatalogCategoryFormScreen(
            key: ValueKey(
              _creating ? 'category-create' : 'category-edit-${_selected?.id}',
            ),
            category: _selected,
            embedded: true,
            onSaved: () => ref
                .read(chatCatalogCategoriesControllerProvider.notifier)
                .reload(),
          ),
        ),
      ],
    );
  }

  Widget _listPane(
    BuildContext context,
    AppLocalizations l10n,
    ChatCatalogCategoriesState state,
    ChatCatalogCategoriesController notifier,
  ) {
    return Column(
      children: [
        if (widget.embedded)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    l10n.catalogCategoriesTitle,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
                IconButton(
                  tooltip: l10n.catalogCategoryAdd,
                  onPressed: state.saving ? null : _openCreate,
                  icon: const Icon(Icons.add_rounded),
                ),
              ],
            ),
          ),
        Expanded(child: _body(context, l10n, state, notifier)),
      ],
    );
  }

  Widget _body(
    BuildContext context,
    AppLocalizations l10n,
    ChatCatalogCategoriesState state,
    ChatCatalogCategoriesController notifier,
  ) {
    if (state.loading && state.items.isEmpty) {
      return const SkeletonList();
    }
    if (state.error != null && state.items.isEmpty) {
      return EmptyState(
        title: l10n.emptyTitle,
        subtitle: _localizedError(l10n, state.error),
        actionLabel: l10n.retry,
        onAction: () => notifier.reload(),
      );
    }
    if (state.items.isEmpty) {
      return EmptyState(
        title: l10n.catalogCategoriesEmptyTitle,
        subtitle: l10n.catalogCategoriesEmptySubtitle,
        actionLabel: l10n.catalogCategoryAdd,
        onAction: _openCreate,
      );
    }

    return RefreshIndicator(
      onRefresh: () => notifier.reload(),
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 88),
        itemCount: state.items.length + (state.nextCursor != null ? 1 : 0),
        separatorBuilder: (_, _) => const SizedBox(height: 8),
        itemBuilder: (context, index) {
          if (index >= state.items.length) {
            if (state.loadingMore) {
              return const Padding(
                padding: EdgeInsets.all(16),
                child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
              );
            }
            WidgetsBinding.instance.addPostFrameCallback(
              (_) => notifier.loadMore(),
            );
            return const SizedBox.shrink();
          }
          final category = state.items[index];
          return _CategoryTile(
            category: category,
            selected: !_creating && _selected?.id == category.id,
            onTap: () => _openItem(category),
          );
        },
      ),
    );
  }
}

class _CategoryTile extends ConsumerWidget {
  const _CategoryTile({
    required this.category,
    required this.onTap,
    this.selected = false,
  });

  final ChatCatalogCategoryModel category;
  final VoidCallback onTap;
  final bool selected;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final notifier = ref.read(chatCatalogCategoriesControllerProvider.notifier);

    return Material(
      color: selected
          ? HasimTokens.mintFill(context)
          : HasimTokens.cardBg(context),
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      category.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: HasimTokens.inkColor(context),
                      ),
                    ),
                    if (category.description != null &&
                        category.description!.isNotEmpty)
                      Text(
                        category.description!,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: HasimTokens.mutedColor(context),
                        ),
                      ),
                    const SizedBox(height: 4),
                    Text(
                      category.isActive
                          ? l10n.catalogCategoryActive
                          : l10n.catalogCategoryInactive,
                      style: Theme.of(context).textTheme.labelSmall?.copyWith(
                        color: category.isActive
                            ? AppTheme.brand
                            : HasimTokens.mutedColor(context),
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ),
              ),
              IconButton(
                tooltip: l10n.catalogCategoryDelete,
                onPressed: () async {
                  final confirm = await showDialog<bool>(
                    context: context,
                    builder: (ctx) => AlertDialog(
                      title: Text(l10n.catalogCategoryDelete),
                      content: Text(l10n.catalogCategoryDeleteConfirm),
                      actions: [
                        TextButton(
                          onPressed: () => Navigator.pop(ctx, false),
                          child: Text(l10n.catalogCancel),
                        ),
                        TextButton(
                          onPressed: () => Navigator.pop(ctx, true),
                          child: Text(l10n.catalogCategoryDelete),
                        ),
                      ],
                    ),
                  );
                  if (confirm != true || !context.mounted) return;
                  final ok = await notifier.deleteCategory(category.id);
                  if (!context.mounted) return;
                  if (!ok) {
                    final err = ref
                        .read(chatCatalogCategoriesControllerProvider)
                        .error;
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text(err ?? l10n.catalogCategoryDeleteFailed),
                      ),
                    );
                  }
                },
                icon: const Icon(Icons.delete_outline_rounded),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
