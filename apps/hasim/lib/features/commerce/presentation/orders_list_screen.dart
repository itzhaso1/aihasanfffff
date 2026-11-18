import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/commerce/data/commerce_order_models.dart';

class OrdersListScreen extends ConsumerStatefulWidget {
  const OrdersListScreen({super.key});

  @override
  ConsumerState<OrdersListScreen> createState() => _OrdersListScreenState();
}

class _OrdersListScreenState extends ConsumerState<OrdersListScreen> {
  List<CommerceOrderModel> _orders = [];
  bool _loading = true;
  String? _error;
  String _selectedStatus = '';

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
      final repo = ref.read(commerceOrderRepositoryProvider);
      final items = await repo.list(
        status: _selectedStatus.isEmpty ? null : _selectedStatus,
      );
      if (!mounted) return;
      setState(() {
        _orders = items;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = 'تعذر تحميل قائمة الطلبات. يرجى المحاولة لاحقاً.';
        _loading = false;
      });
    }
  }

  void _onStatusFilter(String status) {
    if (_selectedStatus == status) return;
    setState(() => _selectedStatus = status);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: const Text(
          'الطلبات والمبيعات',
          style: TextStyle(fontWeight: FontWeight.bold),
        ),
        backgroundColor: HasimTokens.cardBg(context),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        color: HasimTokens.chatGreen,
        child: Column(
          children: [
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              child: Row(
                children: [
                  _FilterChip(
                    label: 'الكل',
                    selected: _selectedStatus.isEmpty,
                    onTap: () => _onStatusFilter(''),
                  ),
                  const SizedBox(width: 8),
                  _FilterChip(
                    label: 'بانتظار الدفع',
                    selected: _selectedStatus == 'pending_payment',
                    onTap: () => _onStatusFilter('pending_payment'),
                  ),
                  const SizedBox(width: 8),
                  _FilterChip(
                    label: 'قيد المعالجة',
                    selected: _selectedStatus == 'processing',
                    onTap: () => _onStatusFilter('processing'),
                  ),
                  const SizedBox(width: 8),
                  _FilterChip(
                    label: 'مكتمل',
                    selected: _selectedStatus == 'completed',
                    onTap: () => _onStatusFilter('completed'),
                  ),
                  const SizedBox(width: 8),
                  _FilterChip(
                    label: 'ملغي',
                    selected: _selectedStatus == 'cancelled',
                    onTap: () => _onStatusFilter('cancelled'),
                  ),
                ],
              ),
            ),
            Expanded(child: _buildBody(theme)),
          ],
        ),
      ),
    );
  }

  Widget _buildBody(ThemeData theme) {
    if (_loading && _orders.isEmpty) {
      return const SkeletonList();
    }

    if (_error != null && _orders.isEmpty) {
      return EmptyState(
        title: _error!,
        actionLabel: 'إعادة المحاولة',
        onAction: _load,
      );
    }

    if (_orders.isEmpty) {
      return EmptyState(
        title: _selectedStatus.isEmpty
            ? 'لا توجد طلبات بعد'
            : 'لا توجد طلبات بهذه الحالة',
        subtitle: _selectedStatus.isEmpty
            ? 'عند إنشاء طلبات أو إتمام عمليات شراء عبر المحادثات والكتالوج، ستظهر هنا بالتفصيل.'
            : 'اختر تصفية أخرى لعرض الطلبات المتوفرة.',
        actionLabel: _selectedStatus.isNotEmpty ? 'عرض كل الطلبات' : null,
        onAction: () => _onStatusFilter(''),
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      itemCount: _orders.length,
      separatorBuilder: (_, _) => const SizedBox(height: 10),
      itemBuilder: (context, index) {
        final order = _orders[index];
        return _OrderCard(
          order: order,
          onTap: () async {
            await context.push('/orders/${order.id}', extra: order);
            _load();
          },
        );
      },
    );
  }
}

class _FilterChip extends StatelessWidget {
  const _FilterChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: selected ? HasimTokens.chatGreen : HasimTokens.cardBg(context),
      borderRadius: BorderRadius.circular(20),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(20),
            border: Border.all(
              color: selected
                  ? HasimTokens.chatGreen
                  : HasimTokens.lineColor(context),
              width: 0.8,
            ),
          ),
          child: Text(
            label,
            style: TextStyle(
              fontSize: 13,
              fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
              color: selected ? Colors.white : HasimTokens.inkColor(context),
            ),
          ),
        ),
      ),
    );
  }
}

class _OrderCard extends StatelessWidget {
  const _OrderCard({required this.order, required this.onTap});

  final CommerceOrderModel order;
  final VoidCallback onTap;

  Color _statusColor(String status) {
    return switch (status.toLowerCase()) {
      'completed' || 'fulfilled' => const Color(0xFF16A34A),
      'pending_payment' => const Color(0xFFD97706),
      'processing' => const Color(0xFF2563EB),
      'cancelled' => const Color(0xFFDC2626),
      _ => const Color(0xFF64748B),
    };
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final statusColor = _statusColor(order.status);
    final customerName = order.customer?.name ?? 'عميل غير مسجل';

    return Material(
      color: HasimTokens.cardBg(context),
      borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
        child: Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
            border: Border.all(
              color: HasimTokens.lineColor(context),
              width: 0.8,
            ),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 8,
                      vertical: 3,
                    ),
                    decoration: BoxDecoration(
                      color: HasimTokens.mintFill(context),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Text(
                      '#${order.orderNumber}',
                      style: const TextStyle(
                        fontFamily: 'monospace',
                        fontWeight: FontWeight.w700,
                        fontSize: 13,
                        color: HasimTokens.chatGreen,
                      ),
                    ),
                  ),
                  const Spacer(),
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 8,
                      vertical: 3,
                    ),
                    decoration: BoxDecoration(
                      color: statusColor.withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Text(
                      order.statusLabel,
                      style: TextStyle(
                        color: statusColor,
                        fontWeight: FontWeight.w700,
                        fontSize: 12,
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Icon(
                    Icons.person_outline,
                    size: 16,
                    color: HasimTokens.mutedColor(context),
                  ),
                  const SizedBox(width: 6),
                  Text(
                    customerName,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Icon(
                    Icons.shopping_bag_outlined,
                    size: 16,
                    color: HasimTokens.mutedColor(context),
                  ),
                  const SizedBox(width: 6),
                  Text(
                    '${order.items.length} منتجات',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: HasimTokens.mutedColor(context),
                    ),
                  ),
                  const Spacer(),
                  Text(
                    '${order.totalAmount ?? 0} ${order.currency}',
                    style: theme.textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: HasimTokens.inkColor(context),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
