import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/widgets/hasim_primary_button.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/presentation/widgets/catalog_form_fields.dart';
import 'package:hasim/features/catalog/providers/chat_catalog_categories_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';

class CatalogCategoryFormScreen extends ConsumerStatefulWidget {
  const CatalogCategoryFormScreen({
    super.key,
    this.category,
    this.embedded = false,
    this.onSaved,
  });

  final ChatCatalogCategoryModel? category;
  final bool embedded;
  final VoidCallback? onSaved;

  @override
  ConsumerState<CatalogCategoryFormScreen> createState() =>
      _CatalogCategoryFormScreenState();
}

class _CatalogCategoryFormScreenState
    extends ConsumerState<CatalogCategoryFormScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _name;
  late final TextEditingController _description;
  late final TextEditingController _sortOrder;
  bool _active = true;
  int? _parentId;

  bool get _editing => widget.category != null;

  @override
  void initState() {
    super.initState();
    final c = widget.category;
    _name = TextEditingController(text: c?.name ?? '');
    _description = TextEditingController(text: c?.description ?? '');
    _sortOrder = TextEditingController(text: '${c?.sortOrder ?? 0}');
    _active = c?.isActive ?? true;
    _parentId = c?.parentId;
  }

  @override
  void dispose() {
    _name.dispose();
    _description.dispose();
    _sortOrder.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    final l10n = AppLocalizations.of(context);
    final draft = ChatCatalogCategoryModel(
      id: widget.category?.id ?? 0,
      name: _name.text.trim(),
      description: _description.text.trim().isEmpty
          ? null
          : _description.text.trim(),
      parentId: _parentId,
      isActive: _active,
      sortOrder: int.tryParse(_sortOrder.text.trim()) ?? 0,
    );
    final ok = await ref
        .read(chatCatalogCategoriesControllerProvider.notifier)
        .saveCategory(draft, id: _editing ? widget.category!.id : null);
    if (!mounted) return;
    if (ok) {
      widget.onSaved?.call();
      if (widget.embedded) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text('تم حفظ التصنيف')));
        return;
      }
      context.pop(true);
      return;
    }
    final err = ref.read(chatCatalogCategoriesControllerProvider).error;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(err ?? l10n.catalogCategorySaveFailed)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final saving = ref.watch(
      chatCatalogCategoriesControllerProvider.select((s) => s.saving),
    );
    final categories = ref.watch(
      chatCatalogCategoriesControllerProvider.select((s) => s.items),
    );
    final parentOptions = categories
        .where((c) => c.id != widget.category?.id)
        .toList();

    return Scaffold(
      backgroundColor: widget.embedded ? Colors.transparent : null,
      appBar: widget.embedded
          ? null
          : AppBar(
              title: Text(
                _editing ? l10n.catalogCategoryEdit : l10n.catalogCategoryAdd,
              ),
            ),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            CatalogFieldCard(
              dense: true,
              label: l10n.catalogCategoryName,
              icon: Icons.category_outlined,
              child: TextFormField(
                key: const Key('catalog-category-name'),
                controller: _name,
                validator: (v) =>
                    (v == null || v.trim().isEmpty) ? l10n.required : null,
                decoration: catalogInputDecoration(context),
              ),
            ),
            const SizedBox(height: 8),
            CatalogFieldCard(
              label: l10n.catalogCategoryDescription,
              icon: Icons.notes_outlined,
              child: TextFormField(
                controller: _description,
                minLines: 2,
                maxLines: 4,
                decoration: catalogInputDecoration(context),
              ),
            ),
            const SizedBox(height: 8),
            CatalogSelectField<int?>(
              dense: true,
              label: l10n.catalogCategoryParent,
              icon: Icons.account_tree_outlined,
              value: _parentId,
              onChanged: (v) => setState(() => _parentId = v),
              items: [
                DropdownMenuItem<int?>(
                  value: null,
                  child: Text(l10n.catalogCategoryNoParent),
                ),
                for (final c in parentOptions)
                  DropdownMenuItem<int?>(value: c.id, child: Text(c.name)),
              ],
            ),
            const SizedBox(height: 8),
            CatalogFieldCard(
              dense: true,
              label: l10n.catalogCategorySortOrder,
              icon: Icons.sort_rounded,
              child: TextFormField(
                controller: _sortOrder,
                keyboardType: TextInputType.number,
                decoration: catalogInputDecoration(context),
              ),
            ),
            const SizedBox(height: 12),
            SwitchListTile(
              title: Text(l10n.catalogCategoryActive),
              value: _active,
              onChanged: saving ? null : (v) => setState(() => _active = v),
            ),
            const SizedBox(height: 24),
            HasimPrimaryButton(
              key: const Key('catalog-category-save'),
              label: saving ? l10n.catalogSaving : l10n.save,
              icon: Icons.save_outlined,
              expanded: true,
              busy: saving,
              enabled: !saving,
              onPressed: _save,
            ),
          ],
        ),
      ),
    );
  }
}
