import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';
import 'package:hasim/features/worker/presentation/worker_earnings_section.dart';
import 'package:hasim/features/worker/presentation/worker_account_widgets.dart';
import 'package:hasim/features/worker/presentation/worker_capability_presentation.dart';
import 'package:hasim/features/worker/presentation/worker_disputes_screen.dart';
import 'package:hasim/features/worker/presentation/worker_guide_section.dart';
import 'package:hasim/features/worker/presentation/worker_payouts_screen.dart';
import 'package:hasim/features/worker/presentation/worker_products_section.dart';
import 'package:hasim/features/worker/presentation/worker_scaffold.dart';
import 'package:hasim/features/worker/presentation/worker_work_session_section.dart';
import 'package:hasim/features/worker/providers/worker_engagement_controller.dart';
import 'package:hasim/router/worker_nav.dart';

class WorkerEngagementDetailScreen extends ConsumerWidget {
  const WorkerEngagementDetailScreen({
    super.key,
    required this.engagementId,
    this.embedded = false,
  });

  final int? engagementId;
  final bool embedded;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(workerEngagementDetailProvider(engagementId));
    final engagement = state.engagement;
    final offer = engagement?.isOffer ?? false;

    final body = SafeArea(
      child: state.loading && engagement == null
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
              children: [
                HasimPageHeader(
                  title: offer ? 'تفاصيل العرض' : 'تفاصيل الارتباط',
                  onBack: () => navigateWorkerBack(
                    context,
                    offer ? workerOffersLocation : workerEngagementsLocation,
                  ),
                ),
                const SizedBox(height: 16),
                if (state.error != null && engagement == null)
                  EmptyState(
                    title: state.error!,
                    actionLabel: 'إعادة المحاولة',
                    onAction: () => ref
                        .read(
                          workerEngagementDetailProvider(engagementId).notifier,
                        )
                        .load(),
                  )
                else if (engagement != null)
                  _DetailBody(
                    engagement: engagement,
                    busy: state.busy,
                    actionError: state.actionError,
                  ),
              ],
            ),
    );

    if (embedded) {
      return body;
    }

    return WorkerScaffold(
      selectedIndex: offer ? WorkerNav.offersIndex : WorkerNav.engagementsIndex,
      showBottomNavigation: false,
      body: body,
    );
  }
}

class _DetailBody extends ConsumerWidget {
  const _DetailBody({
    required this.engagement,
    required this.busy,
    required this.actionError,
  });

  final WorkerEngagement engagement;
  final bool busy;
  final String? actionError;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _WorkerSummaryCard(engagement: engagement),
        const SizedBox(height: 12),
        WorkerGuideSection(engagement: engagement),
        if (engagement.canViewAssignedProducts) ...[
          const SizedBox(height: 12),
          WorkerProductsSection(engagementId: engagement.id),
        ],
        const SizedBox(height: 12),
        _WorkerCapabilitiesSection(capabilities: engagement.capabilities),
        if (!engagement.isOffer) ...[
          const SizedBox(height: 12),
          WorkerWorkSessionSection(engagement: engagement),
          const SizedBox(height: 12),
          WorkerEarningsSection(engagement: engagement),
          const SizedBox(height: 12),
          WorkerPayoutsSection(engagementId: engagement.id),
          const SizedBox(height: 12),
          WorkerEngagementDisputesSection(engagement: engagement),
        ],
        if (actionError != null)
          WorkerInlineMessage(
            actionError!,
            key: const Key('worker-offer-action-error'),
            error: true,
          ),
        if (engagement.isOffer) ...[
          const SizedBox(height: 16),
          FilledButton(
            key: const Key('worker-offer-accept'),
            onPressed: busy
                ? null
                : () async {
                    final ok = await ref
                        .read(
                          workerEngagementDetailProvider(
                            engagement.id,
                          ).notifier,
                        )
                        .accept();
                    if (!context.mounted || !ok) return;
                    showWorkerSnack(context, 'تم قبول العرض');
                  },
            child: busy
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('قبول'),
          ),
          const SizedBox(height: 8),
          OutlinedButton(
            key: const Key('worker-offer-reject'),
            onPressed: busy ? null : () => _reject(context, ref),
            child: const Text('رفض'),
          ),
        ],
      ],
    );
  }

  Future<void> _reject(BuildContext context, WidgetRef ref) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('رفض العرض'),
        content: const Text('رفض هذا العرض؟'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('إلغاء'),
          ),
          TextButton(
            key: const Key('worker-offer-reject-confirm'),
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('رفض'),
          ),
        ],
      ),
    );
    if (confirmed != true || !context.mounted) return;
    final ok = await ref
        .read(workerEngagementDetailProvider(engagement.id).notifier)
        .reject();
    if (!context.mounted || !ok) return;
    showWorkerSnack(context, 'تم رفض العرض');
  }
}

class _WorkerSummaryCard extends StatelessWidget {
  const _WorkerSummaryCard({required this.engagement});

  final WorkerEngagement engagement;

  @override
  Widget build(BuildContext context) {
    final ink = HasimTokens.isDark(context)
        ? Colors.white
        : const Color(0xFF101418);
    final workspaceName = engagement.workspaceName?.trim() ?? '';
    final description = engagement.jobDescription?.trim() ?? '';

    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: HasimTokens.lineColor(context)),
        boxShadow: HasimTokens.isDark(context)
            ? const []
            : const [
                BoxShadow(
                  color: Color(0x120B8F82),
                  blurRadius: 22,
                  offset: Offset(0, 8),
                ),
              ],
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Directionality(
              textDirection: TextDirection.ltr,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Directionality(
                      textDirection: TextDirection.rtl,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'تفاصيل العمل',
                            style: Theme.of(context).textTheme.titleLarge
                                ?.copyWith(
                                  color: ink,
                                  fontWeight: FontWeight.w800,
                                ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            engagement.headline,
                            style: Theme.of(context).textTheme.headlineSmall
                                ?.copyWith(
                                  color: ink,
                                  fontWeight: FontWeight.w800,
                                  height: 1.25,
                                ),
                          ),
                          if (workspaceName.isNotEmpty) ...[
                            const SizedBox(height: 4),
                            Text(
                              workspaceName,
                              style: Theme.of(context).textTheme.bodyLarge
                                  ?.copyWith(
                                    color: ink.withValues(alpha: 0.68),
                                    fontWeight: FontWeight.w600,
                                  ),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      color: HasimTokens.mintFill(context),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Icon(
                      Icons.business_center_rounded,
                      color: HasimTokens.primary,
                      size: 24,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 6),
            DecoratedBox(
              decoration: BoxDecoration(
                color: HasimTokens.mintFill(context),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 5,
                ),
                child: Text(
                  'الحالة: ${engagement.statusLabel}',
                  style: TextStyle(
                    color: ink,
                    fontWeight: FontWeight.w800,
                    fontSize: 13,
                  ),
                ),
              ),
            ),
            if (description.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(
                description,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: ink.withValues(alpha: 0.78),
                  height: 1.3,
                  fontWeight: FontWeight.w500,
                ),
              ),
            ],
            if (engagement.startsAt != null || engagement.endsAt != null) ...[
              const SizedBox(height: 8),
              Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (engagement.startsAt != null)
                    _WorkerOfferDetailDate(
                      icon: Icons.calendar_today_rounded,
                      label: 'تاريخ البداية',
                      value: _formatOfferDetailDate(engagement.startsAt!),
                    ),
                  if (engagement.startsAt != null && engagement.endsAt != null)
                    const SizedBox(height: 4),
                  if (engagement.endsAt != null)
                    _WorkerOfferDetailDate(
                      icon: Icons.event_rounded,
                      label: 'تاريخ النهاية',
                      value: _formatOfferDetailDate(engagement.endsAt!),
                    ),
                ],
              ),
            ],
            if (engagement.contractDurationLabel != null ||
                engagement.contractPriceLabel != null) ...[
              const SizedBox(height: 6),
              DecoratedBox(
                decoration: BoxDecoration(
                  color: HasimTokens.isDark(context)
                      ? const Color(0xFF191919)
                      : const Color(0xFFF6FAFA),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: HasimTokens.lineColor(context)),
                ),
                child: Padding(
                  padding: const EdgeInsets.all(6),
                  child: Wrap(
                    spacing: 20,
                    runSpacing: 12,
                    children: [
                      if (engagement.contractDurationLabel != null)
                        _WorkerOfferDetailStat(
                          label: 'مدة التعاقد',
                          value: engagement.contractDurationLabel!,
                          valueKey: const Key('worker-offer-contract-duration'),
                        ),
                      if (engagement.contractPriceLabel != null)
                        _WorkerOfferDetailStat(
                          label: 'سعر التعاقد',
                          value: engagement.contractPriceLabel!,
                          valueKey: const Key('worker-offer-contract-price'),
                        ),
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

class _WorkerOfferDetailDate extends StatelessWidget {
  const _WorkerOfferDetailDate({
    required this.icon,
    required this.label,
    required this.value,
  });

  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final ink = HasimTokens.isDark(context)
        ? Colors.white
        : const Color(0xFF101418);

    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.isDark(context)
            ? const Color(0xFF191919)
            : const Color(0xFFF4F9FB),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        child: Directionality(
          textDirection: TextDirection.ltr,
          child: Row(
            children: [
              Expanded(
                child: Directionality(
                  textDirection: TextDirection.rtl,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        label,
                        textAlign: TextAlign.right,
                        style: TextStyle(
                          color: ink.withValues(alpha: 0.58),
                          fontSize: 10,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        value,
                        textAlign: TextAlign.right,
                        style: TextStyle(
                          color: ink,
                          fontSize: 15,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Container(
                width: 30,
                height: 30,
                decoration: BoxDecoration(
                  color: HasimTokens.mintFill(context),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Icon(
                  icon,
                  size: 16,
                  color: HasimTokens.mutedColor(context),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _WorkerOfferDetailStat extends StatelessWidget {
  const _WorkerOfferDetailStat({
    required this.label,
    required this.value,
    required this.valueKey,
  });

  final String label;
  final String value;
  final Key valueKey;

  @override
  Widget build(BuildContext context) {
    final ink = HasimTokens.isDark(context)
        ? Colors.white
        : const Color(0xFF101418);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '$label: $value',
          key: valueKey,
          style: TextStyle(
            color: ink,
            fontSize: 15,
            fontWeight: FontWeight.w800,
          ),
        ),
      ],
    );
  }
}

String _formatOfferDetailDate(String value) {
  final raw = value.trim();
  final parsed = DateTime.tryParse(raw);
  if (parsed == null) return raw;

  final date = raw.length == 10 ? parsed : parsed.toLocal();
  final day = date.day.toString().padLeft(2, '0');
  final month = date.month.toString().padLeft(2, '0');
  return '$day-$month-${date.year}';
}

class _WorkerCapabilitiesSection extends StatelessWidget {
  const _WorkerCapabilitiesSection({required this.capabilities});

  final List<WorkerCapability> capabilities;

  @override
  Widget build(BuildContext context) {
    final grouped = <String, List<WorkerCapabilityPresentation>>{};
    for (final capability in capabilities) {
      final presentation = WorkerCapabilityPresentation.fromCapability(
        capability,
      );
      grouped.putIfAbsent(presentation.category, () => []).add(presentation);
    }

    return WorkerSectionCard(
      key: const Key('worker-capabilities-section'),
      title: 'الصلاحيات والأدوات المتاحة لي',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('الصلاحيات الممنوحة'),
          const SizedBox(height: 4),
          Text(
            'الأدوات والقدرات المتاحة لك ضمن هذا الارتباط.',
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
              color: HasimTokens.mutedColor(context),
              height: 1.45,
            ),
          ),
          const SizedBox(height: 14),
          if (grouped.isEmpty)
            const _WorkerCapabilitiesEmptyState()
          else
            for (final entry in grouped.entries) ...[
              _WorkerCapabilityGroup(
                category: entry.key,
                capabilities: entry.value,
              ),
              if (entry.key != grouped.keys.last) const SizedBox(height: 10),
            ],
        ],
      ),
    );
  }
}

class _WorkerCapabilityGroup extends StatelessWidget {
  const _WorkerCapabilityGroup({
    required this.category,
    required this.capabilities,
  });

  final String category;
  final List<WorkerCapabilityPresentation> capabilities;

  @override
  Widget build(BuildContext context) {
    final color = capabilities.first.categoryColor;
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.pageBg(context),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                DecoratedBox(
                  decoration: BoxDecoration(
                    color: color.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(11),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(8),
                    child: Icon(
                      _categoryIcon(category),
                      size: 20,
                      color: color,
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    category,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
                Text(
                  '${capabilities.length}',
                  style: Theme.of(context).textTheme.labelMedium?.copyWith(
                    color: HasimTokens.mutedColor(context),
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            for (var index = 0; index < capabilities.length; index++) ...[
              _WorkerCapabilityTile(capability: capabilities[index]),
              if (index < capabilities.length - 1) const SizedBox(height: 8),
            ],
          ],
        ),
      ),
    );
  }

  IconData _categoryIcon(String value) {
    return switch (value) {
      'المحادثات' => Icons.forum_outlined,
      'العملاء' => Icons.groups_outlined,
      'المنتجات' => Icons.inventory_2_outlined,
      'الطلبات' => Icons.shopping_bag_outlined,
      'الدفع' => Icons.payments_outlined,
      'التقارير' => Icons.bar_chart_rounded,
      'البريد' => Icons.mail_outline_rounded,
      'التحديثات' => Icons.auto_stories_outlined,
      _ => Icons.extension_outlined,
    };
  }
}

class _WorkerCapabilityTile extends StatelessWidget {
  const _WorkerCapabilityTile({required this.capability});

  final WorkerCapabilityPresentation capability;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      key: Key('worker-capability-${capability.key}'),
      decoration: BoxDecoration(
        color: capability.categoryColor.withValues(alpha: 0.07),
        borderRadius: BorderRadius.circular(13),
        border: Border.all(
          color: capability.categoryColor.withValues(alpha: 0.14),
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(capability.icon, size: 21, color: capability.categoryColor),
            const SizedBox(width: 9),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    capability.title,
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                      height: 1.3,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    capability.description,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: HasimTokens.mutedColor(context),
                      height: 1.35,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _WorkerCapabilitiesEmptyState extends StatelessWidget {
  const _WorkerCapabilitiesEmptyState();

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.pageBg(context),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            Icon(
              Icons.lock_outline_rounded,
              size: 28,
              color: HasimTokens.mutedColor(context),
            ),
            const SizedBox(height: 8),
            const Text(
              'لا توجد صلاحيات ممنوحة حاليًا',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 4),
            Text(
              'لم يتم منح أدوات تشغيلية لهذا الارتباط بعد.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: HasimTokens.mutedColor(context),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
