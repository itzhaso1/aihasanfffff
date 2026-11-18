import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/hasim_search_bar.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';

class CustomersListScreen extends ConsumerStatefulWidget {
  const CustomersListScreen({super.key});

  @override
  ConsumerState<CustomersListScreen> createState() =>
      _CustomersListScreenState();
}

class _CustomersListScreenState extends ConsumerState<CustomersListScreen> {
  final _searchController = TextEditingController();
  List<CustomerModel> _customers = [];
  bool _loading = true;
  String? _error;
  String _search = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final repo = ref.read(customerRepositoryProvider);
      final items = await repo.list(search: _search);
      if (!mounted) return;
      setState(() {
        _customers = items;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = 'تعذر تحميل قائمة العملاء. يرجى المحاولة لاحقاً.';
        _loading = false;
      });
    }
  }

  void _onSearch(String value) {
    _search = value;
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final isDesktop = !HasimLayout.isCompact(context);

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: const Text(
          'العملاء',
          style: TextStyle(fontWeight: FontWeight.bold),
        ),
        backgroundColor: HasimTokens.cardBg(context),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        actions: [
          IconButton(
            tooltip: 'إضافة عميل جديد',
            icon: const Icon(Icons.person_add_alt_1_rounded),
            onPressed: () async {
              final created = await context.push<bool>('/customers/create');
              if (created == true) _load();
            },
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        key: const Key('add-customer-fab'),
        onPressed: () async {
          final created = await context.push<bool>('/customers/create');
          if (created == true) _load();
        },
        backgroundColor: HasimTokens.chatGreen,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.person_add_rounded),
        label: const Text(
          'عميل جديد',
          style: TextStyle(fontWeight: FontWeight.bold),
        ),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        color: HasimTokens.chatGreen,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
              child: HasimSearchBar(
                controller: _searchController,
                hintText: 'ابحث بالاسم، رقم الهاتف، أو البريد...',
                flat: true,
                onSubmitted: _onSearch,
                onChanged: (v) {
                  if (v.isEmpty && _search.isNotEmpty) {
                    _onSearch('');
                  }
                },
                onClear: () {
                  _searchController.clear();
                  _onSearch('');
                },
              ),
            ),
            Expanded(child: _buildBody(theme, isDesktop)),
          ],
        ),
      ),
    );
  }

  Widget _buildBody(ThemeData theme, bool isDesktop) {
    if (_loading && _customers.isEmpty) {
      return const SkeletonList();
    }

    if (_error != null && _customers.isEmpty) {
      return EmptyState(
        title: _error!,
        actionLabel: 'إعادة المحاولة',
        onAction: _load,
      );
    }

    if (_customers.isEmpty) {
      return EmptyState(
        title: _search.isEmpty ? 'لا يوجد عملاء بعد' : 'لا توجد نتائج مطابقة',
        subtitle: _search.isEmpty
            ? 'أضف أول عميل لإدارة معلوماته وسجل محادثاته وطلباته بكل سهولة.'
            : 'جرب البحث بكلمات أخرى أو تحقق من كتابة الاسم ورقم الهاتف.',
        actionLabel: _search.isEmpty ? 'إضافة عميل جديد' : null,
        onAction: () async {
          final created = await context.push<bool>('/customers/create');
          if (created == true) _load();
        },
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      itemCount: _customers.length,
      separatorBuilder: (_, _) => const SizedBox(height: 8),
      itemBuilder: (context, index) {
        final c = _customers[index];
        return _CustomerCard(
          customer: c,
          onTap: () async {
            await context.push('/customers/${c.id}', extra: c);
            _load();
          },
          onEdit: () async {
            final updated = await context.push<bool>(
              '/customers/${c.id}/edit',
              extra: c,
            );
            if (updated == true) _load();
          },
        );
      },
    );
  }
}

class _CustomerCard extends StatelessWidget {
  const _CustomerCard({
    required this.customer,
    required this.onTap,
    required this.onEdit,
  });

  final CustomerModel customer;
  final VoidCallback onTap;
  final VoidCallback onEdit;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final initial = customer.name.isNotEmpty
        ? customer.name.characters.first.toUpperCase()
        : '?';

    return Material(
      color: HasimTokens.cardBg(context),
      borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
            border: Border.all(
              color: HasimTokens.lineColor(context),
              width: 0.8,
            ),
          ),
          child: Row(
            children: [
              CircleAvatar(
                radius: 24,
                backgroundColor: HasimTokens.chatGreen.withValues(alpha: 0.12),
                child: Text(
                  initial,
                  style: const TextStyle(
                    color: HasimTokens.chatGreen,
                    fontWeight: FontWeight.w700,
                    fontSize: 18,
                  ),
                ),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            customer.name,
                            style: theme.textTheme.titleMedium?.copyWith(
                              fontWeight: FontWeight.w700,
                            ),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 2,
                          ),
                          decoration: BoxDecoration(
                            color: customer.status == 'needs_review'
                                ? Colors.amber.withValues(alpha: 0.15)
                                : HasimTokens.mintFill(context),
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: Text(
                            customer.displayStatus,
                            style: TextStyle(
                              fontSize: 10,
                              fontWeight: FontWeight.w700,
                              color: customer.status == 'needs_review'
                                  ? Colors.amber.shade900
                                  : HasimTokens.chatGreen,
                            ),
                          ),
                        ),
                        if (customer.ordersCount > 0) ...[
                          const SizedBox(width: 4),
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 8,
                              vertical: 2,
                            ),
                            decoration: BoxDecoration(
                              color: HasimTokens.mintFill(context),
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: Text(
                              '${customer.ordersCount} طلب',
                              style: const TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w700,
                                color: HasimTokens.chatGreen,
                              ),
                            ),
                          ),
                        ],
                      ],
                    ),
                    const SizedBox(height: 4),
                    if (customer.phone != null && customer.phone!.isNotEmpty)
                      Row(
                        children: [
                          Icon(
                            Icons.phone_outlined,
                            size: 14,
                            color: HasimTokens.mutedColor(context),
                          ),
                          const SizedBox(width: 6),
                          Directionality(
                            textDirection: TextDirection.ltr,
                            child: Text(
                              customer.phone!,
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: HasimTokens.mutedColor(context),
                              ),
                            ),
                          ),
                        ],
                      ),
                    if (customer.email != null &&
                        customer.email!.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Row(
                        children: [
                          Icon(
                            Icons.mail_outline,
                            size: 14,
                            color: HasimTokens.mutedColor(context),
                          ),
                          const SizedBox(width: 6),
                          Expanded(
                            child: Text(
                              customer.email!,
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: HasimTokens.mutedColor(context),
                              ),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 8),
              IconButton(
                icon: Icon(
                  Icons.edit_outlined,
                  size: 20,
                  color: HasimTokens.mutedColor(context),
                ),
                tooltip: 'تعديل بيانات العميل',
                onPressed: onEdit,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
