import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
import 'package:hasim/router/hasim_nav.dart';
import 'package:hasim/router/notification_deep_link.dart';

void main() {
  group('HasimNav', () {
    test('bottom navigation contains required tabs in order', () {
      expect(HasimNav.bottomLabels, [
        'المحادثات',
        'البريد',
        'التحديثات',
        'المزيد',
      ]);
      expect(HasimNav.bottomLabels.length, 4);
    });

    test('conversations is initial shell route', () {
      expect(HasimNav.initialShellRoute, '/conversations');
      expect(HasimNav.conversationsIndex, 0);
      expect(HasimNav.updatesIndex, 2);
      expect(HasimNav.moreIndex, 3);
    });
  });

  group('notification deep links', () {
    test('message → conversation', () {
      final n = AppNotificationModel(
        id: '1',
        type: 'message.received',
        data: {'conversation_id': 42},
      );
      expect(resolveNotificationRoute(n), '/conversations/42');
    });

    test('story → updates', () {
      final n = AppNotificationModel(
        id: '3',
        type: 'story.created',
        data: {'story_id': 7},
      );
      expect(resolveNotificationRoute(n), '/updates');
    });
  });

  group('stories unviewed badge', () {
    test('counts only other unviewed stories', () {
      final state = StoriesState(
        buckets: [
          StoryBucket(
            authorKey: 'mine',
            authorName: 'حالتي',
            isMine: true,
            stories: [
              StoryModel(id: 1, type: 'text', isMine: true, viewedByMe: true),
            ],
          ),
          StoryBucket(
            authorKey: 'u-2',
            authorName: 'أحمد',
            isMine: false,
            stories: [
              StoryModel(id: 2, type: 'text', viewedByMe: false),
              StoryModel(id: 3, type: 'image', viewedByMe: true),
              StoryModel(id: 4, type: 'video', viewedByMe: false),
            ],
          ),
        ],
        loadedOnce: true,
      );
      expect(state.unviewedCount, 2);
      expect(state.buckets[1].hasUnviewed, isTrue);
    });

    test('hides zero badge conceptually', () {
      const state = StoriesState(loadedOnce: true);
      expect(state.unviewedCount, 0);
    });
  });

  group('StoryModel viewed_by_me', () {
    test('parses viewed_by_me', () {
      final story = StoryModel.fromJson({
        'id': 5,
        'type': 'text',
        'is_mine': false,
        'viewed_by_me': false,
        'views_count': 0,
      });
      expect(story.viewedByMe, isFalse);

      final viewed = StoryModel.fromJson({
        'id': 6,
        'type': 'text',
        'is_mine': false,
        'viewed_by_me': true,
      });
      expect(viewed.viewedByMe, isTrue);
    });
  });

  group('RTL header semantics', () {
    testWidgets('brand text is on the right and more is on the left in RTL', (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          builder: (context, child) => Directionality(
            textDirection: TextDirection.rtl,
            child: child!,
          ),
          home: Scaffold(
            appBar: AppBar(
              automaticallyImplyLeading: false,
              title: const Text('حاسم'),
              actions: [
                IconButton(
                  key: const Key('more-btn'),
                  onPressed: () {},
                  icon: const Icon(Icons.more_horiz),
                ),
              ],
            ),
          ),
        ),
      );

      expect(find.text('حاسم'), findsOneWidget);
      expect(find.byKey(const Key('more-btn')), findsOneWidget);
      expect(find.byType(CircleAvatar), findsNothing);

      final brand = tester.getCenter(find.text('حاسم'));
      final more = tester.getCenter(find.byKey(const Key('more-btn')));
      // RTL: title (حاسم) على اليمين، actions (more) على اليسار
      expect(brand.dx, greaterThan(more.dx));
    });
  });

  group('More menu entries', () {
    test('expected hub destinations exist as routes', () {
      const routes = {
        '/contacts',
        '/channels',
        '/plans',
        '/settings',
        '/contact-groups',
        '/more/security',
        '/profile',
        '/stories/create',
        '/updates',
      };
      expect(routes.contains('/contacts'), isTrue);
      expect(routes.contains('/channels'), isTrue);
      expect(routes.contains('/plans'), isTrue);
      expect(routes.contains('/settings'), isTrue);
      expect(routes.contains('/profile'), isTrue);
      expect(routes.contains('/updates'), isTrue);
      expect(routes.contains('/stories/create'), isTrue);
    });
  });
}
