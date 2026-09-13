import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';

class StoryBucket {
  const StoryBucket({
    required this.authorKey,
    required this.authorName,
    required this.isMine,
    required this.stories,
    this.avatarPath,
  });

  final String authorKey;
  final String authorName;
  final bool isMine;
  final String? avatarPath;
  final List<StoryModel> stories;

  bool get hasUnviewed => !isMine && stories.any((s) => !s.viewedByMe);

  StoryBucket copyWith({List<StoryModel>? stories, String? avatarPath}) {
    return StoryBucket(
      authorKey: authorKey,
      authorName: authorName,
      isMine: isMine,
      avatarPath: avatarPath ?? this.avatarPath,
      stories: stories ?? this.stories,
    );
  }
}

class StoriesState {
  const StoriesState({
    this.buckets = const [],
    this.loading = false,
    this.error,
    this.loadedOnce = false,
  });

  final List<StoryBucket> buckets;
  final bool loading;
  final String? error;
  final bool loadedOnce;

  /// عدد التحديثات غير المشاهدة (قصص الآخرين فقط).
  int get unviewedCount => buckets
      .where((b) => !b.isMine)
      .expand((b) => b.stories)
      .where((s) => !s.viewedByMe)
      .length;

  StoriesState copyWith({
    List<StoryBucket>? buckets,
    bool? loading,
    String? error,
    bool clearError = false,
    bool? loadedOnce,
  }) {
    return StoriesState(
      buckets: buckets ?? this.buckets,
      loading: loading ?? this.loading,
      error: clearError ? null : (error ?? this.error),
      loadedOnce: loadedOnce ?? this.loadedOnce,
    );
  }
}

class StoriesController extends StateNotifier<StoriesState> {
  StoriesController(this._ref) : super(const StoriesState()) {
    refresh();
  }

  final Ref _ref;

  Future<void> refresh({bool force = true}) async {
    // لا تعِد التحميل عند تبديل التبويب إذا البيانات محمّلة مسبقاً.
    if (!force && state.loadedOnce) return;

    state = state.copyWith(loading: true, clearError: true);
    try {
      final stories = await _ref.read(storyRepositoryProvider).list();
      state = state.copyWith(
        buckets: _group(stories),
        loading: false,
        clearError: true,
        loadedOnce: true,
      );
    } on ApiException catch (e) {
      state = state.copyWith(loading: false, error: e.message, loadedOnce: true);
    } catch (_) {
      state = state.copyWith(loading: false, error: 'تعذر تحميل التحديثات.', loadedOnce: true);
    }
  }

  /// تحديث محلي بعد مشاهدة قصة (idempotent على مستوى الواجهة).
  void markViewedLocally(int storyId) {
    final next = state.buckets.map((b) {
      final stories = b.stories
          .map((s) => s.id == storyId ? s.copyWith(viewedByMe: true) : s)
          .toList();
      return b.copyWith(stories: stories);
    }).toList();
    state = state.copyWith(buckets: next);
  }

  List<StoryBucket> _group(List<StoryModel> stories) {
    final order = <String>[];
    final map = <String, List<StoryModel>>{};
    final meta = <String, ({String name, bool isMine, String? avatar})>{};

    for (final s in stories) {
      final key = s.isMine ? 'mine' : 'u-${s.author?.id ?? s.id}';
      if (!map.containsKey(key)) {
        order.add(key);
        meta[key] = (
          name: s.isMine ? 'حالتي' : (s.author?.name ?? 'عضو'),
          isMine: s.isMine,
          avatar: s.author?.avatarPath,
        );
      }
      map.putIfAbsent(key, () => []).add(s);
    }

    // Ensure "my status" create affordance even with zero stories.
    if (!map.containsKey('mine')) {
      order.insert(0, 'mine');
      meta['mine'] = (name: 'حالتي', isMine: true, avatar: null);
      map['mine'] = [];
    } else {
      order.remove('mine');
      order.insert(0, 'mine');
    }

    return [
      for (final key in order)
        StoryBucket(
          authorKey: key,
          authorName: meta[key]!.name,
          isMine: meta[key]!.isMine,
          avatarPath: meta[key]!.avatar,
          stories: map[key]!,
        ),
    ];
  }
}

final storiesControllerProvider =
    StateNotifierProvider<StoriesController, StoriesState>((ref) => StoriesController(ref));
