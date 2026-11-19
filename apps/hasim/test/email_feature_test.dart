import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/features/email/data/email_models.dart';
import 'package:hasim/features/email/data/email_repository.dart';
import 'package:hasim/features/email/providers/email_controller.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _FakeEmailRepository extends EmailRepository {
  _FakeEmailRepository(super.api);

  final List<EmailMessageModel> inboxMessages = [
    const EmailMessageModel(
      id: 1,
      sender: 'client@example.com',
      recipient: 'support@hasim.app',
      subject: 'استفسار بخصوص الطلب #100',
      body: 'مرحبا أريد معرفة موعد وصول الشحنة',
      isRead: false,
      isStarred: false,
      folder: 'inbox',
      createdAt: '2026-03-01T10:00:00Z',
    ),
  ];

  final List<EmailMessageModel> sentMessages = [
    const EmailMessageModel(
      id: 2,
      sender: 'support@hasim.app',
      recipient: 'client@example.com',
      subject: 'تم شحن طلبك بنجاح',
      body: 'طلبك رقم 100 في الطريق إليك مع أرامكس',
      isRead: true,
      isStarred: true,
      folder: 'sent',
      createdAt: '2026-03-01T12:00:00Z',
    ),
  ];

  @override
  Future<List<EmailAccountModel>> accounts() async {
    return const [
      EmailAccountModel(
        id: 1,
        name: 'دعم العملاء',
        email: 'support@hasim.app',
        brandColor: '#00BFAE',
      ),
    ];
  }

  @override
  Future<List<EmailMessageModel>> listFolder(String folder, {int page = 1}) async {
    if (folder == 'sent') return List.of(sentMessages);
    return List.of(inboxMessages);
  }

  @override
  Future<EmailMessageModel> send({
    required int emailAccountId,
    required String to,
    required String subject,
    required String body,
    int? replyToMessageId,
  }) async {
    final msg = EmailMessageModel(
      id: 99,
      emailAccountId: emailAccountId,
      sender: 'support@hasim.app',
      recipient: to,
      subject: subject,
      body: body,
      type: 'outbound',
      isRead: true,
      createdAt: '2026-03-02T10:00:00Z',
    );
    sentMessages.add(msg);
    return msg;
  }

  @override
  Future<void> star(int id) async {}

  @override
  Future<void> markRead(int id) async {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('Email Models & Serialization', () {
    test('EmailMessageModel serialization parses nested attachments and account', () {
      final json = {
        'id': 10,
        'email_account_id': 1,
        'sender': 'customer@test.com',
        'recipient': 'hasim@test.com',
        'subject': 'عرض سعر',
        'body': 'نرجو تزويدنا بالتفاصيل',
        'is_read': false,
        'is_starred': true,
        'folder': 'inbox',
        'created_at': '2026-03-01T10:00:00Z',
        'attachments': [
          {'id': 101, 'file_type': 'application/pdf', 'file_size': 2048},
        ],
        'account': {
          'id': 1,
          'name': 'المبيعات',
          'email': 'sales@hasim.app',
        },
      };

      final msg = EmailMessageModel.fromJson(json);
      expect(msg.id, 10);
      expect(msg.sender, 'customer@test.com');
      expect(msg.subject, 'عرض سعر');
      expect(msg.displaySubject, 'عرض سعر');
      expect(msg.isRead, isFalse);
      expect(msg.isStarred, isTrue);
      expect(msg.attachments.length, 1);
      expect(msg.attachments.first.fileType, 'application/pdf');
      expect(msg.account, isNotNull);
      expect(msg.account!.name, 'المبيعات');
    });
  });

  group('EmailFolderController State & Actions', () {
    late _FakeEmailRepository repo;
    late EmailFolderController controller;

    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      final prefs = PrefsStore(await SharedPreferences.getInstance());
      final api = isolatedTestApiClient(
        secureStore: SecureStore(),
        prefsStore: prefs,
      );
      repo = _FakeEmailRepository(api);
      controller = EmailFolderController(repo);
    });

    test('init() loads accounts and inbox folder', () async {
      await controller.init();
      expect(controller.state.loading, isFalse);
      expect(controller.state.currentFolder, 'inbox');
      expect(controller.state.accounts.length, 1);
      expect(controller.state.messages.length, 1);
      expect(controller.state.messages.first.subject, 'استفسار بخصوص الطلب #100');
    });

    test('switchFolder() updates messages to sent folder', () async {
      await controller.init();
      await controller.switchFolder('sent');
      expect(controller.state.currentFolder, 'sent');
      expect(controller.state.messages.length, 1);
      expect(controller.state.messages.first.subject, 'تم شحن طلبك بنجاح');
    });

    test('toggleStar() updates starred state in-place', () async {
      await controller.init();
      expect(controller.state.messages.first.isStarred, isFalse);
      await controller.toggleStar(1);
      expect(controller.state.messages.first.isStarred, isTrue);
    });

    test('markRead() sets message as read', () async {
      await controller.init();
      expect(controller.state.messages.first.isRead, isFalse);
      await controller.markRead(1);
      expect(controller.state.messages.first.isRead, isTrue);
    });
  });
}
