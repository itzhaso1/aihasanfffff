import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/widgets/hasim_shell_header.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';

/// شاشة «التحديثات» المستقلة — Stories / Status (ليست جزءًا من المحادثات).
class UpdatesScreen extends ConsumerWidget {
  const UpdatesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(storiesControllerProvider);
    final auth = ref.watch(authControllerProvider);
    final theme = Theme.of(context);

    final mine = state.buckets.where((b) => b.isMine).firstOrNull;
    final others = state.buckets.where((b) => !b.isMine && b.stories.isNotEmpty).toList();
    final myAvatar = mine?.avatarPath ?? auth.user?.avatarUrl;

    return Scaffold(
      appBar: const HasimShellHeader(),
      body: RefreshIndicator(
        onRefresh: () => ref.read(storiesControllerProvider.notifier).refresh(force: true),
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
          children: [
            Text(
              'التحديثات',
              style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 20),
            Center(
              child: _MyStatusTile(
                label: 'حالتي',
                avatarPath: myAvatar,
                hasStories: mine?.stories.isNotEmpty == true,
                onTap: () {
                  if (mine != null && mine.stories.isNotEmpty) {
                    final viewable = state.buckets.where((b) => b.stories.isNotEmpty).toList();
                    final idx = viewable.indexWhere((b) => b.authorKey == mine.authorKey);
                    context.push('/stories/view', extra: {
                      'buckets': viewable,
                      'bucketIndex': idx < 0 ? 0 : idx,
                    });
                  } else {
                    context.push('/stories/create');
                  }
                },
                onAdd: () => context.push('/stories/create'),
              ),
            ),
            if (state.loading && state.buckets.isEmpty) ...[
              const SizedBox(height: 48),
              const Center(child: CircularProgressIndicator()),
            ] else if (state.error != null && state.buckets.isEmpty) ...[
              const SizedBox(height: 24),
              Text(state.error!, style: TextStyle(color: theme.colorScheme.error)),
              TextButton(
                onPressed: () => ref.read(storiesControllerProvider.notifier).refresh(),
                child: const Text('إعادة المحاولة'),
              ),
            ] else ...[
              const SizedBox(height: 28),
              if (others.isEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 32),
                  child: Column(
                    children: [
                      Icon(Icons.auto_stories_outlined, size: 40, color: theme.colorScheme.onSurfaceVariant),
                      const SizedBox(height: 10),
                      Text(
                        'لا توجد تحديثات حالياً',
                        style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'اضغط + لإنشاء تحديثك',
                        style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                      ),
                    ],
                  ),
                )
              else ...[
                Text(
                  'التحديثات الأخيرة',
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: 12),
                Wrap(
                  spacing: 16,
                  runSpacing: 16,
                  children: [
                    for (final bucket in others)
                      _OtherStatusTile(
                        label: bucket.authorName,
                        avatarPath: bucket.avatarPath,
                        hasUnviewed: bucket.hasUnviewed,
                        onTap: () {
                          final viewable = state.buckets.where((b) => b.stories.isNotEmpty).toList();
                          final idx = viewable.indexWhere((b) => b.authorKey == bucket.authorKey);
                          context.push('/stories/view', extra: {
                            'buckets': viewable,
                            'bucketIndex': idx < 0 ? 0 : idx,
                          });
                        },
                      ),
                  ],
                ),
                const SizedBox(height: 24),
                Text(
                  'اضغط لمشاهدة التحديث',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
              ],
            ],
          ],
        ),
      ),
    );
  }
}

class _MyStatusTile extends StatelessWidget {
  const _MyStatusTile({
    required this.label,
    required this.hasStories,
    required this.onTap,
    required this.onAdd,
    this.avatarPath,
  });

  final String label;
  final String? avatarPath;
  final bool hasStories;
  final VoidCallback onTap;
  final VoidCallback onAdd;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ring = hasStories ? AppTheme.brand : theme.dividerColor;

    return Column(
      children: [
        Stack(
          clipBehavior: Clip.none,
          children: [
            InkWell(
              onTap: onTap,
              customBorder: const CircleBorder(),
              child: Container(
                padding: const EdgeInsets.all(3),
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(color: ring, width: 2.5),
                ),
                child: _Avatar(radius: 36, label: label, avatarPath: avatarPath),
              ),
            ),
            PositionedDirectional(
              end: 0,
              bottom: 0,
              child: Material(
                color: AppTheme.brand,
                shape: const CircleBorder(),
                elevation: 1,
                child: InkWell(
                  customBorder: const CircleBorder(),
                  onTap: onAdd,
                  child: const Padding(
                    padding: EdgeInsets.all(6),
                    child: Icon(Icons.add, size: 18, color: Colors.white),
                  ),
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Text(label, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
      ],
    );
  }
}

class _OtherStatusTile extends StatelessWidget {
  const _OtherStatusTile({
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
