import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/models/models.dart';

void main() {
  group('StoryModel.fromJson', () {
    test('parses StoryResource fields', () {
      final story = StoryModel.fromJson({
        'id': 12,
        'type': 'text',
        'caption': 'مرحبا',
        'body_text': 'نص القصة',
        'background_color': '#067E6B',
        'media_url': null,
        'media_mime': null,
        'media_size': null,
        'thumbnail_url': null,
        'visibility': 'workspace',
        'selected_user_ids': [1, 2],
        'hidden_user_ids': [],
        'expires_at': '2026-09-02T12:00:00+00:00',
        'views_count': 3,
        'status': 'active',
        'created_at': '2026-09-01T12:00:00+00:00',
        'author': {'id': 9, 'name': 'أحمد', 'avatar_path': null},
        'is_mine': true,
      });

      expect(story.id, 12);
      expect(story.isText, isTrue);
      expect(story.bodyText, 'نص القصة');
      expect(story.backgroundColor, '#067E6B');
      expect(story.selectedUserIds, [1, 2]);
      expect(story.viewsCount, 3);
      expect(story.isMine, isTrue);
      expect(story.viewedByMe, isTrue);
      expect(story.author?.name, 'أحمد');
      expect(story.expiresAt, isNotNull);
    });

    test('parses image story', () {
      final story = StoryModel.fromJson({
        'id': 1,
        'type': 'image',
        'media_url': 'https://example.com/a.jpg',
        'views_count': 0,
        'is_mine': false,
        'viewed_by_me': false,
      });
      expect(story.isImage, isTrue);
      expect(story.mediaUrl, contains('a.jpg'));
      expect(story.viewedByMe, isFalse);
    });
  });

  group('EmailContactModel.fromJson', () {
    test('parses EmailContactResource fields', () {
      final contact = EmailContactModel.fromJson({
        'id': 5,
        'name': 'سارة',
        'email': 'sara@example.com',
        'normalized_email': 'sara@example.com',
        'phone': '0500000000',
        'company': 'حاسم',
        'job_title': 'مديرة',
        'notes': 'ملاحظة',
        'is_favorite': true,
        'avatar_url': null,
        'created_at': '2026-09-01T10:00:00Z',
        'updated_at': '2026-09-01T11:00:00Z',
        'groups': [
          {'id': 2, 'name': 'عملاء'},
        ],
      });

      expect(contact.id, 5);
      expect(contact.name, 'سارة');
      expect(contact.email, 'sara@example.com');
      expect(contact.isFavorite, isTrue);
      expect(contact.jobTitle, 'مديرة');
      expect(contact.groups.single.name, 'عملاء');
    });
  });

  group('EmailCampaignModel.fromJson', () {
    test('parses campaign progress fields', () {
      final campaign = EmailCampaignModel.fromJson({
        'id': 7,
        'email_account_id': 3,
        'subject': 'عرض',
        'body': 'مرحبا',
        'status': 'processing',
        'recipient_count': 10,
        'sent_count': 4,
        'failed_count': 1,
        'pending_count': 5,
        'error_message': null,
        'account': {'id': 3, 'name': 'Support', 'email': 's@x.com'},
      });
      expect(campaign.id, 7);
      expect(campaign.statusLabel, 'جارٍ الإرسال');
      expect(campaign.isTerminal, isFalse);
      expect(campaign.pendingCount, 5);
      expect(campaign.accountEmail, 's@x.com');
    });
  });
}
