import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_buttons.dart';
import 'package:hasim/core/widgets/hasim_card.dart';
import 'package:hasim/core/widgets/hasim_settings_body.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

class PlansScreen extends ConsumerStatefulWidget {
  const PlansScreen({super.key});

  @override
  ConsumerState<PlansScreen> createState() => _PlansScreenState();
}

class _PlansScreenState extends ConsumerState<PlansScreen> {
  PlanSnapshot? _current;
  PlansCatalog? _catalog;
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final repo = ref.read(planRepositoryProvider);
    final cachedCurrent = await repo.cachedCurrent();
    final cachedCatalog = await repo.cachedCatalog();
    if (!mounted) return;
    setState(() {
      _current = cachedCurrent;
      _catalog = cachedCatalog;
      _loading = cachedCurrent == null && cachedCatalog == null;
      _error = null;
    });
    try {
      final current = await ref.read(planRepositoryProvider).current();
      final catalog = await ref.read(planRepositoryProvider).catalog();
      if (!mounted) return;
      setState(() {
        _current = current;
        _catalog = catalog;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = _current == null && _catalog == null ? e.userMessage : null;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = _current == null && _catalog == null
            ? 'تعذر تحميل الباقات.'
            : null;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    ref.listen<String>(authControllerProvider.select((s) => s.isolationKey), (
      previous,
      next,
    ) {
      if (previous != null && previous != next) _load();
    });
    final compact = HasimLayout.isCompact(context);
    final features = _current == null
        ? const <String>[]
        : _chatFeatures(_current!.features);
    final meters = _current?.meters ?? const <String, dynamic>{};

    final content = _loading
        ? const SkeletonList()
        : _error != null
        ? Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(_error!),
                TextButton(
                  onPressed: _load,
                  child: const Text('إعادة المحاولة'),
                ),
              ],
            ),
          )
        : RefreshIndicator(
            onRefresh: _load,
            child: HasimSettingsBody(
              mobileLayout: compact,
              children: [
                HasimCard(
                  borderColor: HasimTokens.primary.withValues(alpha: 0.30),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const HasimSettingsSectionHeader(
                        icon: Icons.workspace_premium_outlined,
                        title: 'الباقة الحالية',
                        subtitle: 'الميزات المتاحة في اشتراك مساحة العمل',
                        trailing: HasimBadge(label: 'نشطة'),
                      ),
                      const SizedBox(height: 14),
                      if (features.isEmpty)
                        Text(
                          'لا توجد ميزات مدرجة لهذه الباقة حالياً.',
                          style: Theme.of(context).textTheme.bodyMedium
                              ?.copyWith(color: HasimTokens.mutedColor(context)),
                        )
                      else
                        Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            for (final feature in features)
                              Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 10,
                                  vertical: 6,
                                ),
                                decoration: BoxDecoration(
                                  color: HasimTokens.mintFill(context),
                                  borderRadius: BorderRadius.circular(10),
                                  border: Border.all(
                                    color: HasimTokens.primary.withValues(
                                      alpha: 0.22,
                                    ),
                                  ),
                                ),
                                child: Text(
                                  feature,
                                  style: Theme.of(context).textTheme.labelMedium
                                      ?.copyWith(
                                        color: HasimTokens.inkColor(context),
                                        fontWeight: FontWeight.w700,
                                      ),
                                ),
                              ),
                          ],
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: HasimTokens.spaceMd),
                HasimCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const HasimSettingsSectionHeader(
                        icon: Icons.speed_outlined,
                        title: 'الاستخدام',
                        subtitle: 'متابعة الاستهلاك مقابل الحدود المسموحة',
                      ),
                      const SizedBox(height: 14),
                      if (meters.isEmpty)
                        Text(
                          'لا توجد عدّادات استخدام.',
                          style: Theme.of(context).textTheme.bodyMedium
                              ?.copyWith(color: HasimTokens.mutedColor(context)),
                        )
                      else
                        for (var i = 0; i < meters.entries.length; i++) ...[
                          if (i > 0) const SizedBox(height: 14),
                          _UsageMeterRow(entry: meters.entries.elementAt(i)),
                        ],
                    ],
                  ),
                ),
                if (_catalog != null && _catalog!.plans.isNotEmpty) ...[
                  const SizedBox(height: HasimTokens.spaceMd),
                  HasimCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        const HasimSettingsSectionHeader(
                          icon: Icons.compare_arrows_rounded,
                          title: 'مقارنة الباقات',
                          subtitle: 'الخطط المتوفرة ومزايا كل باقة',
                        ),
                        const SizedBox(height: 14),
                        for (var i = 0; i < _catalog!.plans.length; i++) ...[
                          if (i > 0) const SizedBox(height: 10),
                          _PlanCatalogCard(
                            plan: _catalog!.plans[i],
                            features: _chatFeatures(_catalog!.plans[i].features),
                          ),
                        ],
                      ],
                    ),
                  ),
                ],
              ],
            ),
          );

    if (compact) {
      return HasimMobileScaffold(title: 'الباقات والاستخدام', body: content);
    }

    return Scaffold(
      backgroundColor: HasimTokens.settingsCanvasBg(context),
      appBar: AppBar(
        title: const Text('الباقات والاستخدام'),
        backgroundColor: HasimTokens.settingsCanvasBg(context),
        surfaceTintColor: Colors.transparent,
      ),
      body: content,
    );
  }

  List<String> _chatFeatures(List<String> features) {
    const businessLegacyKeys = {'finance', 'pos', 'inventory'};

    return features
        .where((feature) => !businessLegacyKeys.contains(feature))
        .toList();
  }
}

class _UsageMeterRow extends StatelessWidget {
  const _UsageMeterRow({required this.entry});

  final MapEntry<String, dynamic> entry;

  @override
  Widget build(BuildContext context) {
    final value = entry.value;
    String subtitle = value.toString();
    double? progress;
    if (value is Map) {
      final used = value['used'] ?? value['current'] ?? value['count'];
      final limit = value['limit'] ?? value['max'];
      subtitle = '${used ?? '—'} / ${limit ?? '∞'}';
      if (used is num && limit is num && limit > 0) {
        progress = (used / limit).clamp(0.0, 1.0).toDouble();
      }
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                entry.key,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: HasimTokens.inkColor(context),
                ),
              ),
            ),
            HasimBadge(label: subtitle),
          ],
        ),
        if (progress != null) ...[
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 7,
              backgroundColor: HasimTokens.lineColor(context),
              valueColor: AlwaysStoppedAnimation<Color>(
                progress >= 0.9 ? HasimTokens.warning : HasimTokens.primary,
              ),
            ),
          ),
        ],
      ],
    );
  }
}

class _PlanCatalogCard extends StatelessWidget {
  const _PlanCatalogCard({required this.plan, required this.features});

  final PlanCatalogItem plan;
  final List<String> features;

  @override
  Widget build(BuildContext context) {
    final priceText = plan.price == null
        ? null
        : '${plan.price} ${plan.currency ?? ''}'.trim();
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: HasimTokens.settingsCanvasBg(context),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  plan.name,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: HasimTokens.inkColor(context),
                  ),
                ),
              ),
              if (priceText != null && priceText.isNotEmpty)
                HasimBadge(label: priceText),
            ],
          ),
          if (plan.description != null && plan.description!.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(
              plan.description!,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: HasimTokens.mutedColor(context),
              ),
            ),
          ],
          if (features.isNotEmpty) ...[
            const SizedBox(height: 10),
            Text(
              features.take(4).join(' · '),
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: HasimTokens.inkColor(context),
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
