import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/core/widgets/hasim_search_bar.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/worker/presentation/worker_inbox_workspace_selector.dart';
import 'package:hasim/features/worker/presentation/worker_scaffold.dart';
import 'package:hasim/features/worker/providers/worker_sales_reference_controller.dart';
import 'package:hasim/router/worker_nav.dart';

class WorkerSalesReferenceScreen extends ConsumerStatefulWidget {
  const WorkerSalesReferenceScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  ConsumerState<WorkerSalesReferenceScreen> createState() =>
      _WorkerSalesReferenceScreenState();
}

class _WorkerSalesReferenceScreenState
    extends ConsumerState<WorkerSalesReferenceScreen> {
  final _search = TextEditingController();
  String _query = '';

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(workerSalesReferenceControllerProvider);
    final body = Material(
      color: HasimTokens.pageBg(context),
      child: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Padding(
              padding: EdgeInsets.fromLTRB(20, 12, 20, 4),
              child: HasimPageHeader(
                title: 'مرجع المبيعات',
                subtitle: 'منتجات وخدمات الشركة الحالية',
                icon: Icons.menu_book_outlined,
              ),
            ),
            if (!widget.embedded)
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
                child: WorkerInboxWorkspacePicker(newMessagesCount: 0),
              ),
            if (state.workspaceId != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
                child: HasimSearchBar(
                  controller: _search,
                  hintText: 'ابحث عن منتج أو خدمة',
                  flat: true,
                  onChanged: (value) => setState(() => _query = value),
                  onClear: () => setState(() => _query = ''),
                ),
              ),
            Expanded(child: _content(context, state)),
          ],
        ),
      ),
    );

    if (widget.embedded) return body;

    return WorkerScaffold(selectedIndex: WorkerNav.accountIndex, body: body);
  }

  Widget _content(BuildContext context, WorkerSalesReferenceState state) {
    if (state.workspaceId == null) {
      return const EmptyState(
        icon: Icons.business_outlined,
        title: 'اختر شركة أولاً',
        subtitle: 'اختر الشركة الحالية لعرض منتجاتها وخدماتها.',
      );
    }
    if (state.loading && state.items.isEmpty) {
      return const SkeletonList();
    }
    if (state.permissionDenied && state.items.isEmpty) {
      return const EmptyState(
        icon: Icons.lock_outline_rounded,
        title: 'لا تملك صلاحية عرض المنتجات',
        subtitle: 'اطلب تفعيل صلاحية مرجع المبيعات لهذا العمل.',
      );
    }
    if (state.error != null && state.items.isEmpty) {
      return EmptyState(
        icon: Icons.menu_book_outlined,
        title: 'تعذر تحميل مرجع المبيعات',
        subtitle: state.error,
        actionLabel: 'إعادة المحاولة',
        onAction: () => ref
            .read(workerSalesReferenceControllerProvider.notifier)
            .load(force: true),
      );
    }

    final controller = ref.read(
      workerSalesReferenceControllerProvider.notifier,
    );
    return DefaultTabController(
      length: 2,
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 4),
            child: Material(
              color: Colors.transparent,
              child: TabBar(
                labelColor: HasimTokens.accentDark,
                unselectedLabelColor: HasimTokens.mutedColor(context),
                indicatorColor: HasimTokens.primary,
                dividerColor: HasimTokens.lineColor(context),
                tabs: const [
                  Tab(text: 'المنتجات'),
                  Tab(text: 'الخدمات'),
                ],
              ),
            ),
          ),
          Expanded(
            child: TabBarView(
              children: [
                _ReferenceItemsList(
                  items: _filter(state.products),
                  emptyTitle: 'لا توجد منتجات متاحة',
                  onRefresh: () => controller.load(force: true),
                  onOpen: _showDetails,
                ),
                _ReferenceItemsList(
                  items: _filter(state.services),
                  emptyTitle: 'لا توجد خدمات متاحة',
                  onRefresh: () => controller.load(force: true),
                  onOpen: _showDetails,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  List<ChatCatalogItemModel> _filter(List<ChatCatalogItemModel> items) {
    final query = _query.trim().toLowerCase();
    if (query.isEmpty) return items;
    return items
        .where(
          (item) =>
              item.name.toLowerCase().contains(query) ||
              (item.description?.toLowerCase().contains(query) ?? false) ||
              (item.sku?.toLowerCase().contains(query) ?? false),
        )
        .toList();
  }

  Future<void> _showDetails(
    BuildContext context,
    ChatCatalogItemModel item,
  ) async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      backgroundColor: HasimTokens.cardBg(context),
      builder: (context) => _ReferenceItemDetails(item: item),
    );
  }
}

class _ReferenceItemsList extends StatelessWidget {
  const _ReferenceItemsList({
    required this.items,
    required this.emptyTitle,
    required this.onRefresh,
    required this.onOpen,
  });

  final List<ChatCatalogItemModel> items;
  final String emptyTitle;
  final Future<void> Function() onRefresh;
  final Future<void> Function(BuildContext context, ChatCatalogItemModel item)
  onOpen;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) {
      return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 32),
        children: [
          EmptyState(
            icon: Icons.inventory_2_outlined,
            title: emptyTitle,
            subtitle: 'ستظهر هنا المعلومات المتاحة للبيع من الشركة.',
          ),
        ],
      );
    }

    return RefreshIndicator(
      color: AppTheme.brand,
      onRefresh: onRefresh,
      child: ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
        itemCount: items.length,
        separatorBuilder: (_, _) => const SizedBox(height: 10),
        itemBuilder: (context, index) {
          final item = items[index];
          return _ReferenceItemCard(
            item: item,
            onTap: () => onOpen(context, item),
          );
        },
      ),
    );
  }
}

class _ReferenceItemCard extends StatelessWidget {
  const _ReferenceItemCard({required this.item, required this.onTap});

  final ChatCatalogItemModel item;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final imageUrl = item.imageUrl;
    final isService = item.itemKind == 'service';
    return Material(
      color: HasimTokens.cardBg(context),
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: SizedBox(
                  width: 72,
                  height: 72,
                  child: imageUrl == null || imageUrl.isEmpty
                      ? ColoredBox(
                          color: HasimTokens.mintFill(context),
                          child: Icon(
                            isService
                                ? Icons.design_services_outlined
                                : Icons.inventory_2_outlined,
                            color: HasimTokens.primary,
                            size: 30,
                          ),
                        )
                      : CachedNetworkImage(
                          imageUrl: imageUrl,
                          fit: BoxFit.cover,
                          placeholder: (_, _) => const Center(
                            child: CircularProgressIndicator(strokeWidth: 2),
                          ),
                          errorWidget: (_, _, _) => ColoredBox(
                            color: HasimTokens.mintFill(context),
                            child: Icon(
                              isService
                                  ? Icons.design_services_outlined
                                  : Icons.inventory_2_outlined,
                              color: HasimTokens.primary,
                              size: 30,
                            ),
                          ),
                        ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            item.name,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              color: HasimTokens.inkColor(context),
                              fontWeight: FontWeight.w800,
                              fontSize: 15,
                            ),
                          ),
                        ),
                        const SizedBox(width: 8),
                        _ReferenceKindChip(label: isService ? 'خدمة' : 'منتج'),
                      ],
                    ),
                    if (item.description != null &&
                        item.description!.trim().isNotEmpty) ...[
                      const SizedBox(height: 6),
                      Text(
                        item.description!,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: HasimTokens.mutedColor(context),
                          height: 1.35,
                        ),
                      ),
                    ],
                    const SizedBox(height: 8),
                    Text(
                      _referencePrice(item),
                      style: TextStyle(
                        color: AppTheme.brand,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
              ),
              const Icon(Icons.chevron_left_rounded),
            ],
          ),
        ),
      ),
    );
  }
}

class _ReferenceKindChip extends StatelessWidget {
  const _ReferenceKindChip({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.mintFill(context),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        child: Text(
          label,
          style: TextStyle(
            color: HasimTokens.accentDark,
            fontSize: 11,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    );
  }
}

class _ReferenceItemDetails extends StatelessWidget {
  const _ReferenceItemDetails({required this.item});

  final ChatCatalogItemModel item;

  @override
  Widget build(BuildContext context) {
    final isService = item.itemKind == 'service';
    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 28),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              item.name,
              style: TextStyle(
                color: HasimTokens.inkColor(context),
                fontSize: 22,
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 8),
            _ReferenceInfoRow(
              label: 'النوع',
              value: isService ? 'خدمة' : 'منتج',
            ),
            _ReferenceInfoRow(label: 'السعر', value: _referencePrice(item)),
            if (item.sku != null && item.sku!.trim().isNotEmpty)
              _ReferenceInfoRow(label: 'الرمز', value: item.sku!),
            if (item.category?.name.trim().isNotEmpty == true)
              _ReferenceInfoRow(label: 'التصنيف', value: item.category!.name),
            if (item.description != null &&
                item.description!.trim().isNotEmpty) ...[
              const SizedBox(height: 12),
              Text(
                'الوصف',
                style: TextStyle(
                  color: HasimTokens.inkColor(context),
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                item.description!,
                style: TextStyle(
                  color: HasimTokens.mutedColor(context),
                  height: 1.45,
                ),
              ),
            ],
            if (item.variants.isNotEmpty) ...[
              const SizedBox(height: 16),
              Text(
                'الخيارات',
                style: TextStyle(
                  color: HasimTokens.inkColor(context),
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 6),
              for (final variant in item.variants)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(variant.name),
                  subtitle: variant.sku == null ? null : Text(variant.sku!),
                  trailing: variant.price == null
                      ? null
                      : Text(
                          '${variant.price} ${variant.currency ?? item.currency}',
                        ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}

class _ReferenceInfoRow extends StatelessWidget {
  const _ReferenceInfoRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 72,
            child: Text(
              label,
              style: TextStyle(color: HasimTokens.mutedColor(context)),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: TextStyle(
                color: HasimTokens.inkColor(context),
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

String _referencePrice(ChatCatalogItemModel item) {
  final minimum = item.workerMinimumPrice;
  final maximum = item.workerMaximumPrice;
  if (minimum != null && maximum != null && minimum != maximum) {
    return 'من $minimum إلى $maximum ${item.currency}';
  }
  final value = minimum ?? maximum ?? item.price;
  return '$value ${item.currency}';
}
