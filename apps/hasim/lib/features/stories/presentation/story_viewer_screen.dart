import 'dart:async';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/stories/presentation/widgets/story_viewers_sheet.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
import 'package:video_player/video_player.dart';

class StoryViewerArgs {
  const StoryViewerArgs({
    required this.buckets,
    this.bucketIndex = 0,
    this.storyIndex = 0,
  });

  final List<StoryBucket> buckets;
  final int bucketIndex;
  final int storyIndex;
}

class StoryViewerScreen extends ConsumerStatefulWidget {
  const StoryViewerScreen({super.key, required this.args});

  final StoryViewerArgs args;

  @override
  ConsumerState<StoryViewerScreen> createState() => _StoryViewerScreenState();
}

class _StoryViewerScreenState extends ConsumerState<StoryViewerScreen> {
  late List<StoryBucket> _buckets;
  late int _bucketIndex;
  late int _storyIndex;
  Timer? _timer;
  double _progress = 0;
  bool _paused = false;
  bool _busy = false;
  final Set<int> _viewed = {};
  VideoPlayerController? _video;

  StoryBucket get _bucket => _buckets[_bucketIndex];
  StoryModel get _story => _bucket.stories[_storyIndex];

  Duration get _duration {
    if (_story.isVideo) {
      final v = _video;
      if (v != null && v.value.isInitialized && v.value.duration.inMilliseconds > 0) {
        return v.value.duration;
      }
      return const Duration(seconds: 15);
    }
    return const Duration(seconds: 5);
  }

  @override
  void initState() {
    super.initState();
    _buckets = [
      for (final bucket in widget.args.buckets)
        bucket.copyWith(stories: List<StoryModel>.of(bucket.stories)),
    ];
    _bucketIndex = widget.args.bucketIndex.clamp(0, _buckets.length - 1);
    _storyIndex = widget.args.storyIndex.clamp(0, _bucket.stories.length - 1);
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await _prepareMedia();
      _markViewed();
      _startTimer();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    _video?.dispose();
    super.dispose();
  }

  Future<void> _prepareMedia() async {
    await _video?.dispose();
    _video = null;
    final story = _story;
    if (!story.isVideo || story.mediaUrl == null) return;
    final controller = VideoPlayerController.networkUrl(Uri.parse(story.mediaUrl!));
    try {
      await controller.initialize();
      await controller.setLooping(false);
      await controller.play();
      if (!mounted) {
        await controller.dispose();
        return;
      }
      setState(() => _video = controller);
    } catch (_) {
      await controller.dispose();
    }
  }

  Future<void> _markViewed() async {
    final id = _story.id;
    if (_viewed.contains(id) || _story.isMine) return;
    _viewed.add(id);
    ref.read(storiesControllerProvider.notifier).markViewedLocally(id);
    try {
      await ref.read(storyRepositoryProvider).markViewed(id);
    } catch (_) {
      // View tracking is best-effort; local state already updated.
    }
  }

  void _startTimer() {
    _scheduleTicks(reset: true);
  }

  void _scheduleTicks({required bool reset}) {
    _timer?.cancel();
    if (reset) {
      _progress = 0;
    }
    final totalMs = _duration.inMilliseconds;
    const tick = 50;
    _timer = Timer.periodic(const Duration(milliseconds: tick), (t) {
      if (!mounted || _paused || _busy) return;
      setState(() => _progress += tick / totalMs);
      if (_progress >= 1) {
        t.cancel();
        _next();
      }
    });
  }

  void _pauseForOverlay() {
    _paused = true;
    _timer?.cancel();
    _timer = null;
    _video?.pause();
  }

  void _resumeFromOverlay() {
    if (!mounted || _busy) return;
    _paused = false;
    _video?.play();
    _scheduleTicks(reset: false);
  }

  Future<void> _goTo({required int bucketIndex, required int storyIndex}) async {
    setState(() {
      _bucketIndex = bucketIndex;
      _storyIndex = storyIndex;
      _paused = false;
    });
    await _prepareMedia();
    _markViewed();
    _startTimer();
  }

  void _next() {
    if (_busy) return;
    if (_storyIndex < _bucket.stories.length - 1) {
      _goTo(bucketIndex: _bucketIndex, storyIndex: _storyIndex + 1);
      return;
    }
    if (_bucketIndex < _buckets.length - 1) {
      _goTo(bucketIndex: _bucketIndex + 1, storyIndex: 0);
      return;
    }
    if (mounted) _closeViewer();
  }

  void _prev() {
    if (_busy) return;
    if (_storyIndex > 0) {
      _goTo(bucketIndex: _bucketIndex, storyIndex: _storyIndex - 1);
      return;
    }
    if (_bucketIndex > 0) {
      final prevBucket = _buckets[_bucketIndex - 1];
      _goTo(bucketIndex: _bucketIndex - 1, storyIndex: prevBucket.stories.length - 1);
    } else {
      _startTimer();
    }
  }

  Color _parseBg(String? raw) {
    if (raw == null || raw.isEmpty) return const Color(0xFF067E6B);
    var s = raw.trim();
    if (s.startsWith('#')) s = s.substring(1);
    if (s.length == 6) {
      final v = int.tryParse(s, radix: 16);
      if (v != null) return Color(0xFF000000 | v);
    }
    return const Color(0xFF067E6B);
  }

  Future<void> _openViewers() async {
    if (_busy || !_story.isMine) return;
    _pauseForOverlay();
    await showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (context) => StoryViewersSheet(storyId: _story.id),
    );
    _resumeFromOverlay();
  }

  Future<void> _confirmDelete() async {
    if (_busy || !_story.isMine) return;
    _pauseForOverlay();
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('حذف التحديث'),
        content: const Text(
          'هل تريد حذف هذا التحديث؟ لا يمكن التراجع عن هذا الإجراء.',
        ),
        actions: [
          TextButton(
            key: const Key('story-delete-cancel'),
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            key: const Key('story-delete-confirm'),
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('حذف'),
          ),
        ],
      ),
    );
    if (ok != true) {
      _resumeFromOverlay();
      return;
    }
    if (!mounted || _busy) {
      return;
    }
    setState(() => _busy = true);
    await _deleteCurrent();
  }

  Future<void> _deleteCurrent() async {
    if (!_story.isMine) return;
    final id = _story.id;
    final error = await ref.read(storiesControllerProvider.notifier).deleteStory(id);
    if (!mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    messenger.clearSnackBars();
    if (error != null) {
      setState(() => _busy = false);
      messenger.showSnackBar(SnackBar(content: Text(error)));
      _resumeFromOverlay();
      return;
    }
    messenger.showSnackBar(
      const SnackBar(content: Text('تم حذف التحديث')),
    );
    _removeDeletedAndAdvance(id);
  }

  Future<void> _removeDeletedAndAdvance(int id) async {
    final currentKey = _bucket.authorKey;
    final nextBuckets = [
      for (final bucket in _buckets)
        bucket.copyWith(
          stories: bucket.stories.where((story) => story.id != id).toList(),
        ),
    ].where((bucket) => bucket.stories.isNotEmpty).toList();
    if (nextBuckets.isEmpty) {
      _closeViewer();
      return;
    }
    final sameAuthor = nextBuckets.indexWhere((bucket) => bucket.authorKey == currentKey);
    final bucketIndex = sameAuthor >= 0
        ? sameAuthor
        : (_bucketIndex < nextBuckets.length ? _bucketIndex : nextBuckets.length - 1);
    var storyIndex = sameAuthor >= 0 ? _storyIndex : 0;
    if (storyIndex >= nextBuckets[bucketIndex].stories.length) {
      storyIndex = nextBuckets[bucketIndex].stories.length - 1;
    }
    setState(() {
      _buckets = nextBuckets;
      _busy = false;
    });
    await _goTo(bucketIndex: bucketIndex, storyIndex: storyIndex);
  }

  void _closeViewer() {
    if (!mounted) {
      return;
    }
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final story = _story;
    final bg = story.isText ? _parseBg(story.backgroundColor) : Colors.black;
    final video = _video;

    return Scaffold(
      backgroundColor: Colors.black,
      body: GestureDetector(
        onLongPressStart: (_) {
          if (_busy) return;
          setState(() => _paused = true);
          video?.pause();
        },
        onLongPressEnd: (_) {
          if (_busy) return;
          setState(() => _paused = false);
          video?.play();
        },
        onTapUp: (d) {
          if (_busy || _paused) return;
          final w = MediaQuery.sizeOf(context).width;
          if (d.localPosition.dx < w * 0.35) {
            _prev();
          } else {
            _next();
          }
        },
        child: Stack(
          fit: StackFit.expand,
          children: [
            ColoredBox(color: bg),
            if (story.isImage && story.mediaUrl != null)
              CachedNetworkImage(imageUrl: story.mediaUrl!, fit: BoxFit.contain)
            else if (story.isVideo && story.mediaUrl != null)
              video != null && video.value.isInitialized
                  ? FittedBox(
                      fit: BoxFit.contain,
                      child: SizedBox(
                        width: video.value.size.width,
                        height: video.value.size.height,
                        child: VideoPlayer(video),
                      ),
                    )
                  : Center(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          if (story.thumbnailUrl != null)
                            CachedNetworkImage(imageUrl: story.thumbnailUrl!, height: 220, fit: BoxFit.cover)
                          else
                            const CircularProgressIndicator(color: Colors.white70),
                          const SizedBox(height: 12),
                          Text(story.caption ?? 'جاري تحميل الفيديو…', style: const TextStyle(color: Colors.white70)),
                        ],
                      ),
                    )
            else if (story.isText)
              Center(
                child: Padding(
                  padding: const EdgeInsets.all(28),
                  child: Text(
                    story.bodyText ?? story.caption ?? '',
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.w800, height: 1.35),
                  ),
                ),
              ),
            if (story.caption != null && story.caption!.isNotEmpty && !story.isText)
              Positioned(
                left: 16,
                right: 16,
                bottom: 48,
                child: Text(
                  story.caption!,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w600),
                ),
              ),
            SafeArea(
              child: GestureDetector(
                onTap: () {},
                child: Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(10, 8, 10, 0),
                      child: Row(
                        children: [
                          for (var i = 0; i < _bucket.stories.length; i++) ...[
                            if (i > 0) const SizedBox(width: 4),
                            Expanded(
                              child: ClipRRect(
                                borderRadius: BorderRadius.circular(4),
                                child: LinearProgressIndicator(
                                  value: i < _storyIndex
                                      ? 1
                                      : i == _storyIndex
                                          ? _progress.clamp(0, 1)
                                          : 0,
                                  minHeight: 3,
                                  backgroundColor: Colors.white24,
                                  color: Colors.white,
                                ),
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(12, 10, 4, 0),
                      child: Row(
                        children: [
                          CircleAvatar(
                            radius: 16,
                            backgroundColor: Colors.white24,
                            child: Text(
                              _bucket.authorName.isNotEmpty ? _bucket.authorName[0] : '?',
                              style: const TextStyle(color: Colors.white, fontSize: 13),
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              _bucket.authorName,
                              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
                            ),
                          ),
                          if (story.isMine)
                            TextButton(
                              key: const Key('story-viewers'),
                              onPressed: _busy ? null : _openViewers,
                              child: Text(
                                '${story.viewsCount} مشاهدة',
                                style: const TextStyle(color: Colors.white70, fontSize: 12),
                              ),
                            ),
                          if (story.isMine)
                            IconButton(
                              key: const Key('story-delete'),
                              tooltip: 'حذف التحديث',
                              onPressed: _busy ? null : _confirmDelete,
                              icon: const Icon(Icons.delete_outline, color: Colors.white),
                            ),
                          IconButton(
                            onPressed: _busy ? null : _closeViewer,
                            icon: const Icon(Icons.close, color: Colors.white),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
            if (_busy)
              const ColoredBox(
                color: Color(0x88000000),
                child: Center(
                  child: CircularProgressIndicator(
                    key: Key('story-delete-loading'),
                    color: Colors.white,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
