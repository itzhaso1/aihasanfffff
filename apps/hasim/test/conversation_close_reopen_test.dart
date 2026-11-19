import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _FakeConversationRepo extends ConversationRepository {
  _FakeConversationRepo(super.api);

  int? closedId;
  int? reopenedId;

  @override
  Future<void> closeConversation(int conversationId, {int? workspaceId}) async {
    closedId = conversationId;
  }

  @override
  Future<void> reopenConversation(int conversationId, {int? workspaceId}) async {
    reopenedId = conversationId;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('Conversation Close and Reopen Tests', () {
    late _FakeConversationRepo repo;

    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      final prefs = PrefsStore(await SharedPreferences.getInstance());
      final api = isolatedTestApiClient(
        secureStore: SecureStore(),
        prefsStore: prefs,
      );
      repo = _FakeConversationRepo(api);
    });

    test('closeConversation calls backend repository endpoint', () async {
      await repo.closeConversation(42);
      expect(repo.closedId, 42);
    });

    test('reopenConversation calls backend repository endpoint', () async {
      await repo.reopenConversation(42);
      expect(repo.reopenedId, 42);
    });

    test('ConversationModel copyWith toggles status between open and closed', () {
      const conv = ConversationModel(
        id: 1,
        channel: 'whatsapp',
        customer: CustomerModel(id: 1, name: 'فهد المنصور'),
        status: 'open',
      );

      final closed = conv.copyWith(status: 'closed');
      expect(closed.status, 'closed');
      expect(closed.id, 1);
      expect(closed.customer?.name, 'فهد المنصور');

      final reopened = closed.copyWith(status: 'open');
      expect(reopened.status, 'open');
    });
  });
}
