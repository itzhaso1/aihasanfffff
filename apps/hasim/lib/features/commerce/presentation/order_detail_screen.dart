import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/commerce/data/commerce_order_models.dart';

class OrderDetailScreen extends ConsumerStatefulWidget {
  const OrderDetailScreen({
    super.key,
    required this.orderId,
    this.initialOrder,
  });

  final int orderId;
  final CommerceOrderModel? initialOrder;

  @override
  ConsumerState<OrderDetailScreen> createState() => _OrderDetailScreenState();
}

class _OrderDetailScreenState extends ConsumerState<OrderDetailScreen> {
  CommerceOrderModel? _order;
  bool _loading = true;
  String? _error;
  bool _cancelling = false;

  @override
  void initState() {
    super.initState();
    _order = widget.initialOrder;
    _loading = _order == null;
    _load();
  }

  Future<void> _load() async {
    if (_order == null) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }

    try {
      final repo = ref.read(commerceOrderRepositoryProvider);
      final order = await repo.show(widget.orderId);
      if (!mounted) return;
      setState(() {
        _order = order;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      if (_order != null) {
        setState(() => _loading = false);
        return;
      }
      setState(() {
        _error = 'تعذر تحميل تفاصيل الطلب.';
        _loading = false;
      });
    }
  }

  Future<void> _cancelOrder() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('إلغاء الطلب'),
        content: const Text(
          'هل أنت متأكد من رغبتك في إلغاء هذا الطلب؟ لا يمكن التراجع عن هذه الخطوة.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('تراجع'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('نعم، إلغاء الطلب'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    setState(() => _cancelling = true);
    try {
      final repo = ref.read(commerceOrderRepositoryProvider);
      final cancelled = await repo.cancel(widget.orderId);
      if (!mounted) return;
      setState(() {
        _order = cancelled;
        _cancelling = false;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('تم إلغاء الطلب بنجاح.'),
          backgroundColor: HasimTokens.chatGreen,
        ),
      );
    } catch (e) {
      if (!mounted) return;
      setState(() => _cancelling = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(e.toString().replaceAll('Exception: ', '')),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

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
    final order = _order;

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: Text(
          order != null ? 'طلب #${order.orderNumber}' : 'تفاصيل الطلب',
          style: const TextStyle(fontWeight: FontWeight.bold),
        ),
        backgroundColor: HasimTokens.cardBg(context),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
      ),
      body: _buildContent(theme, order),
    );
  }

  Widget _buildContent(ThemeData theme, CommerceOrderModel? order) {
    if (_loading && order == null) {
      return const SkeletonList();
    }

    if (_error != null && order == null) {
      return EmptyState(
        title: _error!,
        actionLabel: 'إعادة المحاولة',
        onAction: _load,
      );
    }

    if (order == null) {
      return const EmptyState(title: 'الطلب غير موجود.');
    }

    final statusColor = _statusColor(order.status);
    final canCancel =
        order.status.toLowerCase() != 'cancelled' &&
        order.status.toLowerCase() != 'completed';

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Status & Header Card
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: HasimTokens.cardBg(context),
              borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
              border: Border.all(
                color: HasimTokens.lineColor(context),
                width: 0.8,
              ),
            ),
            child: Column(
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      'رقم الطلب: #${order.orderNumber}',
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 4,
                      ),
                      decoration: BoxDecoration(
                        color: statusColor.withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(
                        order.statusLabel,
                        style: TextStyle(
                          color: statusColor,
                          fontWeight: FontWeight.w700,
                          fontSize: 13,
                        ),
                      ),
                    ),
                  ],
                ),
                if (order.placedAt != null) ...[
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Icon(
                        Icons.calendar_today_outlined,
                        size: 14,
                        color: HasimTokens.mutedColor(context),
                      ),
                      const SizedBox(width: 6),
                      Text(
                        'تاريخ الطلب: ${order.placedAt}',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: HasimTokens.mutedColor(context),
                        ),
                      ),
                    ],
                  ),
                ],
                if (order.channel != null) ...[
                  const SizedBox(height: 4),
                  Row(
                    children: [
                      Icon(
                        Icons.hub_outlined,
                        size: 14,
                        color: HasimTokens.mutedColor(context),
                      ),
                      const SizedBox(width: 6),
                      Text(
                        'القناة: ${order.channel}',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: HasimTokens.mutedColor(context),
                        ),
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: 16),

          // Customer Card
          if (order.customer != null) ...[
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: HasimTokens.cardBg(context),
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
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        'بيانات العميل',
                        style: theme.textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      TextButton.icon(
                        icon: const Icon(Icons.person_outline, size: 16),
                        label: const Text('الملف'),
                        onPressed: () {
                          context.push(
                            '/customers/${order.customer!.id}',
                            extra: order.customer,
                          );
                        },
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(
                    order.customer!.name,
                    style: theme.textTheme.bodyLarge?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  if (order.customer!.phone != null) ...[
                    const SizedBox(height: 4),
                    Text(
                      order.customer!.phone!,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        color: HasimTokens.mutedColor(context),
                      ),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: 16),
          ],

          // Order Items
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: HasimTokens.cardBg(context),
              borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
              border: Border.all(
                color: HasimTokens.lineColor(context),
                width: 0.8,
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'المنتجات (${order.items.length})',
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 12),
                if (order.items.isEmpty)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: Text(
                      'لا توجد عناصر مسجلة في هذا الطلب.',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: HasimTokens.mutedColor(context),
                      ),
                    ),
                  )
                else
                  for (final item in order.items) ...[
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  item.name,
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                if (item.variantName != null &&
                                    item.variantName!.isNotEmpty)
                                  Text(
                                    item.variantName!,
                                    style: TextStyle(
                                      fontSize: 12,
                                      color: HasimTokens.mutedColor(context),
                                    ),
                                  ),
                                const SizedBox(height: 2),
                                Text(
                                  'الكمية: ${item.quantity} × ${item.unitPrice ?? 0} ${item.currency}',
                                  style: TextStyle(
                                    fontSize: 12,
                                    color: HasimTokens.mutedColor(context),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          Text(
                            '${item.totalAmount ?? 0} ${item.currency}',
                            style: const TextStyle(fontWeight: FontWeight.w700),
                          ),
                        ],
                      ),
                    ),
                    Divider(color: HasimTokens.lineColor(context), height: 1),
                  ],
              ],
            ),
          ),
          const SizedBox(height: 16),

          // Financial Summary
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: HasimTokens.cardBg(context),
              borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
              border: Border.all(
                color: HasimTokens.lineColor(context),
                width: 0.8,
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'ملخص الحساب',
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 12),
                _summaryRow(
                  'المجموع الفرعي',
                  '${order.subtotal ?? 0} ${order.currency}',
                ),
                if (order.discountAmount != null && order.discountAmount != '0')
                  _summaryRow(
                    'الخصم',
                    '- ${order.discountAmount} ${order.currency}',
                    isDiscount: true,
                  ),
                if (order.taxAmount != null && order.taxAmount != '0')
                  _summaryRow(
                    'ضريبة القيمة المضافة',
                    '${order.taxAmount} ${order.currency}',
                  ),
                if (order.shippingAmount != null && order.shippingAmount != '0')
                  _summaryRow(
                    'الشحن والتوصيل',
                    '${order.shippingAmount} ${order.currency}',
                  ),
                Divider(color: HasimTokens.lineColor(context), height: 20),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      'الإجمالي النهائي',
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    Text(
                      '${order.totalAmount ?? 0} ${order.currency}',
                      style: theme.textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.w900,
                        color: HasimTokens.chatGreen,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),

          // Actions
          if (canCancel)
            OutlinedButton.icon(
              onPressed: _cancelling ? null : _cancelOrder,
              icon: _cancelling
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.cancel_outlined, color: Colors.red),
              label: const Text(
                'إلغاء الطلب',
                style: TextStyle(
                  color: Colors.red,
                  fontWeight: FontWeight.bold,
                ),
              ),
              style: OutlinedButton.styleFrom(
                side: const BorderSide(color: Colors.red),
                padding: const EdgeInsets.symmetric(vertical: 12),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _summaryRow(String label, String value, {bool isDiscount = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            label,
            style: TextStyle(
              fontSize: 13,
              color: HasimTokens.mutedColor(context),
            ),
          ),
          Text(
            value,
            style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: isDiscount ? Colors.red : HasimTokens.inkColor(context),
            ),
          ),
        ],
      ),
    );
  }
}
