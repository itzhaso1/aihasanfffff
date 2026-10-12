import 'package:hasim/core/local_db/domain_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class EmailRepository {
  EmailRepository(
    this._api, {
    DomainLocalStore? local,
    int? Function()? workspaceIdOf,
  }) : _local = local,
       _workspaceIdOf = workspaceIdOf;

  final ApiClient _api;
  final DomainLocalStore? _local;
  final int? Function()? _workspaceIdOf;
  bool lastReadFromCache = false;

  int? get _workspace => _workspaceIdOf?.call();

  Future<List<EmailMessageModel>> cached({
    required String folder,
    String? search,
  }) async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const [];
    return _local.emails(workspaceId, folder: folder, search: search);
  }

  Stream<List<EmailMessageModel>> watch({
    required String folder,
    String? search,
  }) {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const Stream.empty();
    return _local.watchEmails(workspaceId, folder: folder, search: search);
  }

  Future<EmailMessageModel?> cachedMessage(int id) async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return null;
    return _local.email(workspaceId, id);
  }

  Future<({List<EmailMessageModel> items, String? nextCursor})> _list(
    String path,
    String folder, {
    String? cursor,
    String? search,
  }) async {
    final workspaceId = _workspace;
    try {
      final res = await _api.get(
        path,
        query: {
          'cursor': ?cursor,
          if (search != null && search.isNotEmpty) 'search': search,
          'per_page': 20,
        },
      );
      final items = asMapList(
        res.data,
      ).map(EmailMessageModel.fromJson).toList();
      lastReadFromCache = false;
      if (workspaceId != null) {
        await _local?.upsertEmails(workspaceId, folder, items);
      }
      return (items: items, nextCursor: res.nextCursor);
    } on ApiException catch (error) {
      if (error.statusCode != null) rethrow;
      if (workspaceId != null && (cursor == null || cursor.isEmpty)) {
        final saved = await cached(folder: folder, search: search);
        if (saved.isNotEmpty) {
          lastReadFromCache = true;
          return (items: saved, nextCursor: null);
        }
      }
      rethrow;
    } catch (_) {
      if (workspaceId != null && (cursor == null || cursor.isEmpty)) {
        final saved = await cached(folder: folder, search: search);
        if (saved.isNotEmpty) {
          lastReadFromCache = true;
          return (items: saved, nextCursor: null);
        }
      }
      rethrow;
    }
  }

  Future<({List<EmailMessageModel> items, String? nextCursor})> inbox({
    String? cursor,
    String? search,
  }) => _list('/emails/inbox', 'inbox', cursor: cursor, search: search);
  Future<({List<EmailMessageModel> items, String? nextCursor})> sent({
    String? cursor,
    String? search,
  }) => _list('/emails/sent', 'sent', cursor: cursor, search: search);
  Future<({List<EmailMessageModel> items, String? nextCursor})> drafts({
    String? cursor,
    String? search,
  }) => _list('/emails/drafts', 'drafts', cursor: cursor, search: search);

  Future<EmailMessageModel> show(int id) async {
    try {
      final res = await _api.get('/emails/$id');
      final map = asMap(res.data);
      if (map == null) throw ApiException(res.message ?? 'الرسالة غير موجودة.');
      final message = EmailMessageModel.fromJson(map);
      final workspaceId = _workspace;
      if (workspaceId != null) {
        await _local?.upsertEmails(workspaceId, message.type ?? 'inbox', [
          message,
        ]);
      }
      return message;
    } on ApiException catch (error) {
      if (error.statusCode != null) rethrow;
      final cached = await cachedMessage(id);
      if (cached != null) return cached;
      rethrow;
    } catch (_) {
      final cached = await cachedMessage(id);
      if (cached != null) return cached;
      rethrow;
    }
  }

  Future<EmailMessageModel> send({
    required int emailAccountId,
    required String to,
    required String subject,
    required String body,
    int? replyToMessageId,
  }) async {
    final res = await _api.post(
      '/emails',
      idempotent: true,
      body: {
        'email_account_id': emailAccountId,
        'to': to,
        'subject': subject,
        'body': body,
        'reply_to_message_id': ?replyToMessageId,
      },
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر إرسال البريد.');
    return EmailMessageModel.fromJson(map);
  }

  Future<List<EmailAccountModel>> accounts() async {
    final res = await _api.get('/emails/accounts');
    return asMapList(res.data).map(EmailAccountModel.fromJson).toList();
  }

  Future<void> clearLocalWorkspaceData() async {
    final workspaceId = _workspace;
    if (workspaceId == null) return;
    await _local?.clearEmailWorkspaceData(workspaceId);
  }

  Future<void> markRead(int id) async {
    final workspaceId = _workspace;
    final previous = workspaceId == null
        ? null
        : await _local?.email(workspaceId, id);
    if (workspaceId != null) {
      await _local?.setEmailFlags(workspaceId, id, isRead: true);
    }
    try {
      await _api.post('/emails/$id/read');
    } catch (_) {
      if (workspaceId != null && previous != null) {
        await _local?.setEmailFlags(workspaceId, id, isRead: previous.isRead);
      }
      rethrow;
    }
  }

  Future<void> star(int id) async {
    final workspaceId = _workspace;
    final previous = workspaceId == null
        ? null
        : await _local?.email(workspaceId, id);
    final next = !(previous?.isStarred ?? false);
    if (workspaceId != null) {
      await _local?.setEmailFlags(workspaceId, id, isStarred: next);
    }
    try {
      await _api.post('/emails/$id/star');
    } catch (_) {
      if (workspaceId != null && previous != null) {
        await _local?.setEmailFlags(
          workspaceId,
          id,
          isStarred: previous.isStarred,
        );
      }
      rethrow;
    }
  }

  Future<void> saveComposeDraft({
    required String recipients,
    required String subject,
    required String body,
  }) async {
    final workspaceId = _workspace;
    if (workspaceId == null) return;
    await _local?.saveComposeDraft(
      workspaceId: workspaceId,
      recipients: recipients,
      subject: subject,
      body: body,
    );
  }

  Future<({String recipients, String subject, String body})?>
  composeDraft() async {
    final workspaceId = _workspace;
    if (workspaceId == null) return null;
    return _local?.composeDraft(workspaceId);
  }

  Future<void> deleteComposeDraft() async {
    final workspaceId = _workspace;
    if (workspaceId == null) return;
    await _local?.deleteComposeDraft(workspaceId);
  }
}
