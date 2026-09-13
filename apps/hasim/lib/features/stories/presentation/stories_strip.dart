import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';

class StoriesStrip extends ConsumerWidget {
  const StoriesStrip({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(storiesControllerProvider);

    if (state.loading && state.buckets.isEmpty) {
      return const SizedBox(
        height: 98,
        child: Center(child: SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2))),
      );
    }

    if (state.error != null && state.buckets.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Text(state.error!, style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 13)),
      );
    }

    return SizedBox(
      height: 104,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(vertical: 4),
        itemCount: state.buckets.length,
        separatorBuilder: (_, _) => const SizedBox(width: 12),
        itemBuilder: (context, index) {
          final bucket = state.buckets[index];
          final hasStories = bucket.stories.isNotEmpty;
          return _StoryAvatar(
            label: bucket.authorName,
            isMine: bucket.isMine,
            hasStories: hasStories,
            avatarPath: bucket.avatarPath,
            onTap: () {
              if (bucket.isMine && !hasStories) {
                context.push('/stories/create');
                return;
              }
              if (!hasStories) return;
              context.push('/stories/view', extra: {
                'buckets': state.buckets.where((b) => b.stories.isNotEmpty).toList(),
                'bucketIndex': state.buckets
                    .where((b) => b.stories.isNotEmpty)
                    .toList()
                    .indexWhere((b) => b.authorKey == bucket.authorKey),
              });
            },
            onAdd: bucket.isMine ? () => context.push('/stories/create') : null,
          );
        },
      ),
    );
  }
}

class _StoryAvatar extends StatelessWidget {
  const _StoryAvatar({
    required this.label,
    required this.isMine,
    required this.hasStories,
    required this.onTap,
    this.avatarPath,
    this.onAdd,
  });

  final String label;
  final bool isMine;
  final bool hasStories;
  final String? avatarPath;
  final VoidCallback onTap;
  final VoidCallback? onAdd;

  @override
  Widget build(BuildContext context) {
    final ring = hasStories ? AppTheme.brand : Colors.grey.shade300;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(40),
      child: SizedBox(
        width: 72,
        child: Column(
          children: [
            Stack(
              clipBehavior: Clip.none,
              children: [
                Container(
                  padding: const EdgeInsets.all(2.5),
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    border: Border.all(color: ring, width: 2.2),
                  ),
                  child: CircleAvatar(
                    radius: 28,
                    backgroundColor: AppTheme.brand.withValues(alpha: 0.12),
                    backgroundImage: avatarPath != null && avatarPath!.startsWith('http')
                        ? CachedNetworkImageProvider(avatarPath!)
                        : null,
                    child: avatarPath == null || !avatarPath!.startsWith('http')
                        ? Text(
                            label.isNotEmpty ? label[0] : '?',
                            style: const TextStyle(fontWeight: FontWeight.w800, color: AppTheme.brandDark),
                          )
                        : null,
                  ),
                ),
                if (isMine && onAdd != null)
                  PositionedDirectional(
                    end: -2,
                    bottom: -2,
                    child: Material(
                      color: AppTheme.brand,
                      shape: const CircleBorder(),
                      child: InkWell(
                        customBorder: const CircleBorder(),
                        onTap: onAdd,
                        child: const Padding(
                          padding: EdgeInsets.all(3),
                          child: Icon(Icons.add, size: 14, color: Colors.white),
                        ),
                      ),
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600),
            ),
          ],
        ),
      ),
    );
  }
}
