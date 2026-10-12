import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

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
  Future<String?>? _inFlightDelete;
  int? _inFlightDeleteId;
  int _loadEpoch = 0;

  void onIsolationChanged() {
    state = const StoriesState();
    unawaited(refresh());
  }

  Future<void> refresh({bool force = true}) async {
    if (!force && state.loadedOnce) return;

    final epoch = ++_loadEpoch;
    final repo = _ref.read(storyRepositoryProvider);
    final cached = await repo.cached();
    if (epoch != _loadEpoch) return;
    if (cached.isNotEmpty) {
      state = state.copyWith(
        buckets: _group(cached),
        loading: false,
        clearError: true,
        loadedOnce: true,
      );
    } else {
      state = state.copyWith(loading: true, clearError: true);
    }
    try {
      final stories = await repo.list();
      if (epoch != _loadEpoch) {
        return;
      }
      state = state.copyWith(
        buckets: _group(stories),
        loading: false,
        loadedOnce: true,
        clearError: !repo.lastReadFromCache,
        error: repo.lastReadFromCache
            ? 'تعذر التحديث. تُعرض البيانات المحفوظة على الجهاز.'
            : null,
      );
    } on ApiException catch (e) {
      if (epoch != _loadEpoch) {
        return;
      }
      if (e.statusCode == 403) {
        await repo.clearLocalWorkspaceData();
        if (epoch != _loadEpoch) {
          return;
        }
        state = state.copyWith(
          buckets: const [],
          loading: false,
          error: e.userMessage,
          loadedOnce: true,
        );
        return;
      }
      state = state.copyWith(
        loading: false,
        clearError: state.buckets.isNotEmpty,
        error: state.buckets.isEmpty ? e.userMessage : null,
        loadedOnce: true,
      );
    } catch (_) {
      if (epoch != _loadEpoch) {
        return;
      }
      state = state.copyWith(
        loading: false,
        clearError: state.buckets.isNotEmpty,
        error: state.buckets.isEmpty ? 'تعذر تحميل التحديثات.' : null,
        loadedOnce: true,
      );
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

  /// Laravel allows delete only for the story author (`is_mine`).
  Future<String?> deleteStory(int storyId) {
    if (_inFlightDelete != null && _inFlightDeleteId == storyId) {
      return _inFlightDelete!;
    }
    if (_inFlightDelete != null) {
      return Future.value('جاري الحذف.');
    }
    _inFlightDeleteId = storyId;
    final pending = _deleteStory(storyId);
    _inFlightDelete = pending;
    return pending;
  }

  Future<String?> _deleteStory(int storyId) async {
    try {
      await _ref.read(storyRepositoryProvider).delete(storyId);
      _removeStoryLocally(storyId);
      return null;
    } on ApiException catch (e) {
      return e.userMessage;
    } catch (_) {
      return 'تعذر حذف التحديث.';
    } finally {
      _inFlightDelete = null;
      _inFlightDeleteId = null;
    }
  }

  void _removeStoryLocally(int storyId) {
    _loadEpoch++;
    final next = [
      for (final bucket in state.buckets)
        bucket.copyWith(
          stories: bucket.stories
              .where((story) => story.id != storyId)
              .toList(),
        ),
    ].where((bucket) => bucket.isMine || bucket.stories.isNotEmpty).toList();
    state = state.copyWith(buckets: next, loading: false, loadedOnce: true);
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
    StateNotifierProvider<StoriesController, StoriesState>((ref) {
      final controller = StoriesController(ref);
      ref.listen<String>(authControllerProvider.select((s) => s.isolationKey), (
        previous,
        next,
      ) {
        if (previous != null && previous != next) {
          controller.onIsolationChanged();
        }
      });
      return controller;
    });
