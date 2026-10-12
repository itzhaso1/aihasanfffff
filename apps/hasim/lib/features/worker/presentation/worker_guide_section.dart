import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/worker/data/worker_guide.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';
import 'package:hasim/features/worker/presentation/worker_account_widgets.dart';

class WorkerGuideSection extends StatelessWidget {
  const WorkerGuideSection({super.key, required this.engagement});

  final WorkerEngagement engagement;

  @override
  Widget build(BuildContext context) {
    final guide = engagement.guide;

    return Directionality(
      textDirection: TextDirection.rtl,
      child: WorkerSectionCard(
        key: const Key('worker-guide-section'),
        title: 'دليل العمل',
        child: guide == null || !guide.hasContent
            ? const _WorkerGuideEmptyState()
            : Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _GuideTextSection(
                    key: const Key('worker-guide-company-overview'),
                    title: 'نبذة عن الشركة',
                    text: guide.companyOverview,
                  ),
                  _GuideTextSection(
                    key: const Key('worker-guide-products-context'),
                    title: 'ماذا نبيع؟',
                    text: guide.productsContext,
                  ),
                  _GuideRoleSection(engagement: engagement),
                  _GuideListSection(
                    key: const Key('worker-guide-responsibilities'),
                    title: 'المهام والمسؤوليات',
                    items: guide.responsibilities,
                  ),
                  _GuideListSection(
                    key: const Key('worker-guide-workflow-steps'),
                    title: 'طريقة العمل',
                    items: guide.workflowSteps,
                    numbered: true,
                  ),
                  _GuideListSection(
                    key: const Key('worker-guide-important-rules'),
                    title: 'قواعد وتعليمات مهمة',
                    items: guide.importantRules,
                  ),
                  _GuideListSection(
                    key: const Key('worker-guide-customer-guidance'),
                    title: 'التعامل مع العملاء',
                    items: guide.customerGuidance,
                  ),
                  _GuideListSection(
                    key: const Key('worker-guide-escalation-rules'),
                    title: 'متى أتواصل مع المسؤول؟',
                    items: guide.escalationRules,
                  ),
                  _GuideTextSection(
                    key: const Key('worker-guide-schedule'),
                    title: 'أوقات ونظام العمل',
                    text: guide.workScheduleNotes,
                  ),
                  _GuideTextSection(
                    key: const Key('worker-guide-important-notes'),
                    title: 'ملاحظات مهمة',
                    text: guide.importantNotes,
                  ),
                  _GuideFaqSection(items: guide.faqItems),
                ],
              ),
      ),
    );
  }
}

class _WorkerGuideEmptyState extends StatelessWidget {
  const _WorkerGuideEmptyState();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 16),
      child: Column(
        children: [
          Icon(
            Icons.menu_book_outlined,
            size: 32,
            color: HasimTokens.mutedColor(context),
          ),
          const SizedBox(height: 8),
          const Text(
            'لا يوجد دليل عمل مضاف لهذا التعاقد حاليًا',
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }
}

class _GuideRoleSection extends StatelessWidget {
  const _GuideRoleSection({required this.engagement});

  final WorkerEngagement engagement;

  @override
  Widget build(BuildContext context) {
    final title = engagement.jobTitle?.trim();
    final description = engagement.jobDescription?.trim();
    if ((title == null || title.isEmpty) &&
        (description == null || description.isEmpty)) {
      return const SizedBox.shrink();
    }

    return _GuideSection(
      key: const Key('worker-guide-role'),
      title: 'دوري في هذا العمل',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (title != null && title.isNotEmpty)
            Text(
              title,
              style: Theme.of(
                context,
              ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800),
            ),
          if (description != null && description.isNotEmpty) ...[
            if (title != null && title.isNotEmpty) const SizedBox(height: 6),
            Text(description, style: const TextStyle(height: 1.55)),
          ],
        ],
      ),
    );
  }
}

class _GuideTextSection extends StatelessWidget {
  const _GuideTextSection({super.key, required this.title, required this.text});

  final String title;
  final String? text;

  @override
  Widget build(BuildContext context) {
    final value = text?.trim();
    if (value == null || value.isEmpty) {
      return const SizedBox.shrink();
    }

    return _GuideSection(
      title: title,
      child: Text(value, style: const TextStyle(height: 1.55)),
    );
  }
}

class _GuideListSection extends StatelessWidget {
  const _GuideListSection({
    super.key,
    required this.title,
    required this.items,
    this.numbered = false,
  });

  final String title;
  final List<String> items;
  final bool numbered;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) {
      return const SizedBox.shrink();
    }

    return _GuideSection(
      title: title,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var index = 0; index < items.length; index++)
            Padding(
              padding: EdgeInsets.only(top: index == 0 ? 0 : 8),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(
                    width: numbered ? 28 : 22,
                    child: Text(
                      numbered ? '${index + 1}.' : '•',
                      style: TextStyle(
                        color: HasimTokens.primary,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                  Expanded(
                    child: Text(
                      items[index],
                      style: const TextStyle(height: 1.5),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _GuideFaqSection extends StatelessWidget {
  const _GuideFaqSection({required this.items});

  final List<WorkerGuideFaq> items;

  @override
  Widget build(BuildContext context) {
    final visibleItems = items
        .where(
          (item) =>
              item.question.trim().isNotEmpty || item.answer.trim().isNotEmpty,
        )
        .toList();
    if (visibleItems.isEmpty) {
      return const SizedBox.shrink();
    }

    return _GuideSection(
      key: const Key('worker-guide-faq'),
      title: 'أسئلة شائعة / معلومات سريعة',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var index = 0; index < visibleItems.length; index++) ...[
            if (index > 0) const SizedBox(height: 12),
            if (visibleItems[index].question.trim().isNotEmpty)
              Text(
                visibleItems[index].question,
                style: Theme.of(
                  context,
                ).textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w800),
              ),
            if (visibleItems[index].answer.trim().isNotEmpty) ...[
              if (visibleItems[index].question.trim().isNotEmpty)
                const SizedBox(height: 4),
              Text(
                visibleItems[index].answer,
                style: TextStyle(
                  color: HasimTokens.mutedColor(context),
                  height: 1.5,
                ),
              ),
            ],
          ],
        ],
      ),
    );
  }
}

class _GuideSection extends StatelessWidget {
  const _GuideSection({super.key, required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: HasimTokens.pageBg(context),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: HasimTokens.lineColor(context)),
        ),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                title,
                style: Theme.of(
                  context,
                ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 8),
              child,
            ],
          ),
        ),
      ),
    );
  }
}
