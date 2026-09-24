import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/local_db/outbox_worker.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/auth/data/auth_repository.dart';
import 'package:hasim/features/catalog/data/chat_catalog_repository.dart';
import 'package:hasim/features/contacts/data/contact_group_repository.dart';
import 'package:hasim/features/contacts/data/contact_repository.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/email/data/campaign_repository.dart';
import 'package:hasim/features/email/data/email_repository.dart';
import 'package:hasim/features/home/data/home_repository.dart';
import 'package:hasim/features/notifications/data/notification_repository.dart';
import 'package:hasim/features/settings/data/plan_channel_repository.dart';
import 'package:hasim/features/settings/data/session_repository.dart';
import 'package:hasim/features/search/data/search_repository.dart';
import 'package:hasim/features/stories/data/story_repository.dart';
import 'package:hasim/features/workspace/data/workspace_repository.dart';
import 'package:hasim/push/push_service.dart';
import 'package:hasim/realtime/hybrid_realtime_service.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

final sharedPrefsProvider = Provider<SharedPreferences>((ref) {
  throw UnimplementedError('SharedPreferences must be overridden in main');
});

final secureStoreProvider = Provider<SecureStore>((ref) => SecureStore());

final prefsStoreProvider = Provider<PrefsStore>((ref) {
  return PrefsStore(ref.watch(sharedPrefsProvider));
});

final inboxDatabaseProvider = Provider<InboxDatabase>((ref) {
  final db = InboxDatabase.memory();
  ref.onDispose(db.close);
  return db;
});

final inboxLocalStoreProvider = Provider<InboxLocalStore>((ref) {
  return InboxLocalStore(ref.watch(inboxDatabaseProvider));
});

final inboxSessionProvider = Provider<InboxSession>((ref) => InboxSession());

/// Bumped after local inbox mutations (mark-read, reconcile) so Home and
/// Conversations reread the same Drift rows.
final inboxLocalRevisionProvider = StateProvider<int>((ref) => 0);

void bumpInboxLocalRevision(Ref ref) {
  ref.read(inboxLocalRevisionProvider.notifier).state++;
}

final apiClientProvider = Provider<ApiClient>((ref) {
  return ApiClient(
    secureStore: ref.watch(secureStoreProvider),
    prefsStore: ref.watch(prefsStoreProvider),
  );
});

final authRepositoryProvider = Provider((ref) => AuthRepository(ref.watch(apiClientProvider)));
final workspaceRepositoryProvider = Provider((ref) => WorkspaceRepository(ref.watch(apiClientProvider)));
final homeRepositoryProvider = Provider((ref) => HomeRepository(ref.watch(apiClientProvider)));
final searchRepositoryProvider = Provider((ref) => SearchRepository(ref.watch(apiClientProvider)));
final conversationRepositoryProvider = Provider(
  (ref) => ConversationRepository(
    ref.watch(apiClientProvider),
    local: ref.watch(inboxLocalStoreProvider),
  ),
);
final emailRepositoryProvider = Provider((ref) => EmailRepository(ref.watch(apiClientProvider)));
final campaignRepositoryProvider = Provider((ref) => CampaignRepository(ref.watch(apiClientProvider)));
final contactRepositoryProvider = Provider((ref) => ContactRepository(ref.watch(apiClientProvider)));
final chatCatalogRepositoryProvider = Provider((ref) => ChatCatalogRepository(ref.watch(apiClientProvider)));
final contactGroupRepositoryProvider = Provider((ref) => ContactGroupRepository(ref.watch(apiClientProvider)));
final storyRepositoryProvider = Provider((ref) => StoryRepository(ref.watch(apiClientProvider)));
final notificationRepositoryProvider = Provider((ref) => NotificationRepository(ref.watch(apiClientProvider)));
final sessionRepositoryProvider = Provider((ref) => SessionRepository(ref.watch(apiClientProvider)));
final deviceRepositoryProvider = Provider((ref) => DeviceRepository(ref.watch(apiClientProvider)));
final planRepositoryProvider = Provider((ref) => PlanRepository(ref.watch(apiClientProvider)));
final channelRepositoryProvider = Provider((ref) => ChannelRepository(ref.watch(apiClientProvider)));
final brandingRepositoryProvider = Provider((ref) => BrandingRepository(ref.watch(apiClientProvider)));
final customerRepositoryProvider = Provider((ref) => CustomerRepository(ref.watch(apiClientProvider)));
final aiRepositoryProvider = Provider((ref) => AiRepository(ref.watch(apiClientProvider)));

final realtimeServiceProvider = Provider<RealtimeService>((ref) {
  final polling = PollingRealtimeService(
    ref.watch(conversationRepositoryProvider),
    workspaceIdOf: () => ref.read(prefsStoreProvider).workspaceId,
  );

  return HybridRealtimeService(
    polling,
    apiClient: ref.watch(apiClientProvider),
  );
});

final outboxWorkerProvider = Provider<OutboxWorker>((ref) {
  final worker = OutboxWorker(
    store: ref.watch(inboxLocalStoreProvider),
    repository: ref.watch(conversationRepositoryProvider),
    session: ref.watch(inboxSessionProvider),
  );
  ref.onDispose(worker.dispose);
  return worker;
});

final pushServiceProvider = Provider<PushService>((ref) {
  return NoopPushService(ref.watch(deviceRepositoryProvider));
});
