import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';

/// يوجّه الإشعار إلى الوجهة المناسبة حسب نوعه / بياناته.
String? resolveNotificationRoute(AppNotificationModel n) {
  final data = n.data;
  final type = (data['type'] ?? data['entity'] ?? n.type).toString().toLowerCase();

  final conversationId = _asInt(data['conversation_id'] ?? data['conversationId']);
  if (conversationId != null || type.contains('message') || type.contains('conversation')) {
    if (conversationId != null) return '/conversations/$conversationId';
    return '/conversations';
  }

  final storyId = _asInt(data['story_id'] ?? data['storyId']);
  if (storyId != null || type.contains('story') || type.contains('status')) {
    return '/updates';
  }

  final emailId = _asInt(data['email_id'] ?? data['emailId'] ?? data['message_id']);
  if (emailId != null || type.contains('email') || type.contains('mail')) {
    if (emailId != null) return '/email/$emailId';
    return '/email';
  }

  return null;
}

Future<void> openNotificationDeepLink(
  BuildContext context,
  AppNotificationModel n, {
  StoriesState? stories,
}) async {
  final route = resolveNotificationRoute(n);
  if (route == null || !context.mounted) return;

  if (route == '/updates') {
    final storyId = _asInt(n.data['story_id'] ?? n.data['storyId']);
    if (storyId != null && stories != null) {
      final viewable = stories.buckets.where((b) => b.stories.isNotEmpty).toList();
      for (var bi = 0; bi < viewable.length; bi++) {
        final si = viewable[bi].stories.indexWhere((s) => s.id == storyId);
        if (si >= 0) {
          context.push('/stories/view', extra: {
            'buckets': viewable,
            'bucketIndex': bi,
            'storyIndex': si,
          });
          return;
        }
      }
    }
    context.go('/updates');
    return;
  }

  if (route.startsWith('/conversations/') || route.startsWith('/email/')) {
    context.push(route);
    return;
  }

  context.go(route);
}

int? _asInt(dynamic v) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  return int.tryParse(v?.toString() ?? '');
}
