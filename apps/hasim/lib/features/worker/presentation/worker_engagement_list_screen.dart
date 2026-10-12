import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';
import 'package:hasim/features/worker/presentation/worker_account_widgets.dart';
import 'package:hasim/features/worker/presentation/worker_capability_presentation.dart';
import 'package:hasim/features/worker/presentation/worker_scaffold.dart';
import 'package:hasim/features/worker/providers/worker_engagement_controller.dart';
import 'package:hasim/router/worker_nav.dart';

class WorkerOffersScreen extends StatelessWidget {
  const WorkerOffersScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  Widget build(BuildContext context) {
    return WorkerEngagementListScreen(offers: true, embedded: embedded);
  }
}

class WorkerEngagementsScreen extends StatelessWidget {
  const WorkerEngagementsScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  Widget build(BuildContext context) {
    return WorkerEngagementListScreen(offers: false, embedded: embedded);
  }
}

class WorkerEngagementListScreen extends ConsumerWidget {
  const WorkerEngagementListScreen({
    super.key,
    required this.offers,
    this.embedded = false,
  });

  final bool offers;
  final bool embedded;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(workerEngagementListProvider);
    final items = [
      for (final item in state.items)
        if (item.isOffer == offers) item,
    ];

    final body = SafeArea(
      child: offers
          ? _WorkerOffersContent(
              state: state,
              items: items,
              onRetry: () => ref
                  .read(workerEngagementListProvider.notifier)
                  .load(force: true),
            )
          : _WorkerBusinessesContent(
              state: state,
              items: items,
              onRetry: () => ref
                  .read(workerEngagementListProvider.notifier)
                  .load(force: true),
            ),
    );

    if (embedded) {
      return body;
    }

    return WorkerScaffold(
      selectedIndex: offers
          ? WorkerNav.offersIndex
          : WorkerNav.engagementsIndex,
      body: body,
    );
  }
}

class _WorkerBusinessesContent extends StatefulWidget {
  const _WorkerBusinessesContent({
    required this.state,
    required this.items,
    required this.onRetry,
  });

  final WorkerEngagementListState state;
  final List<WorkerEngagement> items;
  final VoidCallback onRetry;

  @override
  State<_WorkerBusinessesContent> createState() =>
      _WorkerBusinessesContentState();
}

class _WorkerBusinessesContentState extends State<_WorkerBusinessesContent> {
  var _selectedTab = 0;

  @override
  Widget build(BuildContext context) {
    final loading = widget.state.loading && widget.state.items.isEmpty;
    return DefaultTabController(
      length: 2,
      initialIndex: _selectedTab,
      child: Builder(
        builder: (context) {
          return ListView(
            key: loading ? const Key('worker-engagements-loading') : null,
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
            children: [
              const HasimPageHeader(
                title: 'الأعمال',
                subtitle: 'ارتباطاتك مع الشركات',
              ),
              const SizedBox(height: 8),
              LayoutBuilder(
                builder: (context, constraints) {
                  final tabs = Material(
                    color: Colors.transparent,
                    child: TabBar(
                      onTap: (index) => setState(() => _selectedTab = index),
                      labelColor: HasimTokens.accentDark,
                      unselectedLabelColor: HasimTokens.mutedColor(context),
                      indicatorColor: HasimTokens.primary,
                      indicatorSize: TabBarIndicatorSize.tab,
                      dividerColor: HasimTokens.lineColor(context),
                      tabs: const [
                        Tab(text: 'سجل الدفعات'),
                        Tab(text: 'النزاعات'),
                      ],
                    ),
                  );
                  final payouts = OutlinedButton.icon(
                    key: const Key('worker-open-payouts'),
                    onPressed: () => navigateWorkerDestination(
                      context,
                      workerPayoutsLocation,
                    ),
                    icon: const Icon(Icons.payments_outlined, size: 18),
                    label: const Text('فتح سجل الدفعات الكامل'),
                  );

                  if (constraints.maxWidth < 520) {
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        tabs,
                        const SizedBox(height: 8),
                        Align(
                          alignment: AlignmentDirectional.centerStart,
                          child: payouts,
                        ),
                      ],
                    );
                  }

                  return Row(
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      Expanded(child: tabs),
                      const SizedBox(width: 8),
                      payouts,
                    ],
                  );
                },
              ),
              const SizedBox(height: 16),
              if (_selectedTab == 0)
                ..._businessHistoryChildren(context, loading)
              else
                const _WorkerDisputesTab(),
            ],
          );
        },
      ),
    );
  }

  List<Widget> _businessHistoryChildren(BuildContext context, bool loading) {
    if (loading) {
      return const [
        _WorkerBusinessSkeletonCard(key: Key('worker-business-skeleton-1')),
        SizedBox(height: 12),
        _WorkerBusinessSkeletonCard(key: Key('worker-business-skeleton-2')),
      ];
    }
    if (widget.state.error != null && widget.items.isEmpty) {
      return [
        _EngagementError(message: widget.state.error!, onRetry: widget.onRetry),
      ];
    }
    if (widget.items.isEmpty) {
      return const [
        EmptyState(icon: Icons.work_outline, title: 'لا توجد ارتباطات حالياً'),
      ];
    }

    return [
      if (widget.state.error != null) ...[
        _EngagementError(message: widget.state.error!, onRetry: widget.onRetry),
        const SizedBox(height: 12),
      ],
      for (final item in widget.items) ...[
        _WorkerBusinessCard(engagement: item),
        const SizedBox(height: 12),
      ],
    ];
  }
}

class _WorkerDisputesTab extends StatelessWidget {
  const _WorkerDisputesTab();

  @override
  Widget build(BuildContext context) {
    return WorkerSectionCard(
      title: 'النزاعات',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'راجع نزاعات الارتباطات وتفاصيلها من شاشة النزاعات الحالية.',
          ),
          const SizedBox(height: 16),
          FilledButton.icon(
            key: const Key('worker-open-disputes'),
            onPressed: () =>
                navigateWorkerDestination(context, workerDisputesLocation),
            icon: const Icon(Icons.gavel_outlined),
            label: const Text('عرض النزاعات'),
          ),
        ],
      ),
    );
  }
}

class _WorkerOffersContent extends StatelessWidget {
  const _WorkerOffersContent({
    required this.state,
    required this.items,
    required this.onRetry,
  });

  final WorkerEngagementListState state;
  final List<WorkerEngagement> items;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final loading = state.loading && state.items.isEmpty;
    final list = <Widget>[
      if (HasimLayout.isCompact(context))
        _WorkerOffersHeader(
          onBack: () => navigateWorkerBack(context, '/conversations'),
        )
      else
        const HasimPageHeader(
          title: 'العروض',
          subtitle: 'عروض العمل المقدمة إليك',
        ),
      const SizedBox(height: 8),
      _WorkerOffersToolbar(
        onSearchTap: () => _showOffersCapabilityNotice(
          context,
          title: 'البحث في العروض',
          message:
              'البحث غير متاح في عقد عروض العامل الحالي. سيظهر هنا عند دعم البحث من الخادم.',
        ),
        onFilterTap: () => _showOffersFilters(context),
      ),
      if (state.loading && state.items.isNotEmpty) ...[
        const SizedBox(height: 12),
        const LinearProgressIndicator(minHeight: 2),
      ],
      const SizedBox(height: 16),
    ];

    if (loading) {
      list.addAll(const [
        _WorkerOfferSkeletonCard(key: Key('worker-offer-skeleton-1')),
        SizedBox(height: 12),
        _WorkerOfferSkeletonCard(key: Key('worker-offer-skeleton-2')),
      ]);
    } else if (state.error != null && items.isEmpty) {
      list.add(_EngagementError(message: state.error!, onRetry: onRetry));
    } else if (items.isEmpty) {
      list.add(
        const EmptyState(
          icon: Icons.local_offer_outlined,
          title: 'لا توجد عروض متاحة حاليًا',
        ),
      );
    } else {
      if (state.error != null) {
        list.add(_EngagementError(message: state.error!, onRetry: onRetry));
        list.add(const SizedBox(height: 12));
      }
      for (final item in items) {
        list
          ..add(_WorkerOfferCard(engagement: item))
          ..add(const SizedBox(height: 12));
      }
    }

    return Stack(
      fit: StackFit.expand,
      children: [
        const _WorkerOffersBackdrop(),
        ListView(
          key: loading ? const Key('worker-engagements-loading') : null,
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
          children: list,
        ),
      ],
    );
  }
}

class _WorkerOffersHeader extends StatelessWidget {
  const _WorkerOffersHeader({required this.onBack});

  final VoidCallback onBack;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 8, 4, 14),
      child: Directionality(
        textDirection: TextDirection.ltr,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Material(
              color: HasimTokens.mintFill(context),
              borderRadius: BorderRadius.circular(16),
              child: InkWell(
                key: const Key('worker-offers-back'),
                onTap: onBack,
                borderRadius: BorderRadius.circular(16),
                child: const SizedBox(
                  width: 56,
                  height: 56,
                  child: Icon(Icons.arrow_back_rounded, size: 28),
                ),
              ),
            ),
            const SizedBox(width: 16),
            Expanded(
              child: Directionality(
                textDirection: TextDirection.rtl,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      'العروض',
                      textAlign: TextAlign.right,
                      style: theme.textTheme.headlineMedium?.copyWith(
                        color: HasimTokens.inkColor(context),
                        fontWeight: FontWeight.w800,
                        height: 1.1,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'عروض العمل المقدمة إليك',
                      textAlign: TextAlign.right,
                      style: theme.textTheme.titleMedium?.copyWith(
                        color: HasimTokens.mutedColor(context),
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _WorkerOffersBackdrop extends StatelessWidget {
  const _WorkerOffersBackdrop();

  @override
  Widget build(BuildContext context) {
    if (HasimTokens.isDark(context)) {
      return const SizedBox.shrink();
    }

    return IgnorePointer(
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Positioned(
            top: 155,
            right: -115,
            child: Container(
              width: 330,
              height: 330,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: HasimTokens.mintSoft.withValues(alpha: 0.58),
              ),
            ),
          ),
          Positioned(
            top: 320,
            left: -145,
            child: Container(
              width: 290,
              height: 290,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: HasimTokens.mintSoft.withValues(alpha: 0.34),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _WorkerOffersToolbar extends StatelessWidget {
  const _WorkerOffersToolbar({
    required this.onSearchTap,
    required this.onFilterTap,
  });

  final VoidCallback onSearchTap;
  final VoidCallback onFilterTap;

  @override
  Widget build(BuildContext context) {
    final searchDecoration = BoxDecoration(
      color: HasimTokens.cardBg(context).withValues(alpha: 0.94),
      borderRadius: BorderRadius.circular(22),
      border: Border.all(color: HasimTokens.lineColor(context)),
      boxShadow: HasimTokens.softShadow(context),
    );
    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Expanded(
          child: DecoratedBox(
            decoration: searchDecoration,
            child: SizedBox(
              height: 62,
              child: _WorkerOffersSearchField(onTap: onSearchTap),
            ),
          ),
        ),
        const SizedBox(width: 10),
        SizedBox(
          width: 125,
          height: 62,
          child: OutlinedButton.icon(
            key: const Key('worker-offers-filters'),
            onPressed: onFilterTap,
            icon: const Icon(Icons.tune_rounded, size: 22),
            label: const Text('فلاتر'),
            style: OutlinedButton.styleFrom(
              foregroundColor: HasimTokens.primary,
              backgroundColor: HasimTokens.cardBg(
                context,
              ).withValues(alpha: 0.88),
              side: const BorderSide(color: HasimTokens.primary, width: 1.4),
              padding: const EdgeInsets.symmetric(horizontal: 14),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(20),
              ),
              textStyle: const TextStyle(
                fontWeight: FontWeight.w700,
                fontSize: 16,
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _WorkerOffersSearchField extends StatelessWidget {
  const _WorkerOffersSearchField({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return TextField(
      key: const Key('worker-offers-search'),
      readOnly: true,
      showCursor: false,
      onTap: onTap,
      textAlign: TextAlign.right,
      decoration: InputDecoration(
        hintText: 'ابحث في العروض...',
        hintStyle: TextStyle(
          color: HasimTokens.mutedColor(context),
          fontSize: 16,
          fontWeight: FontWeight.w500,
        ),
        prefixIcon: Icon(
          Icons.search_rounded,
          size: 28,
          color: HasimTokens.mutedColor(context),
        ),
        border: InputBorder.none,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 18,
        ),
      ),
    );
  }
}

Future<void> _showOffersFilters(BuildContext context) {
  return showModalBottomSheet<void>(
    context: context,
    backgroundColor: HasimTokens.pageBg(context),
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
    ),
    builder: (sheetContext) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    'الفلاتر',
                    key: const Key('worker-offers-filter-sheet-title'),
                    style: Theme.of(sheetContext).textTheme.titleLarge
                        ?.copyWith(fontWeight: FontWeight.w800),
                  ),
                ),
                IconButton(
                  key: const Key('worker-offers-filter-close'),
                  onPressed: () => Navigator.pop(sheetContext),
                  icon: const Icon(Icons.close_rounded),
                  tooltip: 'إغلاق',
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              'لا توجد فلاتر خادمية مدعومة حاليًا لعروض العامل. ستظهر هنا عند إضافتها إلى العقد.',
              style: Theme.of(sheetContext).textTheme.bodyMedium?.copyWith(
                color: HasimTokens.mutedColor(sheetContext),
                height: 1.5,
              ),
            ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: () => Navigator.pop(sheetContext),
              child: const Text('إغلاق'),
            ),
          ],
        ),
      ),
    ),
  );
}

Future<void> _showOffersCapabilityNotice(
  BuildContext context, {
  required String title,
  required String message,
}) {
  return showModalBottomSheet<void>(
    context: context,
    backgroundColor: HasimTokens.pageBg(context),
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
    ),
    builder: (sheetContext) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              title,
              style: Theme.of(
                sheetContext,
              ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 8),
            Text(
              message,
              style: Theme.of(sheetContext).textTheme.bodyMedium?.copyWith(
                color: HasimTokens.mutedColor(sheetContext),
                height: 1.5,
              ),
            ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: () => Navigator.pop(sheetContext),
              child: const Text('حسنًا'),
            ),
          ],
        ),
      ),
    ),
  );
}

class _WorkerOfferCard extends StatelessWidget {
  const _WorkerOfferCard({required this.engagement});

  final WorkerEngagement engagement;

  @override
  Widget build(BuildContext context) {
    final title = engagement.jobTitle?.trim().isNotEmpty == true
        ? engagement.jobTitle!.trim()
        : engagement.headline;
    final workspaceName = engagement.workspaceName?.trim() ?? '';

    void openDetails() {
      navigateWorkerDestination(
        context,
        '$workerEngagementsLocation/${engagement.id}',
      );
    }

    final cardRadius = BorderRadius.circular(24);
    final dateRows = <Widget>[
      if (engagement.startsAt != null)
        _WorkerOfferDateRow(
          icon: Icons.calendar_today_rounded,
          title: 'تاريخ البداية',
          value: _formatWorkerOfferDate(engagement.startsAt!),
        ),
      if (engagement.startsAt != null && engagement.endsAt != null)
        const SizedBox(height: 10),
      if (engagement.endsAt != null)
        _WorkerOfferDateRow(
          icon: Icons.event_rounded,
          title: 'تاريخ النهاية',
          value: _formatWorkerOfferDate(engagement.endsAt!),
        ),
    ];

    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context).withValues(alpha: 0.96),
        borderRadius: cardRadius,
        border: Border.all(color: HasimTokens.lineColor(context)),
        boxShadow: HasimTokens.isDark(context)
            ? const []
            : const [
                BoxShadow(
                  color: Color(0x140B8F82),
                  blurRadius: 24,
                  offset: Offset(0, 10),
                ),
              ],
      ),
      child: Material(
        color: Colors.transparent,
        borderRadius: cardRadius,
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          key: Key('worker-offer-${engagement.id}'),
          onTap: openDetails,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: 52,
                      height: 52,
                      decoration: BoxDecoration(
                        color: HasimTokens.mintFill(context),
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: Icon(
                        Icons.business_center_rounded,
                        color: HasimTokens.primary,
                        size: 28,
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            title,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: Theme.of(context).textTheme.titleLarge
                                ?.copyWith(
                                  color: HasimTokens.inkColor(context),
                                  fontWeight: FontWeight.w800,
                                ),
                          ),
                          if (workspaceName.isNotEmpty) ...[
                            const SizedBox(height: 4),
                            Text(
                              workspaceName,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: Theme.of(context).textTheme.bodyLarge
                                  ?.copyWith(
                                    color: HasimTokens.mutedColor(context),
                                    fontWeight: FontWeight.w600,
                                  ),
                            ),
                          ],
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    _WorkerOfferStatusBadge(status: engagement.status),
                  ],
                ),
                const SizedBox(height: 8),
                Align(
                  alignment: AlignmentDirectional.centerEnd,
                  child: _WorkerOfferIdBadge(id: engagement.id),
                ),
                if (dateRows.isNotEmpty) ...[
                  const SizedBox(height: 10),
                  _WorkerOfferDatePanel(
                    rows: dateRows,
                    onDetails: openDetails,
                    engagementId: engagement.id,
                  ),
                ],
                if (engagement.capabilities.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  Text(
                    'الصلاحيات الممنوحة',
                    style: Theme.of(context).textTheme.labelLarge?.copyWith(
                      color: HasimTokens.inkColor(context),
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final capability in engagement.capabilities)
                        _WorkerOfferCapability(
                          label: WorkerCapabilityPresentation.fromCapability(
                            capability,
                          ).title,
                        ),
                    ],
                  ),
                ],
                if (dateRows.isEmpty) ...[
                  const SizedBox(height: 16),
                  _WorkerOfferDetailsButton(
                    engagementId: engagement.id,
                    onPressed: openDetails,
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _WorkerOfferDatePanel extends StatelessWidget {
  const _WorkerOfferDatePanel({
    required this.rows,
    required this.onDetails,
    required this.engagementId,
  });

  final List<Widget> rows;
  final VoidCallback onDetails;
  final int engagementId;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context).withValues(alpha: 0.82),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(
          color: HasimTokens.lineColor(context).withValues(alpha: 0.72),
        ),
        boxShadow: HasimTokens.isDark(context)
            ? const []
            : const [
                BoxShadow(
                  color: Color(0x0A0B8F82),
                  blurRadius: 14,
                  offset: Offset(0, 5),
                ),
              ],
      ),
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ...rows,
            const SizedBox(height: 10),
            _WorkerOfferDetailsButton(
              engagementId: engagementId,
              onPressed: onDetails,
            ),
          ],
        ),
      ),
    );
  }
}

class _WorkerOfferDateRow extends StatelessWidget {
  const _WorkerOfferDateRow({
    required this.icon,
    required this.title,
    required this.value,
  });

  final IconData icon;
  final String title;
  final String value;

  @override
  Widget build(BuildContext context) {
    final rowColor = HasimTokens.isDark(context)
        ? const Color(0xFF191919)
        : const Color(0xFFF4F9FB);

    return Container(
      constraints: const BoxConstraints(minHeight: 58),
      padding: const EdgeInsets.fromLTRB(10, 6, 10, 6),
      decoration: BoxDecoration(
        color: rowColor,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Directionality(
        textDirection: TextDirection.ltr,
        child: Row(
          children: [
            Expanded(
              child: Directionality(
                textDirection: TextDirection.rtl,
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      textAlign: TextAlign.right,
                      style: TextStyle(
                        color: HasimTokens.mutedColor(context),
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      value,
                      textAlign: TextAlign.right,
                      style: TextStyle(
                        color: HasimTokens.inkColor(context),
                        fontSize: 16,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(width: 10),
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: HasimTokens.mintFill(context),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(
                icon,
                size: 22,
                color: HasimTokens.mutedColor(context),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _WorkerOfferDetailsButton extends StatelessWidget {
  const _WorkerOfferDetailsButton({
    required this.engagementId,
    required this.onPressed,
  });

  final int engagementId;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 52,
      width: double.infinity,
      child: FilledButton.icon(
        key: Key('worker-offer-details-$engagementId'),
        onPressed: onPressed,
        icon: const Icon(Icons.arrow_forward_rounded, size: 22),
        label: const Text('عرض التفاصيل'),
        style: FilledButton.styleFrom(
          backgroundColor: HasimTokens.primary,
          foregroundColor: Colors.white,
          elevation: 0,
          padding: const EdgeInsets.symmetric(horizontal: 16),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
          textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800),
        ),
      ),
    );
  }
}

String _formatWorkerOfferDate(String value) {
  final raw = value.trim();
  final parsed = DateTime.tryParse(raw);
  if (parsed == null) return raw;

  final date = raw.length == 10 ? parsed : parsed.toLocal();
  final day = date.day.toString().padLeft(2, '0');
  final month = date.month.toString().padLeft(2, '0');
  return '$day-$month-${date.year}';
}

class _WorkerOfferStatusBadge extends StatelessWidget {
  const _WorkerOfferStatusBadge({required this.status});

  final String status;

  @override
  Widget build(BuildContext context) {
    final (background, foreground) = switch (status) {
      'offered' => (HasimTokens.mintFill(context), HasimTokens.accentDark),
      'accepted' || 'active' || 'completed' => (
        HasimTokens.success.withValues(alpha: 0.12),
        HasimTokens.success,
      ),
      'rejected' || 'terminated' => (
        HasimTokens.dangerSoftColor(context),
        HasimTokens.danger,
      ),
      'paused' => (
        HasimTokens.warning.withValues(alpha: 0.14),
        HasimTokens.warning,
      ),
      _ => (HasimTokens.lineColor(context), HasimTokens.mutedColor(context)),
    };
    return DecoratedBox(
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(HasimTokens.radiusPill),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 8,
              height: 8,
              decoration: BoxDecoration(
                color: foreground,
                shape: BoxShape.circle,
              ),
            ),
            const SizedBox(width: 6),
            Text(
              WorkerEngagement.statusLabels[status] ?? 'حالة العرض',
              style: TextStyle(
                color: foreground,
                fontWeight: FontWeight.w700,
                fontSize: 12,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _WorkerOfferIdBadge extends StatelessWidget {
  const _WorkerOfferIdBadge({required this.id});

  final int id;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.mintFill(context),
        borderRadius: BorderRadius.circular(HasimTokens.radiusPill),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        child: Text(
          '#$id',
          style: TextStyle(
            color: HasimTokens.accentDark,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    );
  }
}

class _WorkerOfferCapability extends StatelessWidget {
  const _WorkerOfferCapability({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.mintFill(context),
        borderRadius: BorderRadius.circular(HasimTokens.radiusPill),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        child: Text(
          label,
          style: TextStyle(
            color: HasimTokens.accentDark,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
    );
  }
}

class _WorkerOfferSkeletonCard extends StatelessWidget {
  const _WorkerOfferSkeletonCard({super.key});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: HasimTokens.cardBg(context),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(28),
        side: BorderSide(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                const _WorkerOfferSkeletonBlock(width: 52, height: 52),
                const SizedBox(width: 12),
                const Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _WorkerOfferSkeletonBlock(width: 180, height: 16),
                      SizedBox(height: 8),
                      _WorkerOfferSkeletonBlock(width: 110, height: 12),
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                const _WorkerOfferSkeletonBlock(width: 62, height: 28),
              ],
            ),
            const SizedBox(height: 16),
            const Row(
              children: [
                _WorkerOfferSkeletonBlock(width: 46, height: 28),
                SizedBox(width: 8),
                Expanded(child: _WorkerOfferSkeletonBlock(height: 28)),
              ],
            ),
            const SizedBox(height: 16),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: _WorkerOfferSkeletonBlock(width: 128, height: 44),
            ),
          ],
        ),
      ),
    );
  }
}

class _WorkerOfferSkeletonBlock extends StatelessWidget {
  const _WorkerOfferSkeletonBlock({this.width, required this.height});

  final double? width;
  final double height;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: HasimTokens.lineColor(context),
        borderRadius: BorderRadius.circular(8),
      ),
    );
  }
}

class _EngagementError extends StatelessWidget {
  const _EngagementError({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        WorkerInlineMessage(message, error: true),
        TextButton(
          key: const Key('worker-engagements-retry'),
          onPressed: onRetry,
          child: const Text('إعادة المحاولة'),
        ),
      ],
    );
  }
}

class _WorkerBusinessCard extends StatelessWidget {
  const _WorkerBusinessCard({required this.engagement});

  final WorkerEngagement engagement;

  @override
  Widget build(BuildContext context) {
    void openDetails() {
      navigateWorkerDestination(
        context,
        '$workerEngagementsLocation/${engagement.id}',
      );
    }

    final workspaceName = engagement.workspaceName?.trim() ?? '';
    final title = engagement.jobTitle?.trim().isNotEmpty == true
        ? engagement.jobTitle!.trim()
        : engagement.headline;
    final actions = [
      for (final capability in engagement.capabilities)
        if (capability.key.trim().isNotEmpty) _businessAction(capability),
    ];

    return Material(
      color: HasimTokens.cardBg(context),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(HasimTokens.radiusCard),
        side: BorderSide(color: HasimTokens.lineColor(context)),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        key: Key('worker-engagement-${engagement.id}'),
        onTap: openDetails,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 56,
                    height: 56,
                    decoration: BoxDecoration(
                      color: HasimTokens.mintFill(context),
                      borderRadius: BorderRadius.circular(16),
                    ),
                    child: Icon(
                      Icons.storefront_outlined,
                      size: 30,
                      color: HasimTokens.primary,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          workspaceName.isEmpty ? title : workspaceName,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.titleMedium
                              ?.copyWith(fontWeight: FontWeight.w800),
                        ),
                        if (workspaceName.isNotEmpty &&
                            title != workspaceName) ...[
                          const SizedBox(height: 4),
                          Text(
                            title,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: Theme.of(context).textTheme.bodyMedium
                                ?.copyWith(
                                  color: HasimTokens.mutedColor(context),
                                  fontWeight: FontWeight.w600,
                                ),
                          ),
                        ],
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  _WorkerOfferIdBadge(id: engagement.id),
                ],
              ),
              const SizedBox(height: 12),
              _WorkerBusinessStatusBar(status: engagement.status),
              if (engagement.startsAt != null || engagement.endsAt != null) ...[
                const SizedBox(height: 10),
                _BusinessDateRow(
                  startsAt: engagement.startsAt,
                  endsAt: engagement.endsAt,
                ),
              ],
              const SizedBox(height: 16),
              if (actions.isEmpty)
                const Text('لا توجد صلاحيات تشغيلية لهذا الارتباط.')
              else
                _BusinessActionGrid(
                  engagementId: engagement.id,
                  actions: actions,
                  onDetails: openDetails,
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _WorkerBusinessStatusBar extends StatelessWidget {
  const _WorkerBusinessStatusBar({required this.status});

  final String status;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.mintFill(context),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
        child: Align(
          alignment: AlignmentDirectional.centerEnd,
          child: Text(
            WorkerEngagement.statusLabels[status] ?? 'الحالة',
            style: TextStyle(
              color: HasimTokens.accentDark,
              fontWeight: FontWeight.w800,
              fontSize: 12,
            ),
          ),
        ),
      ),
    );
  }
}

class _BusinessDateRow extends StatelessWidget {
  const _BusinessDateRow({this.startsAt, this.endsAt});

  final String? startsAt;
  final String? endsAt;

  @override
  Widget build(BuildContext context) {
    final children = [
      if (startsAt != null)
        Expanded(
          child: _BusinessDateCard(
            icon: Icons.calendar_today_outlined,
            label: 'تاريخ البداية',
            value: startsAt!,
          ),
        ),
      if (startsAt != null && endsAt != null) const SizedBox(width: 8),
      if (endsAt != null)
        Expanded(
          child: _BusinessDateCard(
            icon: Icons.event_outlined,
            label: 'تاريخ النهاية',
            value: endsAt!,
          ),
        ),
    ];

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: children,
    );
  }
}

class _BusinessDateCard extends StatelessWidget {
  const _BusinessDateCard({
    required this.icon,
    required this.label,
    required this.value,
  });

  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.pageBg(context),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 9),
        child: Row(
          children: [
            Icon(icon, size: 18, color: HasimTokens.mutedColor(context)),
            const SizedBox(width: 7),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.labelSmall?.copyWith(
                      color: HasimTokens.mutedColor(context),
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    value,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textDirection: TextDirection.ltr,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      fontWeight: FontWeight.w800,
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

class _BusinessActionGrid extends StatelessWidget {
  const _BusinessActionGrid({
    required this.engagementId,
    required this.actions,
    required this.onDetails,
  });

  final int engagementId;
  final List<_BusinessAction> actions;
  final VoidCallback onDetails;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns =
            HasimLayout.isCompact(context) || constraints.maxWidth < 600
            ? 2
            : 3;
        final spacing = 6.0;
        final width =
            (constraints.maxWidth - (columns - 1) * spacing) / columns;

        final primary = actions.take(columns).toList();
        final secondary = actions.skip(columns).toList();
        final secondaryWidth = (constraints.maxWidth - spacing) / 2;

        return Column(
          children: [
            Wrap(
              spacing: spacing,
              runSpacing: spacing,
              children: [
                for (final action in primary)
                  SizedBox(
                    width: width,
                    child: _BusinessActionCard(
                      engagementId: engagementId,
                      action: action,
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 8),
            if (secondary.isNotEmpty)
              Wrap(
                spacing: spacing,
                runSpacing: spacing,
                children: [
                  for (final action in secondary)
                    SizedBox(
                      width: secondaryWidth,
                      child: _BusinessActionCard(
                        engagementId: engagementId,
                        action: action,
                      ),
                    ),
                  SizedBox(
                    width: secondaryWidth,
                    child: OutlinedButton.icon(
                      key: Key('worker-engagement-details-$engagementId'),
                      onPressed: onDetails,
                      icon: const Icon(Icons.arrow_back_rounded, size: 18),
                      label: const Text('عرض التفاصيل'),
                    ),
                  ),
                ],
              )
            else
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: OutlinedButton.icon(
                  key: Key('worker-engagement-details-$engagementId'),
                  onPressed: onDetails,
                  icon: const Icon(Icons.arrow_back_rounded, size: 18),
                  label: const Text('عرض التفاصيل'),
                ),
              ),
          ],
        );
      },
    );
  }
}

class _BusinessActionCard extends StatelessWidget {
  const _BusinessActionCard({required this.engagementId, required this.action});

  final int engagementId;
  final _BusinessAction action;

  @override
  Widget build(BuildContext context) {
    void openAction() {
      if (action.key == 'inbox.messages.read' ||
          action.key == 'inbox.messages.send') {
        context.push('/conversations');
        return;
      }
      navigateWorkerDestination(
        context,
        '$workerEngagementsLocation/$engagementId',
      );
    }

    return Material(
      color: action.backgroundColor,
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        key: Key('worker-business-action-$engagementId-${action.key}'),
        onTap: openAction,
        borderRadius: BorderRadius.circular(14),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 7),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              DecoratedBox(
                decoration: BoxDecoration(
                  color: action.iconColor.withValues(alpha: 0.14),
                  borderRadius: BorderRadius.circular(11),
                ),
                child: Padding(
                  padding: const EdgeInsets.all(7),
                  child: Icon(action.icon, size: 18, color: action.iconColor),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      action.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.labelLarge?.copyWith(
                        fontSize: 13,
                        fontWeight: FontWeight.w800,
                        height: 1.1,
                      ),
                    ),
                    if (action.subtitle != null) ...[
                      const SizedBox(height: 2),
                      Text(
                        action.subtitle!,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.labelSmall?.copyWith(
                          color: HasimTokens.mutedColor(context),
                          fontSize: 10,
                          height: 1.1,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 4),
              Icon(
                Icons.chevron_left_rounded,
                size: 18,
                color: HasimTokens.mutedColor(context),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _BusinessAction {
  const _BusinessAction({
    required this.key,
    required this.title,
    required this.icon,
    required this.iconColor,
    required this.backgroundColor,
    this.subtitle,
  });

  final String key;
  final String title;
  final String? subtitle;
  final IconData icon;
  final Color iconColor;
  final Color backgroundColor;
}

_BusinessAction _businessAction(WorkerCapability capability) {
  final presentation = WorkerCapabilityPresentation.fromCapability(capability);

  return _BusinessAction(
    key: capability.key,
    title: presentation.title,
    subtitle: presentation.description,
    icon: presentation.icon,
    iconColor: presentation.categoryColor,
    backgroundColor: presentation.categoryColor.withValues(alpha: 0.07),
  );
}

class _WorkerBusinessSkeletonCard extends StatelessWidget {
  const _WorkerBusinessSkeletonCard({super.key});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: HasimTokens.cardBg(context),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(HasimTokens.radiusCard),
        side: BorderSide(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Row(
              children: [
                _WorkerOfferSkeletonBlock(width: 56, height: 56),
                SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _WorkerOfferSkeletonBlock(width: 150, height: 16),
                      SizedBox(height: 8),
                      _WorkerOfferSkeletonBlock(width: 100, height: 12),
                    ],
                  ),
                ),
                SizedBox(width: 8),
                _WorkerOfferSkeletonBlock(width: 44, height: 28),
              ],
            ),
            const SizedBox(height: 14),
            const _WorkerOfferSkeletonBlock(height: 28),
            const SizedBox(height: 14),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _WorkerOfferSkeletonBlock(width: 130, height: 64),
                _WorkerOfferSkeletonBlock(width: 130, height: 64),
                _WorkerOfferSkeletonBlock(width: 130, height: 64),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
