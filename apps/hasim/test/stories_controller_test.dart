import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/stories/data/story_repository.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _MemorySecureStore extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}

  @override
  Future<String?> readToken() async => 'token';

  @override
  Future<void> clearToken() async {}
}

StoryModel _mine({int id = 11}) => StoryModel(
  id: id,
  type: 'text',
  bodyText: 'قصتي $id',
  isMine: true,
  viewsCount: 3,
  author: const StoryAuthorModel(id: 1, name: 'أنا'),
);

StoryModel _other({int id = 22}) => StoryModel(
  id: id,
  type: 'text',
  bodyText: 'قصة زميل',
  isMine: false,
  author: const StoryAuthorModel(id: 8, name: 'سارة'),
);

class _FakeStoryRepository extends StoryRepository {
  _FakeStoryRepository(super.api, {required this.items});

  List<StoryModel> items;
  int listCalls = 0;
  int deleteCalls = 0;
  Completer<void>? deleteGate;
  Object? deleteError;

  @override
  Future<List<StoryModel>> list() async {
    listCalls++;
    return List<StoryModel>.of(items);
  }

  @override
  Future<void> delete(int id) async {
    deleteCalls++;
    final gate = deleteGate;
    if (gate != null) {
      await gate.future;
    }
    if (deleteError != null) {
      throw deleteError!;
    }
    items = items.where((story) => story.id != id).toList();
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late SharedPreferences prefs;
  late ApiClient api;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    api = isolatedTestApiClient(
      secureStore: _MemorySecureStore(),
      prefsStore: PrefsStore(prefs),
    );
  });

  Future<void> waitLoaded(ProviderContainer container) async {
    for (var i = 0; i < 50; i++) {
      final state = container.read(storiesControllerProvider);
      if (state.loadedOnce && !state.loading) {
        return;
      }
      await Future<void>.delayed(const Duration(milliseconds: 10));
    }
    fail('stories did not load');
  }

  List<int> idsOf(ProviderContainer container) {
    return container
        .read(storiesControllerProvider)
        .buckets
        .expand((bucket) => bucket.stories)
        .map((story) => story.id)
        .toList();
  }

  test('successful delete removes only that story without reloading', () async {
    final repo = _FakeStoryRepository(api, items: [_mine(), _mine(id: 12), _other()]);
    final container = ProviderContainer(
      overrides: [storyRepositoryProvider.overrideWithValue(repo)],
    );
    addTearDown(container.dispose);
    container.read(storiesControllerProvider);
    await waitLoaded(container);

    final error = await container
        .read(storiesControllerProvider.notifier)
        .deleteStory(11);

    expect(error, isNull);
    expect(repo.deleteCalls, 1);
    expect(repo.listCalls, 1);
    expect(idsOf(container), [12, 22]);
    expect(
      container.read(storiesControllerProvider).buckets.any((b) => b.isMine),
      isTrue,
    );
  });

  test('failed delete keeps the current stories', () async {
    final repo = _FakeStoryRepository(api, items: [_mine(), _other()])
      ..deleteError = ApiException('يمكنك حذف قصصك فقط.', statusCode: 403);
    final container = ProviderContainer(
      overrides: [storyRepositoryProvider.overrideWithValue(repo)],
    );
    addTearDown(container.dispose);
    container.read(storiesControllerProvider);
    await waitLoaded(container);

    final error = await container
        .read(storiesControllerProvider.notifier)
        .deleteStory(11);

    expect(error, 'يمكنك حذف قصصك فقط.');
    expect(idsOf(container), [11, 22]);
    expect(repo.listCalls, 1);
  });

  test('duplicate delete shares one in-flight request', () async {
    final repo = _FakeStoryRepository(api, items: [_mine(), _other()])
      ..deleteGate = Completer<void>();
    final container = ProviderContainer(
      overrides: [storyRepositoryProvider.overrideWithValue(repo)],
    );
    addTearDown(container.dispose);
    container.read(storiesControllerProvider);
    await waitLoaded(container);

    final first = container.read(storiesControllerProvider.notifier).deleteStory(11);
    final second = container.read(storiesControllerProvider.notifier).deleteStory(11);
    await Future<void>.delayed(const Duration(milliseconds: 10));
    expect(repo.deleteCalls, 1);
    repo.deleteGate!.complete();
    expect(await first, isNull);
    expect(await second, isNull);
    expect(repo.deleteCalls, 1);
    expect(idsOf(container), [22]);
  });
}
