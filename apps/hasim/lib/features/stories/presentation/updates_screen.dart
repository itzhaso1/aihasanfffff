import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/hasim_feature_card.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/core/widgets/hasim_profile_bubble.dart';
import 'package:hasim/core/widgets/hasim_search_bar.dart';
import 'package:hasim/core/widgets/hasim_shell_header.dart';
import 'package:hasim/core/widgets/hasim_wave_backdrop.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/stories/presentation/widgets/updates_empty_illustration.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
import 'package:hasim/features/worker/presentation/worker_capability_notice.dart';
import 'package:hasim/features/worker/providers/worker_capability_access.dart';

/// شاشة «التحديثات» المستقلة — Stories / Status (ليست جزءًا من المحادثات).
class UpdatesScreen extends ConsumerStatefulWidget {
  const UpdatesScreen({super.key});

  @override
  ConsumerState<UpdatesScreen> createState() => _UpdatesScreenState();
}

class _UpdatesScreenState extends ConsumerState<UpdatesScreen> {
  final _search = TextEditingController();
  String _query = '';

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  void _openCreate() => context.push('/stories/create');

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    if (auth.isIndependentWorker) {
      final access = ref.watch(
        workerWorkspaceCapabilityProvider('stories.view'),
      );
      if (access.isLoading) {
        return const Scaffold(body: Center(child: CircularProgressIndicator()));
      }
      if (access.hasError) {
        return WorkerCapabilityNotice(
          title: 'التحديثات',
          message: 'تعذر التحقق من صلاحيات التحديثات حالياً.',
          onRetry: () =>
              ref.invalidate(workerWorkspaceCapabilityProvider('stories.view')),
        );
      }
      final permission = access.value;
      if (permission == null || !permission.workspaceSelected) {
        return const WorkerCapabilityNotice(
          title: 'التحديثات',
          message: 'اختر شركة أولاً للوصول إلى التحديثات.',
        );
      }
      if (!permission.allowed) {
        return const WorkerCapabilityNotice(
          title: 'التحديثات',
          message: 'ليس لديك صلاحيات الوصول إلى التحديثات.',
        );
      }
    }

    final state = ref.watch(storiesControllerProvider);
    final canCreate = !auth.isIndependentWorker;
    final theme = Theme.of(context);
    final mint = HasimTokens.pageBg(context);

    final mine = state.buckets.where((b) => b.isMine).firstOrNull;
    final others = state.buckets
        .where((b) => !b.isMine && b.stories.isNotEmpty)
        .toList();
    final myAvatar = mine?.avatarPath ?? auth.user?.avatarUrl;
    final initial = (auth.user?.name.isNotEmpty == true
        ? auth.user!.name
        : 'ح');
    final filteredOthers = _query.trim().isEmpty
        ? others
        : others.where((b) => b.authorName.contains(_query.trim())).toList();
    final width = MediaQuery.sizeOf(context).width;
    final compactCards = width < 360;

    return Scaffold(
      backgroundColor: mint,
      appBar: HasimShellHeader(
        backgroundColor: mint,
        toolbarHeight: 72,
        brandLeading: HasimProfileBubble(
          key: const Key('updates-profile-bubble'),
          label: initial,
          avatarPath: myAvatar,
          hasStories: mine?.stories.isNotEmpty == true,
          onTap: () {
            if (mine != null && mine.stories.isNotEmpty) {
              final viewable = state.buckets
                  .where((b) => b.stories.isNotEmpty)
                  .toList();
              final idx = viewable.indexWhere(
                (b) => b.authorKey == mine.authorKey,
              );
              context.push(
                '/stories/view',
                extra: {'buckets': viewable, 'bucketIndex': idx < 0 ? 0 : idx},
              );
            } else if (canCreate) {
              _openCreate();
            }
          },
          onAdd: canCreate ? _openCreate : null,
        ),
      ),
      body: Stack(
        children: [
          const Align(
            alignment: Alignment.bottomCenter,
            child: HasimWaveBackdrop(height: 140),
          ),
          RefreshIndicator(
            onRefresh: () => ref
                .read(storiesControllerProvider.notifier)
                .refresh(force: true),
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 36),
              children: [
                const HasimPageHeader(
                  title: 'التحديثات',
                  subtitle: 'تابع كل جديد في مكان واحد',
                ),
                HasimSearchBar(
                  controller: _search,
                  hintText: 'ابحث في التحديثات...',
                  filledMint: true,
                  onChanged: (value) => setState(() => _query = value),
                  onSubmitted: (value) => setState(() => _query = value),
                  onClear: () => setState(() => _query = ''),
                ),
                if (state.loading && state.buckets.isEmpty) ...[
                  const SizedBox(height: 64),
                  const Center(child: CircularProgressIndicator()),
                ] else if (state.error != null && state.buckets.isEmpty) ...[
                  const SizedBox(height: 24),
                  Text(
                    state.error!,
                    style: TextStyle(color: theme.colorScheme.error),
                  ),
                  TextButton(
                    onPressed: () =>
                        ref.read(storiesControllerProvider.notifier).refresh(),
                    child: const Text('إعادة المحاولة'),
                  ),
                ] else if (filteredOthers.isEmpty) ...[
                  const SizedBox(height: 36),
                  EmptyState(
                    title: 'لا توجد تحديثات حالياً',
                    subtitle:
                        'ستظهر هنا آخر التحديثات والمستجدات من حسابك أو من المصادر التي تتابعها.',
                    illustration: const UpdatesEmptyIllustration(),
                    actionLabel: canCreate ? '+ اضغط + لإنشاء تحديثك' : null,
                    actionIcon: canCreate ? Icons.add_rounded : null,
                    pillAction: true,
                    onAction: canCreate ? _openCreate : null,
                  ),
                  const SizedBox(height: 18),
                  Builder(
                    builder: (context) {
                      final cards = _featureCards();
                      if (compactCards) {
                        return SizedBox(
                          height: 128,
                          child: ListView.separated(
                            scrollDirection: Axis.horizontal,
                            itemCount: cards.length,
                            separatorBuilder: (_, _) =>
                                const SizedBox(width: 8),
                            itemBuilder: (context, i) =>
                                SizedBox(width: 148, child: cards[i]),
                          ),
                        );
                      }
                      return Row(
                        children: [
                          for (var i = 0; i < cards.length; i++) ...[
                            if (i > 0) const SizedBox(width: 8),
                            Expanded(child: cards[i]),
                          ],
                        ],
                      );
                    },
                  ),
                ] else ...[
                  const SizedBox(height: 22),
                  Text(
                    'التحديثات الأخيرة',
                    style: theme.textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: HasimTokens.mutedColor(context),
                    ),
                  ),
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 16,
                    runSpacing: 16,
                    children: [
                      for (final bucket in filteredOthers)
                        _OtherStatusTile(
                          key: Key('updates-author-${bucket.authorKey}'),
                          label: bucket.authorName,
                          avatarPath: bucket.avatarPath,
                          hasUnviewed: bucket.hasUnviewed,
                          onTap: () {
                            final viewable = state.buckets
                                .where((b) => b.stories.isNotEmpty)
                                .toList();
                            final idx = viewable.indexWhere(
                              (b) => b.authorKey == bucket.authorKey,
                            );
                            context.push(
                              '/stories/view',
                              extra: {
                                'buckets': viewable,
                                'bucketIndex': idx < 0 ? 0 : idx,
                              },
                            );
                          },
                        ),
                    ],
                  ),
                  const SizedBox(height: 24),
                  Text(
                    'اضغط لمشاهدة التحديث',
                    textAlign: TextAlign.center,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: HasimTokens.mutedColor(context),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  List<Widget> _featureCards() {
    return const [
      HasimFeatureCard(
        title: 'شارك أفكارك',
        subtitle: 'مع متابعيك',
        icon: Icons.person_outline_rounded,
        tint: Color(0xFFE8F8F3),
      ),
      HasimFeatureCard(
        title: 'ابقَ على اطلاع',
        subtitle: 'بكل جديد',
        icon: Icons.notifications_none_rounded,
        tint: Color(0xFFEEF4FB),
      ),
      HasimFeatureCard(
        title: 'نصائح ومحتوى',
        subtitle: 'من اهتماماتك',
        icon: Icons.auto_awesome,
        tint: Color(0xFFFFF6E8),
      ),
    ];
  }
}

class _OtherStatusTile extends StatelessWidget {
  const _OtherStatusTile({
    super.key,
    required this.label,
    required this.hasUnviewed,
    required this.onTap,
    this.avatarPath,
  });

  final String label;
  final String? avatarPath;
  final bool hasUnviewed;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ring = hasUnviewed ? AppTheme.brand : theme.dividerColor;

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(40),
      child: SizedBox(
        width: 76,
        child: Column(
          children: [
            Container(
              padding: const EdgeInsets.all(2.5),
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                border: Border.all(color: ring, width: hasUnviewed ? 2.5 : 1.5),
              ),
              child: _Avatar(radius: 28, label: label, avatarPath: avatarPath),
            ),
            const SizedBox(height: 8),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: 12,
                fontWeight: hasUnviewed ? FontWeight.w800 : FontWeight.w600,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar({required this.radius, required this.label, this.avatarPath});

  final double radius;
  final String label;
  final String? avatarPath;

  @override
  Widget build(BuildContext context) {
    final hasHttp = avatarPath != null && avatarPath!.startsWith('http');
    return CircleAvatar(
      radius: radius,
      backgroundColor: AppTheme.brand.withValues(alpha: 0.12),
      backgroundImage: hasHttp ? CachedNetworkImageProvider(avatarPath!) : null,
      child: hasHttp
          ? null
          : Text(
              label.isNotEmpty ? label[0] : '؟',
              style: TextStyle(
                fontWeight: FontWeight.w800,
                fontSize: radius * 0.7,
                color: AppTheme.brandDark,
              ),
            ),
    );
  }
}
