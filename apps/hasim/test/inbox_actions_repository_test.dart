import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemorySecureStore extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}

  @override
  Future<String?> readToken() async => 'token';

  @override
  Future<void> clearToken() async {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  Future<({ConversationRepository repo, List<RequestOptions> calls})>
  repository({Map<String, dynamic>? data}) async {
    SharedPreferences.setMockInitialValues({'workspace_id': 4});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    final calls = <RequestOptions>[];
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1/api/mobile/v1/'));
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          calls.add(options);
          handler.resolve(
            Response(
              requestOptions: options,
              statusCode: 200,
              data: {
                'success': true,
                'data':
                    data ??
                    {
                      'id': 7,
                      'channel': 'whatsapp',
                      'status': 'open',
                      'customer_priority': 'important',
                      'payment_status': 'paid',
                      'review_status': 'needs_review',
                      'classification_source': 'manual',
                      'handoff_status': 'queued',
                      'assigned_user_id': 9,
                      'assigned_user': {'id': 9, 'name': 'شركة'},
                    },
              },
            ),
          );
        },
      ),
    );
    return (
      repo: ConversationRepository(
        ApiClient(
          secureStore: _MemorySecureStore(),
          prefsStore: prefs,
          dio: dio,
        ),
      ),
      calls: calls,
    );
  }

  test('classification patch uses the mobile contract payload', () async {
    final setup = await repository();
    final model = await setup.repo.updateClassification(
      7,
      customerPriority: 'important',
      paymentStatus: 'paid',
      reviewStatus: 'needs_review',
    );
    expect(setup.calls.single.method, 'PATCH');
    expect(setup.calls.single.path, 'conversations/7/classification');
    expect(setup.calls.single.data, {
      'customer_priority': 'important',
      'payment_status': 'paid',
      'review_status': 'needs_review',
    });
    expect(model.customerPriority, 'important');
    expect(model.classificationSource, 'manual');
  });

  test('handoff request posts optional reason', () async {
    final setup = await repository(
      data: {
        'id': 7,
        'channel': 'whatsapp',
        'status': 'open',
        'handoff_status': 'queued',
        'handoff_reason': 'payment_issue',
      },
    );
    final model = await setup.repo.requestHandoff(7, reason: 'payment_issue');
    expect(setup.calls.single.method, 'POST');
    expect(setup.calls.single.path, 'conversations/7/handoff');
    expect(setup.calls.single.data, {'reason': 'payment_issue'});
    expect(model.handoffStatus, 'queued');
    expect(model.handoffReason, 'payment_issue');
  });

  test('handoff request without reason posts an empty body', () async {
    final setup = await repository(
      data: {
        'id': 7,
        'channel': 'whatsapp',
        'status': 'open',
        'handoff_status': 'queued',
      },
    );
    await setup.repo.requestHandoff(7);
    expect(setup.calls.single.method, 'POST');
    expect(setup.calls.single.path, 'conversations/7/handoff');
    expect(setup.calls.single.data, <String, dynamic>{});
  });

  test('handoff assign posts a company user id', () async {
    final setup = await repository();
    final model = await setup.repo.assignHandoff(7, userId: 9);
    expect(setup.calls.single.method, 'POST');
    expect(setup.calls.single.path, 'conversations/7/assign');
    expect(setup.calls.single.data, {'user_id': 9});
    expect(model.assignedUserId, 9);
    expect(model.assignedUserName, 'شركة');
  });

  test('handoff assign can post a worker engagement id', () async {
    final setup = await repository(
      data: {
        'id': 7,
        'channel': 'whatsapp',
        'status': 'open',
        'handoff_status': 'assigned',
        'assigned_worker_engagement_id': 22,
        'assigned_participant_type': 'independent_worker',
      },
    );
    final model = await setup.repo.assignHandoff(7, workerEngagementId: 22);
    expect(setup.calls.single.method, 'POST');
    expect(setup.calls.single.path, 'conversations/7/assign');
    expect(setup.calls.single.data, {'worker_engagement_id': 22});
    expect(model.assignedWorkerEngagementId, 22);
  });

  test('resolve handoff posts the mobile contract path', () async {
    final setup = await repository(
      data: {
        'id': 7,
        'channel': 'whatsapp',
        'status': 'open',
        'handoff_status': 'resolved',
        'ai_enabled': false,
      },
    );
    final model = await setup.repo.resolveHandoff(7);
    expect(setup.calls.single.method, 'POST');
    expect(setup.calls.single.path, 'conversations/7/resolve-handoff');
    expect(model.handoffStatus, 'resolved');
    expect(model.aiEnabled, isFalse);
  });

  test('resume AI posts the mobile contract path', () async {
    final setup = await repository(
      data: {
        'id': 7,
        'channel': 'whatsapp',
        'status': 'open',
        'handoff_status': 'none',
        'ai_enabled': true,
      },
    );
    final model = await setup.repo.resumeAi(7);
    expect(setup.calls.single.method, 'POST');
    expect(setup.calls.single.path, 'conversations/7/resume-ai');
    expect(model.aiEnabled, isTrue);
  });
}
