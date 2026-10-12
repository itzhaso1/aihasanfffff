import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/local_db/feed_local_store.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';

void main() {
  test('stories hide expired rows and stay inside the workspace', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = FeedLocalStore(db);
    final now = DateTime.utc(2026, 10, 5, 12);
    await store.upsertStories(1, [
      StoryModel(
        id: 1,
        type: 'text',
        bodyText: 'حية',
        createdAt: now.subtract(const Duration(hours: 1)),
        expiresAt: now.add(const Duration(hours: 12)),
        author: const StoryAuthorModel(id: 4, name: 'نورة'),
      ),
      StoryModel(
        id: 2,
        type: 'text',
        bodyText: 'منتهية',
        createdAt: now.subtract(const Duration(hours: 30)),
        expiresAt: now.subtract(const Duration(hours: 1)),
      ),
    ]);
    await store.upsertStories(2, [
      StoryModel(id: 3, type: 'text', bodyText: 'أخرى', expiresAt: now.add(const Duration(hours: 2))),
    ]);

    final visible = await store.stories(1, now: now);
    expect(visible.map((story) => story.id), [1]);
    expect(visible.single.author?.name, 'نورة');
    expect(await store.stories(2, now: now), hasLength(1));

    await store.markStoryViewed(1, 1);
    expect((await store.stories(1, now: now)).single.viewedByMe, isTrue);
    await store.markStoryDeleted(1, 1);
    expect(await store.stories(1, now: now), isEmpty);
  });

  test('story viewers and notification read state stay workspace scoped', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = FeedLocalStore(db);
    await store.upsertViewers(1, 9, const [
      StoryViewerEntry(userId: 4, name: 'نورة', viewedAt: null),
    ]);
    await store.upsertNotifications(1, [
      AppNotificationModel(
        id: 'n1',
        type: 'message',
        data: {'title': 'رسالة', 'body': 'مرحبا', 'conversation_id': 7, 'token': 'hide'},
        createdAt: DateTime.utc(2026, 10, 5),
      ),
    ]);
    await store.upsertNotifications(2, [
      const AppNotificationModel(id: 'n2', type: 'message', data: {'title': 'أخرى'}),
    ]);

    expect((await store.viewers(1, 9)).single.name, 'نورة');
    expect(await store.viewers(2, 9), isEmpty);
    final mine = await store.notifications(1);
    expect(mine.single.title, 'رسالة');
    expect(mine.single.data.containsKey('token'), isFalse);
    expect(await store.notifications(2), hasLength(1));

    await store.markNotificationRead(1, 'n1', read: true);
    expect((await store.notifications(1)).single.isRead, isTrue);
    await store.markAllNotificationsRead(1);
    expect((await store.notifications(1)).every((item) => item.isRead), isTrue);
    expect((await store.notifications(2)).single.isRead, isFalse);
  });

  test('notification preferences and logout keep phase data boundaries', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final feed = FeedLocalStore(db);
    final inbox = InboxLocalStore(db);
    await inbox.upsertConversations(1, [
      const ConversationModel(id: 8, channel: 'whatsapp', status: 'open'),
    ]);
    await feed.saveNotificationPreferences(1, {'messages': true, 'email': false, 'marketing': true});
    expect((await feed.notificationPreferences(1))?['marketing'], isTrue);
    expect(await feed.notificationPreferences(2), isNull);
    await inbox.clearAll();
    expect(await feed.notificationPreferences(1), isNull);
    expect(await inbox.getConversations(1), isEmpty);
  });

  test('story and notification queries stay bounded', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = FeedLocalStore(db);
    final now = DateTime.utc(2026, 10, 5);
    await store.upsertStories(1, [
      for (var i = 0; i < 500; i++)
        StoryModel(
          id: i + 1,
          type: 'text',
          bodyText: 'قصة $i',
          createdAt: now.add(Duration(minutes: i)),
          expiresAt: now.add(const Duration(days: 1)),
        ),
    ]);
    await store.upsertNotifications(1, [
      for (var i = 0; i < 2000; i++)
        AppNotificationModel(
          id: 'n$i',
          type: 'message',
          data: {'title': 'تنبيه $i', 'body': 'نص'},
          createdAt: now.add(Duration(seconds: i)),
        ),
    ]);
    final started = DateTime.now();
    final stories = await store.stories(1, now: now);
    final notes = await store.notifications(1);
    expect(stories, hasLength(FeedLocalStore.storyLimit));
    expect(stories.first.id, 500);
    expect(notes, hasLength(FeedLocalStore.notificationLimit));
    expect(notes.first.title, 'تنبيه 1999');
    expect(DateTime.now().difference(started).inMilliseconds, lessThan(1500));
  });
}
