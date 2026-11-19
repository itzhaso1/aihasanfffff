import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/analytics/providers/analytics_controller.dart';

class AnalyticsScreen extends ConsumerWidget {
  const AnalyticsScreen({super.key});

  String _rangeLabel(String range) {
    return switch (range) {
      'today' => 'اليوم',
      'week' => 'هذا الأسبوع',
      'month' => 'هذا الشهر',
      'year' => 'هذه السنة',
      _ => range,
    };
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(analyticsControllerProvider);
    final data = state.analytics;

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: const Text('التحليلات والتقارير', style: TextStyle(fontWeight: FontWeight.bold)),
        backgroundColor: HasimTokens.cardBg(context),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.read(analyticsControllerProvider.notifier).load(state.range),
        color: HasimTokens.chatGreen,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            // Range selector
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: ['today', 'week', 'month', 'year'].map((r) {
                  final selected = state.range == r;
                  return Padding(
                    padding: const EdgeInsets.only(left: 8),
                    child: ChoiceChip(
                      label: Text(_rangeLabel(r)),
                      selected: selected,
                      selectedColor: HasimTokens.primary.withValues(alpha: 0.15),
                      labelStyle: TextStyle(
                        color: selected ? HasimTokens.primary : Colors.black87,
                        fontWeight: selected ? FontWeight.bold : FontWeight.normal,
                      ),
                      onSelected: (_) =>
                          ref.read(analyticsControllerProvider.notifier).load(r),
                    ),
                  );
                }).toList(),
              ),
            ),
            const SizedBox(height: 16),

            if (state.loading && data == null)
              const SkeletonList()
            else if (state.error != null && data == null)
              Center(
                child: Column(
                  children: [
                    Text(state.error!),
                    const SizedBox(height: 8),
                    FilledButton(
                      onPressed: () => ref
                          .read(analyticsControllerProvider.notifier)
                          .load(state.range),
                      child: const Text('إعادة المحاولة'),
                    ),
                  ],
                ),
              )
            else if (data != null) ...[
              // KPI Cards Grid
              LayoutBuilder(
                builder: (context, constraints) {
                  final isWide = constraints.maxWidth > 550;
                  return GridView.count(
                    crossAxisCount: isWide ? 4 : 2,
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    crossAxisSpacing: 10,
                    mainAxisSpacing: 10,
                    childAspectRatio: isWide ? 1.4 : 1.3,
                    children: [
                      _KpiCard(
                        title: 'إجمالي المبيعات',
                        value: '${data.revenue.toStringAsFixed(2)} ر.س',
                        icon: Icons.payments_outlined,
                        color: Colors.green,
                      ),
                      _KpiCard(
                        title: 'عدد الطلبات',
                        value: '${data.ordersCount}',
                        icon: Icons.shopping_bag_outlined,
                        color: Colors.blue,
                      ),
                      _KpiCard(
                        title: 'معدل التحويل',
                        value: '${data.conversionRate}%',
                        icon: Icons.trending_up,
                        color: Colors.purple,
                      ),
                      _KpiCard(
                        title: 'عملاء جدد',
                        value: '${data.customersNew}',
                        icon: Icons.person_add_outlined,
                        color: Colors.teal,
                      ),
                      _KpiCard(
                        title: 'المحادثات الإجمالية',
                        value: '${data.conversationsCount}',
                        icon: Icons.chat_bubble_outline,
                        color: Colors.indigo,
                      ),
                      _KpiCard(
                        title: 'ردود الذكاء الاصطناعي',
                        value: '${data.aiResponsesCount}',
                        icon: Icons.smart_toy_outlined,
                        color: Colors.amber.shade800,
                      ),
                    ],
                  );
                },
              ),
              const SizedBox(height: 24),

              // Top Products
              Card(
                elevation: 0,
                color: HasimTokens.cardBg(context),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(16),
                  side: BorderSide(color: HasimTokens.lineColor(context)),
                ),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Row(
                        children: [
                          Icon(Icons.leaderboard_outlined, color: HasimTokens.primary),
                          SizedBox(width: 8),
                          Text(
                            'المنتجات الأكثر مبيعاً وطلباً',
                            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                          ),
                        ],
                      ),
                      const SizedBox(height: 12),
                      if (data.topProducts.isEmpty)
                        const Padding(
                          padding: EdgeInsets.symmetric(vertical: 16),
                          child: Center(
                            child: Text(
                              'لا توجد مبيعات مسجلة في هذا النطاق الزمني.',
                              style: TextStyle(color: Colors.grey),
                            ),
                          ),
                        )
                      else
                        ListView.separated(
                          shrinkWrap: true,
                          physics: const NeverScrollableScrollPhysics(),
                          itemCount: data.topProducts.length,
                          separatorBuilder: (_, _) => const Divider(),
                          itemBuilder: (context, idx) {
                            final prod = data.topProducts[idx];
                            return ListTile(
                              contentPadding: EdgeInsets.zero,
                              leading: CircleAvatar(
                                backgroundColor: HasimTokens.mintSoft,
                                child: Text('${idx + 1}',
                                    style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      color: HasimTokens.primary,
                                    )),
                              ),
                              title: Text(prod.name, style: const TextStyle(fontWeight: FontWeight.bold)),
                              subtitle: Text('الكمية المباعة: ${prod.quantity.toInt()}'),
                              trailing: Text(
                                '${prod.revenue.toStringAsFixed(2)} ر.س',
                                style: const TextStyle(
                                  fontWeight: FontWeight.bold,
                                  color: Colors.green,
                                ),
                              ),
                            );
                          },
                        ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),

              // Timeline summary card
              if (data.timeline.isNotEmpty)
                Card(
                  elevation: 0,
                  color: HasimTokens.cardBg(context),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(16),
                    side: BorderSide(color: HasimTokens.lineColor(context)),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Row(
                          children: [
                            Icon(Icons.timeline, color: HasimTokens.primary),
                            SizedBox(width: 8),
                            Text(
                              'السجل الزمني للأداء',
                              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                            ),
                          ],
                        ),
                        const SizedBox(height: 12),
                        ...data.timeline.map((entry) => Padding(
                          padding: const EdgeInsets.symmetric(vertical: 4),
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(entry.label, style: const TextStyle(color: Colors.grey)),
                              Text(
                                '${entry.orders} طلبات • ${entry.revenue.toStringAsFixed(2)} ر.س',
                                style: const TextStyle(fontWeight: FontWeight.w600),
                              ),
                            ],
                          ),
                        )),
                      ],
                    ),
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}

class _KpiCard extends StatelessWidget {
  const _KpiCard({
    required this.title,
    required this.value,
    required this.icon,
    required this.color,
  });

  final String title;
  final String value;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      color: HasimTokens.cardBg(context),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: BorderSide(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Text(
                    title,
                    style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Icon(icon, size: 20, color: color),
              ],
            ),
            Text(
              value,
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ),
      ),
    );
  }
}
